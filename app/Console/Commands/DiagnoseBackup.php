<?php

namespace App\Console\Commands;

use App\Models\DriveStorageConnection;
use App\Services\Backup\Drive;
use App\Services\Backup\Runner;
use App\Services\Backup\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class DiagnoseBackup extends Command
{
    protected $signature = 'backup:doctor {--remote : Check database tables, offline consent and Drive folder access}';

    protected $description = 'Check backup prerequisites without exporting or changing application data';

    public function handle(Runner $runner, Drive $drive): int
    {
        $checks = ['laravel' => app()->version(), 'php' => PHP_VERSION, 'enabled' => config('backups.enabled'),
            'schedule' => config('backups.schedule'), 'timezone' => config('backups.timezone'), 'retention_days' => config('backups.retention_days')];
        $ok = true;
        foreach (['sodium', 'zip', 'pdo_mysql'] as $extension) {
            $checks[$extension] = extension_loaded($extension);
        }
        foreach (['configuration' => fn () => $runner->validate(), 'private_workspace' => function () {
            (new Workspace)->tick();
        },
            'mysqldump' => function () {
                $process = new Process([config('backups.mysqldump'), '--version'], timeout: 15);
                $process->disableOutput()->run();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException;
                }
            }] as $name => $test) {
            try {
                $test();
                $checks[$name] = 'ok';
            } catch (\Throwable) {
                $checks[$name] = 'failed (see docs/BACKUPS.md)';
                $ok = false;
            }
        }
        if ($this->option('remote')) {
            try {
                if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    throw new \RuntimeException;
                }
                $tables = DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [DB::connection()->getDatabaseName(), 'BASE TABLE']);
                if (! $tables || collect($tables)->contains(fn ($t) => strtoupper($t->ENGINE ?? '') !== 'INNODB')) {
                    throw new \RuntimeException;
                }
                if (empty(DriveStorageConnection::find(1)?->token['refresh_token'])) {
                    throw new \RuntimeException;
                }
                $drive->assertFolder(config('backups.folder_id'));
                $checks['database_offline_consent_and_drive_folder'] = 'ok';
            } catch (\Throwable) {
                $checks['database_offline_consent_and_drive_folder'] = 'failed (check InnoDB, application storage consent and folder ID)';
                $ok = false;
            }
        }
        $checks['email_delivery_configured'] = (bool) config('backups.alert_email') && ! in_array(config('mail.default'), ['log', 'array'], true);
        $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
