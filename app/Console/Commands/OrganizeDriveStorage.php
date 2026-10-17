<?php

namespace App\Console\Commands;

use App\Models\DriveStoredFile;
use App\Services\DriveStorage\DriveClient;
use Illuminate\Console\Command;
use RuntimeException;

class OrganizeDriveStorage extends Command
{
    protected $signature = 'drive-storage:organize {--id= : Organize a specific stored-file row} {--after-id=0 : Resume after this stored-file row} {--limit=100 : Maximum number of uploaded files to inspect}';

    protected $description = 'Move uploaded originals from the storage root into feeder folders, preserving file IDs and contents';

    public function handle(DriveClient $client): int
    {
        $moved = $errors = $skipped = 0;
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $files = DriveStoredFile::where('status', 'uploaded')->where('id', '>', (int) $this->option('after-id'))
            ->when($this->option('id'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->limit($limit)->get();
        foreach ($files as $file) {
            try {
                $client->organize($file) ? $moved++ : $skipped++;
            } catch (\Throwable $exception) {
                $errors++;
                // DriveClient's own RuntimeExceptions contain curated messages, never Google responses.
                $reason = get_class($exception) === RuntimeException::class ? $exception->getMessage()
                    : 'Check the database, account access and storage connectivity.';
                $this->warn('File #'.$file->id.': '.$reason.' Retry with --id='.$file->id.'.');
            }
        }
        $this->info($moved.' file(s) moved; '.$skipped.' already organized or without a feeder; '.$errors.' error(s).');
        if ($files->isNotEmpty()) {
            $this->info('Last inspected stored-file ID: '.$files->last()->id.'. For the next batch use --after-id='.$files->last()->id.'.');
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
