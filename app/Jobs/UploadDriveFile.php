<?php

namespace App\Jobs;

use App\Services\DriveStorage\FileStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class UploadDriveFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public int $storedFileId)
    {
        $this->onConnection(config('drive_storage.connection', 'drive-storage'));
        $this->onQueue(config('drive_storage.queue', 'drive-storage'));
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('drive-transfer:'.$this->storedFileId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(FileStorage $storage): void
    {
        $storage->transfer($this->storedFileId);
    }
}
