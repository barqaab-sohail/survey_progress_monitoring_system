<?php

namespace App\Console\Commands;

use App\Services\Backup\Drive;
use Illuminate\Console\Command;

class CreateBackupFolder extends Command
{
    protected $signature = 'backup:folder';

    protected $description = 'Create a dedicated private Drive backup folder using the application storage connection';

    public function handle(Drive $drive): int
    {
        try {
            $this->info('Set BACKUP_DRIVE_FOLDER_ID='.$drive->createBackupFolder());

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Folder creation failed. Connect the application storage account and check Google OAuth settings.');

            return self::FAILURE;
        }
    }
}
