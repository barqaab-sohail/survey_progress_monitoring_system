<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reviewed, bounded web cleanup. Files contain IDs/metadata only, never audit payloads. */
class WebCleanup
{
    public function __construct(private Storage $storage, private BackupGuard $backup, private AuditService $audit) {}

    public function preview(User $actor, string $scope): array
    {
        abort_unless(in_array($scope, ['all', 'expired'], true), 422);
        $maximum = (int) DB::table('audit_logs')->max('id');
        $at = now()->toIso8601String();
        $query = $this->candidates($scope, $at)->where('id', '<=', $maximum);
        $total = (clone $query)->count();
        $records = (clone $query)->select('id', 'action', 'auditable_type', 'auditable_id', 'created_at')
            ->orderBy('id')->limit(max(1, min(10000, (int) config('audit.max_records'))))->get()->map(fn ($row) => (array) $row)->all();
        $plan = ['token' => bin2hex(random_bytes(16)), 'user_id' => $actor->id,
            'database_fingerprint' => $this->storage->identity(), 'scope' => $scope,
            'prepared_at' => $at, 'expires_at' => now()->addMinutes(30)->timestamp,
            'snapshot_max_id' => $maximum, 'eligible_total' => $total, 'records' => $records,
            'protected_count' => DB::table('audit_logs')->whereIn('action', $this->protectedActions())->count()];
        $this->directory();
        $this->removeExpiredPlans();
        if (file_put_contents($this->path($plan['token']), json_encode($plan, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw ValidationException::withMessages(['cleanup' => 'Cannot prepare the cleanup preview. Check private storage permissions.']);
        }
        chmod($this->path($plan['token']), 0600);

        return $plan;
    }

    public function load(User $actor, string $token): array
    {
        $path = $this->path($token);
        if (! is_file($path) || is_link($path) || filesize($path) > 8388608) {
            throw ValidationException::withMessages(['cleanup' => 'This preview is unavailable. Preview the logs again.']);
        }
        $plan = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if ($plan['user_id'] !== $actor->id || $plan['database_fingerprint'] !== $this->storage->identity()
            || $plan['expires_at'] <= now()->timestamp) {
            throw ValidationException::withMessages(['cleanup' => 'This preview expired or belongs to another account/database. Preview the logs again.']);
        }

        return $plan;
    }

    public function discard(User $actor, string $token): void
    {
        try {
            $this->load($actor, $token);
            unlink($this->path($token));
        } catch (ValidationException) {
            // An expired preview is already unusable; bounded housekeeping removes it later.
        }
    }

    public function execute(User $actor, string $token): array
    {
        $lock = fopen(storage_path('framework/audit-maintenance.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            throw ValidationException::withMessages(['cleanup' => 'Another audit cleanup is running. Wait a moment, then continue.']);
        }
        try {
            $plan = $this->load($actor, $token);
            if (! $plan['records']) {
                throw ValidationException::withMessages(['cleanup' => 'There are no records in this preview.']);
            }
            // The compact cleanup audit is also the durable progress record. A lost HTTP
            // response/retry cannot double-count a batch or create another summary.
            $event = AuditLog::where('action', 'audit.logs_cleared')->where('auditable_type', User::class)
                ->where('auditable_id', $actor->id)->where('new_values->operation_id', $token)->first();
            $progress = $event?->new_values ?? ['operation_id' => $token, 'scope' => $plan['scope'],
                'planned' => count($plan['records']), 'processed' => 0, 'cleared' => 0, 'skipped' => 0,
                'snapshot_max_id' => $plan['snapshot_max_id'], 'status' => 'running'];
            if (empty($progress['backup_verified_at']) || strtotime($progress['backup_verified_at']) < now()->subMinutes(5)->timestamp) {
                try {
                    $this->backup->verify(max(array_column($plan['records'], 'id')));
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['cleanup' => 'Cleanup needs a verified application backup from the last 48 hours covering these logs. Ask the server administrator to run backup:run and verify recovery, then try again.']);
                }
                $progress['backup_verified_at'] = now()->toIso8601String();
            }

            return DB::transaction(function () use ($actor, $plan, $progress, $event) {
                $batch = array_slice($plan['records'], $progress['processed'], min(100, max(1, (int) config('audit.batch_size'))));
                $query = $this->candidates($plan['scope'], $plan['prepared_at'])->where(function ($query) use ($batch) {
                    foreach ($batch as $record) {
                        $query->orWhere(fn ($q) => $q->where('id', $record['id'])->where('action', $record['action'])
                            ->where('created_at', $record['created_at'])->where('auditable_type', $record['auditable_type'])->where('auditable_id', $record['auditable_id']));
                    }
                });
                // No model observers, DROP, TRUNCATE, or per-row audit events.
                $cleared = $batch ? $query->delete() : 0;
                $progress['processed'] += count($batch);
                $progress['cleared'] += $cleared;
                $progress['skipped'] = $progress['processed'] - $progress['cleared'];
                $progress['status'] = $progress['processed'] >= $progress['planned'] ? 'completed' : 'running';
                if ($event) {
                    [, $details] = (new Payload)->prepare([], $progress, false);
                    DB::table('audit_logs')->where('id', $event->id)->update(['new_values' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                } else {
                    $this->audit->record($actor, 'audit.logs_cleared', $actor, [], $progress, 'Super Admin cleared reviewed audit records after confirming backup recovery.');
                }

                return $progress;
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function protectedActions(): array
    {
        return array_values(array_unique([...config('audit.protected_actions'), 'audit.logs_cleared']));
    }

    private function candidates(string $scope, string $at): Builder
    {
        $query = $scope === 'expired' ? $this->storage->expired($at) : DB::table('audit_logs');

        return $query->whereNotIn('action', $this->protectedActions());
    }

    private function directory(): string
    {
        $directory = storage_path('app/private/audit-maintenance/web');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    private function path(string $token): string
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/D', $token), 422);

        return storage_path('app/private/audit-maintenance/web/webclear-'.$token.'.json');
    }

    private function removeExpiredPlans(): void
    {
        $examined = 0;
        foreach (new \FilesystemIterator($this->directory(), \FilesystemIterator::SKIP_DOTS) as $file) {
            if (++$examined > 100) {
                break;
            }
            if (! $file->isLink() && $file->isFile() && preg_match('/^webclear-[a-f0-9]{32}\.json$/D', $file->getFilename())
                && $file->getMTime() < time() - 3600) {
                unlink($file->getPathname()); // Only expired temporary web previews in this exact directory.
            }
        }
    }
}
