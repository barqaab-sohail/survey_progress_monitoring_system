<?php

namespace App\Console\Commands;

use App\Services\Backup\Runner;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--scheduled : Run at most once daily after 02:00 Asia/Karachi}';

    protected $description = 'Back up application code and the database as a normal ZIP to Google Drive';

    public function handle(Runner $runner): int
    {
        try {
            $this->info($runner->run((bool) $this->option('scheduled')));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup failed at '.$runner->stage.'. Check docs/BACKUPS.md and the private backup log.');

            return self::FAILURE;
        }
    }
}
