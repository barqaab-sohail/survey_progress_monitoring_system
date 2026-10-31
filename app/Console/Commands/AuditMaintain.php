<?php

namespace App\Console\Commands;

use App\Services\Audit\BackupGuard;
use App\Services\Audit\Payload;
use App\Services\Audit\Storage;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditMaintain extends Command
{
    protected $signature = 'audit:maintain {operation=prune : prune or compact}
        {--dry-run : Prepare an exact, bounded plan without changing logs (default)}
        {--execute : Execute only the reviewed plan}
        {--plan= : Private plan filename printed by the dry run}
        {--plan-sha256= : SHA256 printed by the dry run}
        {--after-id=0 : Resume the scan after this ID}
        {--limit= : Maximum records examined (default configured 10000)}
        {--seconds= : Cooperative execution budget (default configured 45 seconds, max 120)}
        {--scheduled : Daily opt-in retention; creates a private plan before deletion}';

    protected $description = 'Preview or safely prune expired audits / compact legacy payloads; never changes business records';

    // A legacy value larger than this is SKIPPED, never partially fetched or blindly erased.
    private const LEGACY_READ_BYTES = 2097152;

    public function handle(Storage $storage, BackupGuard $backup): int
    {
        $operation = $this->argument('operation');
        if (! in_array($operation, ['prune', 'compact'], true) || ($this->option('execute') && $this->option('dry-run'))
            || ($this->option('scheduled') && ($operation !== 'prune' || $this->option('execute') || $this->option('dry-run') || $this->option('plan')))) {
            $this->error('Use prune or compact. Do not combine scheduled, execute and dry-run modes.');

            return self::FAILURE;
        }
        if ($this->option('scheduled') && ! config('audit.cleanup_enabled')) {
            $this->info('Audit cleanup disabled. Review a dry run and verify backup/recovery before enabling it.');

            return self::SUCCESS;
        }
        $directory = storage_path('app/private/audit-maintenance');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $lock = fopen(storage_path('framework/audit-maintenance.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            $this->warn('Another audit maintenance command is running.');

            return self::SUCCESS;
        }
        $deadline = microtime(true) + max(1, min(120, (int) ($this->option('seconds') ?? config('audit.max_seconds'))));
        try {
            if ($this->option('execute')) {
                $name = (string) $this->option('plan');
                if (! preg_match('/^(prune|compact)-[a-f0-9]{24}\.json$/D', $name) || is_link($directory.'/'.$name)) {
                    throw new RuntimeException('Supply the exact private plan filename from the dry run.');
                }
                $path = $directory.'/'.$name;
                if (! is_file($path) || filesize($path) > 8388608 || ! hash_equals(hash_file('sha256', $path), (string) $this->option('plan-sha256'))) {
                    throw new RuntimeException('Plan missing or checksum differs. Review a new dry run.');
                }
                $plan = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if ($plan['database_fingerprint'] !== $storage->identity() || $plan['operation'] !== $operation) {
                    throw new RuntimeException('Plan belongs to another database or operation.');
                }
            } else {
                // Leave time for backup verification/deletion; a backlog must not spend every
                // scheduled invocation's entire budget merely preparing the same first window.
                $previewDeadline = $this->option('scheduled') ? min($deadline, microtime(true) + 10) : $deadline;
                $plan = $this->preview($storage, $operation, $previewDeadline);
                $name = $operation.'-'.bin2hex(random_bytes(12)).'.json';
                $path = $directory.'/'.$name;
                if (file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                    throw new RuntimeException('Could not save the private plan.');
                }
                chmod($path, 0600);
                $this->line(json_encode(['plan' => $name, 'path' => $path, 'sha256' => hash_file('sha256', $path),
                    'operation' => $operation, 'as_of' => $plan['as_of'], 'cutoffs' => $plan['cutoffs'],
                    'examined' => $plan['examined'], 'eligible_in_scan' => count($plan['records']),
                    'next_after_id' => $plan['next_after_id'], 'snapshot_max_id' => $plan['snapshot_max_id'],
                    'complete_range' => $plan['complete_range'], 'skipped' => $plan['skipped'],
                    'note' => 'Private plan lists every affected ID, action, date and byte count. Counts cover only this bounded scan.'], JSON_PRETTY_PRINT));
            }
            if (! $this->option('execute') && ! $this->option('scheduled')) {
                return self::SUCCESS;
            }
            if (! $plan['records']) {
                $this->info('No eligible records in this plan.');

                return self::SUCCESS;
            }
            // Enforced in EVERY environment; tests substitute only this boundary.
            $backup->verify(max(array_column($plan['records'], 'id')));
            $affected = 0;
            $lastId = 0;
            $remaining = array_filter($plan['records'], fn ($item) => $item['id'] > (int) $this->option('after-id'));
            $remaining = array_slice($remaining, 0, max(1, min(10000, (int) ($this->option('limit') ?? config('audit.max_records')))));
            foreach (array_chunk($remaining, min(100, max(1, config('audit.batch_size')))) as $batch) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                if ($operation === 'prune') {
                    // Query builder bypasses model events/observers: deletion never audits deletion.
                    // Match each reviewed row's action/date too, then reapply CURRENT retention policy.
                    $query = $storage->expired($plan['as_of'])->where(function ($q) use ($batch) {
                        foreach ($batch as $item) {
                            $q->orWhere(fn ($q) => $q->where('id', $item['id'])->where('action', $item['action'])->where('created_at', $item['created_at']));
                        }
                    });
                    $affected += $query->delete();
                    $lastId = max(array_column($batch, 'id'));
                } else {
                    foreach ($batch as $item) {
                        if (microtime(true) >= $deadline) {
                            break 2;
                        }
                        $result = $this->compact($storage, $item['id'], $item['fingerprint']);
                        if ($result) {
                            $affected += $result['affected'];
                        }
                        $lastId = $item['id'];
                    }
                }
            }
            $this->info('Affected '.$affected.' audit records; processed through ID '.$lastId.'. Resume the same plan with --after-id='.$lastId.'.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Do not expose SQL/Drive exceptions, payloads or OAuth material.
            $this->error($error instanceof RuntimeException && ! $error instanceof QueryException
                ? $error->getMessage() : 'Audit maintenance stopped safely. Check database/backup access and private plan permissions.');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function preview(Storage $storage, string $operation, float $deadline): array
    {
        $now = now()->toImmutable();
        $cursor = max(0, (int) $this->option('after-id'));
        $maximum = (int) DB::table('audit_logs')->max('id');
        $limit = min(10000, max(1, (int) ($this->option('limit') ?? config('audit.max_records'))));
        $plan = ['version' => 1, 'database_fingerprint' => $storage->identity(), 'operation' => $operation,
            'as_of' => $now->toIso8601String(), 'cutoffs' => [
                'routine_before' => $now->subDays(max(1, (int) config('audit.routine_days')))->toIso8601String(),
                'important_before' => $now->subDays(max(1, (int) config('audit.important_days')))->toIso8601String()],
            'snapshot_max_id' => $maximum, 'examined' => 0, 'records' => [], 'skipped' => []];
        $query = $operation === 'prune' ? $storage->expired($plan['as_of']) : DB::table('audit_logs')->whereNotIn('action', config('audit.protected_actions'));
        while ($plan['examined'] < $limit && microtime(true) < $deadline) {
            $rows = $storage->sizes((clone $query)->select('id', 'action', 'created_at')->where('id', '>', $cursor)->where('id', '<=', $maximum)->orderBy('id')
                ->limit(min(25, $limit - $plan['examined'])))->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                $cursor = (int) $row->id;
                $plan['examined']++;
                $detail = (array) $row;
                if ($operation === 'compact') {
                    if (array_sum(array_map(fn ($c) => (int) $row->{$c.'_bytes'}, Storage::COLUMNS)) > self::LEGACY_READ_BYTES) {
                        $plan['skipped'][] = ['id' => $row->id, 'reason' => 'Over 2 MiB safe read limit; requires separate review, retained unchanged.'];

                        continue;
                    }
                    $result = $this->compact($storage, $row->id);
                    if (! $result) {
                        continue;
                    }
                    if (isset($result['skipped'])) {
                        $plan['skipped'][] = ['id' => $row->id, 'reason' => $result['skipped']];

                        continue;
                    }
                    $detail += $result;
                }
                $plan['records'][] = $detail;
            }
        }
        $plan['next_after_id'] = $cursor;
        $plan['complete_range'] = ! (clone $query)->where('id', '>', $cursor)->where('id', '<=', $maximum)->exists();

        return $plan;
    }

    private function compact(Storage $storage, int $id, ?string $expected = null): ?array
    {
        $length = implode(' + ', array_map(fn ($c) => $storage->length($c), Storage::COLUMNS));
        $row = DB::table('audit_logs')->select(['id', 'action', 'old_values', 'new_values', 'reason', 'user_agent', 'updated_at'])
            ->where('id', $id)->whereNotIn('action', config('audit.protected_actions'))->whereRaw('('.$length.') <= ?', [self::LEGACY_READ_BYTES])->first();
        if (! $row) {
            return null;
        }
        $fingerprint = hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR));
        if ($expected !== null && ! hash_equals($expected, $fingerprint)) {
            return null; // Already compacted, or changed since preview. Never overwrite it.
        }
        $policy = new Payload;
        $old = json_decode($row->old_values ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $new = json_decode($row->new_values ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
        if (! is_array($old) || ! is_array($new)) {
            return ['skipped' => 'Non-object legacy JSON; retained for separate review.'];
        }
        [$old, $new] = $policy->prepare($old, $new);
        $values = ['old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => $new ? json_encode($new, JSON_THROW_ON_ERROR) : null,
            'reason' => $policy->text($row->reason), 'user_agent' => $policy->text($row->user_agent, 512)];
        $before = $after = 0;
        $changed = false;
        foreach ($values as $column => $value) {
            $before += strlen($row->$column ?? '');
            $after += strlen($value ?? '');
            $changed = $changed || ($value !== $row->$column);
        }
        if (! $changed) {
            return null;
        }
        if ($expected !== null) {
            // Atomic comparison against all original values, without changing actor/action/time/record.
            $query = DB::table('audit_logs')->where('id', $id)->where('updated_at', $row->updated_at);
            foreach (array_keys($values) as $column) {
                $query->where($column, $row->$column);
            }

            return ['affected' => $query->update($values)];
        }

        return ['fingerprint' => $fingerprint, 'before_bytes' => $before, 'after_bytes' => $after];
    }
}
