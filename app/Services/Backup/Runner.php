<?php

namespace App\Services\Backup;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class Runner
{
    public string $stage = 'configuration';

    public function __construct(private Drive $drive, private Archive $archive) {}

    public function scheduledSlot(): CarbonImmutable
    {
        return CarbonImmutable::instance((new CronExpression(config('backups.schedule')))
            ->getPreviousRunDate(now()->toDateTimeImmutable(), 0, true, config('backups.timezone')));
    }

    public function due(array $state, CarbonImmutable $slot): bool
    {
        return ($state['last_slot'] ?? '') !== $slot->toIso8601String()
            && (empty($state['last_attempt']) || CarbonImmutable::parse($state['last_attempt'])->addMinutes(max(1, config('backups.retry_minutes')))->isPast());
    }

    public function run(bool $scheduled = false): string
    {
        if ($scheduled && ! config('backups.enabled')) {
            return 'Scheduled backups are disabled.';
        }
        $mask = umask(0077);
        $workspace = null;
        $lock = null;
        $state = [];
        $stateLoaded = false;
        $active = false;
        try {
            $workspace = new Workspace;
            $this->drive->useWorkspace($workspace);
            $lock = $workspace->lock();
            if (! $lock) {
                return 'Another backup operation is running.';
            }
            $state = $workspace->read('state');
            $stateLoaded = true;
            $slot = $this->scheduledSlot();
            if ($scheduled && ! $this->due($state, $slot)) {
                return 'No backup is due.';
            }
            if ($state['running'] ?? false) {
                $this->event('interrupted', ['stage' => $state['stage'] ?? 'unknown']);
                $this->alert('previous process was interrupted');
            }
            $state['last_attempt'] = now()->toIso8601String();
            $state['running'] = true;
            $state['stage'] = 'snapshot';
            $workspace->save('state', $state);
            $active = true;
            register_shutdown_function(function () use ($workspace, &$active) {
                if ($active) {
                    try {
                        $workspace->cleanPlaintext();
                    } catch (\Throwable) { /* Next locked run retries cleanup. */
                    }
                }
            });
            $this->validate();
            $workspace->cleanPlaintext(); // Remove plaintext from any externally killed previous process.
            $this->stage = 'drive_folder';
            $this->drive->assertFolder(config('backups.folder_id'));
            $pending = $workspace->read('pending');
            if ($pending && is_file($workspace->path.'/pending.enc')) {
                $this->finish($pending, $workspace, $state, $slot);
                // An old pending snapshot must not satisfy a more recent scheduled recovery point.
                if (CarbonImmutable::parse($pending['created_at'])->greaterThanOrEqualTo($slot)) {
                    return 'Pending backup uploaded and verified.';
                }
            } elseif (is_file($workspace->path.'/pending.enc')) {
                throw new Failure('An encrypted pending archive has no receipt; preserve it for manual recovery.');
            }
            if (is_file($workspace->path.'/pending.enc.part')) {
                unlink($workspace->path.'/pending.enc.part');
            }
            $this->stage = 'snapshot';
            $state['running'] = true;
            $state['stage'] = $this->stage;
            $workspace->save('state', $state);
            $id = now()->utc()->format('Ymd\THis\Z').'-'.Str::uuid();
            $this->event('started', ['backup_id' => $id]);
            $snapshotAt = now()->toIso8601String();
            $zip = $this->archive->create($id, $workspace);
            $this->stage = 'encryption';
            $pending = ['id' => $id, 'created_at' => $snapshotAt, 'name' => config('backups.set').'-'.$id.'.zip.backup.enc',
                'set' => config('backups.set'), 'folder_id' => config('backups.folder_id'),
                'file_id' => $this->drive->retry(fn () => $this->drive->generateId())];
            // Receipt first, atomic rename second: a killed process can resume a complete encrypted snapshot.
            $workspace->save('pending', $pending);
            Cipher::encrypt($zip, $workspace->path.'/pending.enc.part', Cipher::publicKey(config('backups.public_key')), fn () => $workspace->tick());
            if (! rename($workspace->path.'/pending.enc.part', $workspace->path.'/pending.enc')) {
                throw new Failure('Encrypted backup could not be committed.');
            }
            $workspace->cleanPlaintext();
            $this->finish($pending, $workspace, $state, $slot);

            return 'Backup uploaded, read back, verified and retained successfully.';
        } catch (\Throwable $e) {
            if ($workspace && $lock && $stateLoaded) {
                $state['running'] = false;
                $state['failure_stage'] = $this->stage;
                try {
                    $workspace->save('state', $state);
                } catch (\Throwable) { /* Preserve original failure. */
                }
            }
            $this->event('failed', ['stage' => $this->stage, 'exception_type' => $e::class,
                'reason' => $e instanceof Failure ? $e->getMessage() : 'Unexpected error; inspect the stage prerequisites in docs/BACKUPS.md.']);
            $this->alert($this->stage);
            throw new Failure('Backup failed at '.$this->stage.'. Check private backup logs, credentials, quota and docs/BACKUPS.md.');
        } finally {
            if ($workspace && $lock) {
                try {
                    $workspace->cleanPlaintext();
                } catch (\Throwable) {
                    $this->event('plaintext_cleanup_failed');
                    $this->alert('private plaintext cleanup');
                }
                $active = false;
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            umask($mask);
        }
    }

    private function finish(array $receipt, Workspace $workspace, array &$state, CarbonImmutable $slot): void
    {
        $path = $workspace->path.'/pending.enc';
        $receipt += ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'md5' => md5_file($path)];
        if (filesize($path) !== $receipt['bytes'] || ! hash_equals($receipt['sha256'], hash_file('sha256', $path))) {
            throw new Failure('The retained encrypted backup changed. Preserve it for investigation.');
        }
        $workspace->save('pending', $receipt);
        $this->stage = 'upload';
        $state['stage'] = $this->stage;
        $workspace->save('state', $state);
        $this->drive->uploadArchive($receipt, $path, $workspace);
        $this->stage = 'remote_verification';
        $this->drive->verifyArchive($receipt, $workspace);
        $this->drive->markVerified($receipt);
        $receipt['verified_at'] = now()->toIso8601String();
        $workspace->save('receipt-'.$receipt['id'], $receipt);
        $state['latest'] = $receipt;
        $state['running'] = false;
        $state['failure_stage'] = null;
        if (CarbonImmutable::parse($receipt['created_at'])->greaterThanOrEqualTo($slot)) {
            $state['last_slot'] = $slot->toIso8601String();
        }
        $workspace->save('state', $state); // Success receipt is durable before any cleanup or pruning.
        if (! unlink($path) || ! unlink($workspace->path.'/pending.json')) {
            throw new Failure('Verified backup is awaiting local cleanup.');
        }
        $this->event('verified', ['backup_id' => $receipt['id'], 'drive_file_id' => $receipt['file_id'], 'bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]);
        $this->stage = 'retention';
        $deleted = $this->drive->prune($receipt, $workspace);
        $this->event('retention_complete', ['deleted' => $deleted]);
    }

    public function validate(): void
    {
        foreach (['sodium', 'zip', 'pdo_mysql'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new Failure('Backup requires PHP extension '.$extension.'.');
            }
        }
        if (! function_exists('proc_open')) {
            throw new Failure('Backup requires proc_open for mysqldump.');
        }
        Cipher::publicKey((string) config('backups.public_key'));
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', config('backups.set'))) {
            throw new Failure('BACKUP_SET must be a stable identifier.');
        }
        if (! config('backups.folder_id') || config('backups.retention_days') < 1 || config('backups.timeout') < 60
            || config('backups.max_workspace_bytes') < 1048576 || config('backups.min_free_bytes') < 0) {
            throw new Failure('Invalid backup folder, retention or resource limits.');
        }
        new \DateTimeZone(config('backups.timezone'));
        new CronExpression(config('backups.schedule'));
    }

    public function health(bool $alert = false): array
    {
        $workspace = new Workspace;
        $state = $workspace->read('state');
        $created = $state['latest']['created_at'] ?? null;
        $healthy = $created && CarbonImmutable::parse($created)->greaterThan(now()->subHours(config('backups.max_age_hours')));
        $interrupted = ($state['running'] ?? false) && ! empty($state['last_attempt'])
            && CarbonImmutable::parse($state['last_attempt'])->addSeconds(config('backups.timeout') + 300)->isPast();
        if ($alert && (! $healthy || $interrupted)) {
            $last = $workspace->read('health-alert')['at'] ?? null;
            if (! $last || CarbonImmutable::parse($last)->addHours(6)->isPast()) {
                $this->alert($interrupted ? 'backup process exceeded its time budget' : 'no recent verified backup');
                $workspace->save('health-alert', ['at' => now()->toIso8601String()]);
            }
        }

        return ['healthy' => (bool) $healthy && ! $interrupted, 'enabled' => config('backups.enabled'),
            'snapshot_at' => $created, 'verified_at' => $state['latest']['verified_at'] ?? null,
            'drive_file_id' => $state['latest']['file_id'] ?? null, 'sha256' => $state['latest']['sha256'] ?? null,
            'failure_stage' => $state['failure_stage'] ?? null, 'interrupted' => $interrupted];
    }

    public function verifyLatest(): array
    {
        $workspace = new Workspace;
        $lock = $workspace->lock();
        if (! $lock) {
            throw new Failure('Another backup operation is running.');
        }
        try {
            $this->drive->useWorkspace($workspace);
            $receipt = $workspace->read('state')['latest'] ?? null;
            if (! $receipt) {
                throw new Failure('No verified backup receipt is available.');
            }
            $this->drive->verifyArchive($receipt, $workspace);

            return $receipt;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function alert(string $stage): void
    {
        try {
            User::where('role', UserRole::SuperAdmin->value)->where('status', 'active')->each(function ($user) use ($stage) {
                $user->notify(new BackupFailedNotification($stage));
            });
        } catch (\Throwable) {
            $this->event('database_alert_failed');
        }
        if (config('backups.alert_email')) {
            try {
                if (in_array(config('mail.default'), ['log', 'array'], true)) {
                    throw new Failure('A delivery mailer is required.');
                }
                Notification::route('mail', config('backups.alert_email'))->notify(new BackupFailedNotification($stage));
            } catch (\Throwable) {
                $this->event('email_alert_failed');
            }
        }
    }

    private function event(string $event, array $context = []): void
    {
        // Only IDs, hashes, stages, counts and curated Failure messages. Log failure must not prevent alerts.
        try {
            Log::channel('backups')->log(str_contains($event, 'failed') || $event === 'interrupted' ? 'error' : 'info', 'backup.'.$event, $context);
        } catch (\Throwable) {
            try {
                error_log('backup.'.$event.' '.json_encode($context));
            } catch (\Throwable) { /* Hosting may have exhausted both disk and logging quota. */
            }
        }
    }
}
