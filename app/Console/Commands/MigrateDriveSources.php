<?php

namespace App\Console\Commands;

use App\Models\Mdb\SourceFile;
use App\Models\TransformerMdbProject;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateDriveSources extends Command
{
    protected $signature = 'drive-storage:migrate-existing {--limit=100} {--include-workspaces}';

    protected $description = 'Queue existing private PDF/GPX originals for verified Drive transfer without changing their URLs';

    public function handle(FileStorage $storage): int
    {
        if (! config('drive_storage.enabled')) {
            $this->error('Enable and connect application Drive storage first.');

            return self::FAILURE;
        }
        $errors = 0;
        $limit = max(1, min(1000, (int) $this->option('limit')));
        foreach (SourceFile::whereNull('drive_stored_file_id')->orderBy('id')->limit($limit)->get() as $source) {
            try {
                DB::transaction(function () use ($source, $storage) {
                    $source = SourceFile::lockForUpdate()->findOrFail($source->id);
                    if ($source->drive_stored_file_id) {
                        return;
                    }
                    $path = Storage::disk($source->disk)->path($source->path);
                    if (! is_file($path) || ! hash_equals($source->sha256, hash_file('sha256', $path))) {
                        throw new \RuntimeException('Integrity verification failed.');
                    }
                    $batch = $source->batch;
                    $file = $storage->register($source->disk, $source->path, $source->original_name, $source->mime,
                        ['project_id' => $batch->project_id, 'feeder_id' => $batch->feeder_id, 'batch_id' => $batch->id, 'source_file_id' => $source->id]);
                    $source->update(['drive_stored_file_id' => $file->id]);
                });
            } catch (\Throwable) {
                $errors++;
                $this->warn('Source #'.$source->id.' was left unchanged; check its local original.');
            }
        }
        if ($this->option('include-workspaces')) {
            foreach (TransformerMdbProject::where(fn ($q) => $q->where(fn ($q) => $q->whereNull('pdf_drive_stored_file_id')->whereNotNull('pdf_path'))->orWhere(fn ($q) => $q->whereNull('gpx_drive_stored_file_id')->whereNotNull('gpx_path')))->orderBy('id')->limit($limit)->get() as $project) {
                try {
                    DB::transaction(function () use ($project, $storage) {
                        $project = TransformerMdbProject::lockForUpdate()->findOrFail($project->id);
                        foreach (['pdf', 'gpx'] as $kind) {
                            if (! $project->{$kind.'_path'} || $project->{$kind.'_drive_stored_file_id'}) {
                                continue;
                            }
                            $file = $storage->register('local', $project->{$kind.'_path'}, 'survey.'.$kind, $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml',
                                ['project_id' => $project->feeder->project_id, 'feeder_id' => $project->feeder_id, 'transformer_workspace_id' => $project->id, 'transformer_code' => $project->transformer_code]);
                            $project->update([$kind.'_drive_stored_file_id' => $file->id]);
                        }
                    });
                } catch (\Throwable) {
                    $errors++;
                    $this->warn('Workspace #'.$project->id.' requires administrator inspection.');
                }
            }
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
