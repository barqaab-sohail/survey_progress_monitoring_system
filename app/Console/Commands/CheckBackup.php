<?php

namespace App\Console\Commands;

use App\Services\Backup\Runner;
use Illuminate\Console\Command;

class CheckBackup extends Command
{
    protected $signature = 'backup:check {--alert : Notify administrators if stale or interrupted} {--test-alert : Send a test failure notification}';

    protected $description = 'Show the last verified recovery point and check backup freshness';

    public function handle(Runner $runner): int
    {
        try {
            if ($this->option('test-alert')) {
                $runner->alert('notification delivery test');
            }
            $health = $runner->health((bool) $this->option('alert'));
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $health['healthy'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Backup health check failed; check private workspace permissions and configuration.');

            return self::FAILURE;
        }
    }
}
