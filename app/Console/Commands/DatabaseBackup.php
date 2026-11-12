<?php

namespace App\Console\Commands;

use App\Services\Backup\Runner;
use Illuminate\Console\Command;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:database {--scheduled : Run at most once daily after the configured Asia/Karachi time}';

    protected $description = 'Back up only database.sql as a verified ZIP to Google Drive';

    public function handle(Runner $runner): int
    {
        try {
            $this->info($runner->run((bool) $this->option('scheduled'), true));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup failed at '.$runner->stage.'. Check docs/BACKUPS.md and the private backup log.');

            return self::FAILURE;
        }
    }
}
