<?php

namespace App\Console\Commands;

use App\Services\Backup\Runner;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'backup:verify';

    protected $description = 'Download the latest uploaded encrypted archive and verify its receipt hashes';

    public function handle(Runner $runner): int
    {
        try {
            $receipt = $runner->verifyLatest();
            $this->info('Verified encrypted archive '.$receipt['file_id'].' SHA-256 '.$receipt['sha256']);

            return self::SUCCESS;
        } catch (\Throwable) {
            $runner->alert('manual remote verification');
            $this->error('Remote backup verification failed. Check Drive access, receipt and private workspace.');

            return self::FAILURE;
        }
    }
}
