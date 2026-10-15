<?php

namespace App\Console\Commands;

use App\Jobs\Mdb\ProcessSourceFile;
use App\Jobs\UploadDriveFile;
use App\Models\DriveStoredFile;
use App\Models\Mdb\SourceFile;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RetryDriveStorage extends Command
{
    protected $signature = 'drive-storage:retry {--id= : Retry a specific stored-file row} {--force : Ignore the retry delay}';

    protected $description = 'Recover pending Drive transfers and remove confirmed local staging copies';

    public function handle(FileStorage $storage): int
    {
        $errors = 0;
        // Only disposable work snapshots; pending originals are never subject to age cleanup.
        $workDisk = Storage::disk('local');
        foreach ($workDisk->files('drive-storage/work') as $temporary) {
            if (str_starts_with(basename($temporary), 'source-') && $workDisk->lastModified($temporary) < now()->subDay()->timestamp) {
                $workDisk->delete($temporary);
            }
        }
        DriveStoredFile::where('status', 'uploaded')->whereNull('local_deleted_at')->limit(200)->get()->each(function ($file) use ($storage, &$errors) {
            try {
                $storage->cleanup($file);
            } catch (\Throwable) {
                $errors++;
                $this->warn('Local cleanup pending for file #'.$file->id);
            }
        });
        if (! config('drive_storage.enabled')) {
            $this->info('Transfers disabled; staged originals are retained.');

            return self::SUCCESS;
        }
        $files = DriveStoredFile::whereIn('status', ['pending', 'failed'])->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('force'), fn ($q) => $q->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->orderBy('id')->limit(200)->get();
        foreach ($files as $file) {
            try {
                $file->update(['next_attempt_at' => now()->addMinutes(10)]);
                UploadDriveFile::dispatch($file->id);
            } catch (\Throwable) {
                $errors++;
                $this->warn('Queue unavailable; file #'.$file->id.' retained for the next recovery run.');
            }
        }
        // Recover source inspection dispatches lost during a queue outage after the upload committed.
        SourceFile::where('status', 'queued')->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get()->each(function ($source) use (&$errors) {
            try {
                ProcessSourceFile::dispatch($source->id);
            } catch (\Throwable) {
                $errors++;
            }
        });
        $this->info($files->count().' transfer(s) queued; '.$errors.' recovery error(s).');

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
