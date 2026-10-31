<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;

class Runner
{
    public string $stage = 'configuration';

    public function __construct(private Drive $drive, private Archive $archive) {}

    public function run(bool $scheduled = false): string
    {
        if ($scheduled && ! config('backups.enabled')) {
            return 'Scheduled backups are disabled.';
        }
        $mask = umask(0077);
        $lock = null;
        $directory = null;
        try {
            $workspace = new Workspace;
            $lock = $workspace->lock();
            if (! $lock) {
                return 'Another backup is running.';
            }
            $state = $workspace->read('state');
            $now = now(config('backups.timezone'));
            $date = $now->toDateString();
            // Cron can poll in the server timezone; only one scheduled attempt per Pakistan date.
            if ($scheduled && ($now->hour < 2 || ($state['last_zip_attempt_date'] ?? null) === $date)) {
                return 'No backup is due.';
            }
            if ($scheduled) {
                $state['last_zip_attempt_date'] = $date;
                $workspace->save('state', $state); // Leave old encrypted-backup metadata untouched.
            }
            foreach (['zip', 'pdo_mysql'] as $extension) {
                if (! extension_loaded($extension)) {
                    throw new Failure('Required PHP extension missing: '.$extension.'.');
                }
            }
            if (! function_exists('proc_open') || ! config('backups.folder_id')) {
                throw new Failure('Configure BACKUP_DRIVE_FOLDER_ID and enable proc_open for mysqldump.');
            }
            $this->drive->useWorkspace($workspace);
            $this->stage = 'Drive folder';
            $this->drive->assertFolder(config('backups.folder_id'));
            $directory = $workspace->createRun();
            $name = 'backup-'.$now->format('Y-m-d-Hi').'.zip';
            $this->stage = 'database and ZIP';
            // Conservative boundary captured BEFORE mysqldump's consistent snapshot.
            $auditThroughId = (int) \Illuminate\Support\Facades\DB::table('audit_logs')->max('id');
            $databaseFingerprint = (new \App\Services\Audit\Storage)->identity();
            $path = $this->archive->create($name, $workspace, $directory);
            $receipt = ['file_id' => $this->drive->retry(fn () => $this->drive->generateId()),
                'name' => $name, 'folder_id' => config('backups.folder_id'), 'bytes' => filesize($path), 'md5' => md5_file($path)];
            $this->stage = 'upload';
            $this->drive->uploadArchive($receipt, $path, $workspace);
            $this->stage = 'upload verification';
            $this->drive->assertMetadata($receipt, $this->drive->metadata($receipt['file_id']) ?? []);
            $state['latest_zip'] = $receipt + ['uploaded_at' => now()->toIso8601String(),
                'database_fingerprint' => $databaseFingerprint, 'audit_through_id' => $auditThroughId];
            $workspace->save('state', $state);
            $this->stage = 'local cleanup';
            $workspace->cleanRun($directory); // Only this run, and only after confirmed size/MD5.
            Log::info('Backup successful.', ['archive' => $name, 'drive_file_id' => $receipt['file_id']]);

            return 'Backup successful: '.$name;
        } catch (\Throwable $error) {
            // Do not log third-party exceptions: they may contain SQL, OAuth tokens or credentials.
            Log::error('Backup failed.', ['stage' => $this->stage,
                'reason' => $error instanceof Failure ? $error->getMessage() : 'Check database access, Drive connection, permissions and hosting limits.',
                'local_files_retained' => $directory !== null]);
            throw new Failure('Backup failed at '.$this->stage.'. See the application log; any staged SQL/ZIP has been retained privately.');
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            umask($mask);
        }
    }
}
