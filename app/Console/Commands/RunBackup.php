<?php

namespace App\Console\Commands;

use App\Services\Backup\Runner;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--scheduled : Evaluate the configured schedule and retry delay}';

    protected $description = 'Create, encrypt, upload and verify a complete Google Drive recovery backup';

    public function handle(Runner $runner): int
    {
        try {
            if ($this->option('scheduled') && config('backups.enabled')) {
                $runner->health(true);
            }
            $this->info($runner->run((bool) $this->option('scheduled')));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup failed at '.$runner->stage.'. Check docs/BACKUPS.md and the private backup log.');

            return self::FAILURE;
        }
    }
}
