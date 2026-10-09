<?php

namespace App\Jobs\Mdb;

use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Waypoint;
use App\Services\Mdb\GpxParser;
use App\Services\Mdb\SnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ProcessSourceFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('mdb-sources');
    }

    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mdb-source:'.$this->sourceId))->releaseAfter(10)->expireAfter(240)];
    }

    public function handle(GpxParser $parser, SnapshotService $snapshots): void
    {
        $source = SourceFile::findOrFail($this->sourceId);
        if ($source->status === 'ready') {
            return;
        }
        $path = Storage::disk($source->disk)->path($source->path);
        if (! is_file($path) || ! hash_equals($source->sha256, hash_file('sha256', $path))) {
            throw new \RuntimeException('Source file hash verification failed.');
        }
        $result = [];
        if ($source->kind === 'gpx') {
            $result = $parser->parse(file_get_contents($path));
            $metadata = ['waypoint_count' => count($result['waypoints']), 'warnings' => $result['warnings']];
        } else {
            $process = new Process([config('mdb_workflow.python', 'python'), base_path('scripts/mdb-workflow/inspect_pdf.py'), $path]);
            $process->setTimeout(150)->mustRun();
            $metadata = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        }
        DB::transaction(function () use ($source, $result, $metadata, $snapshots): void {
            $batch = SurveyBatch::lockForUpdate()->findOrFail($source->batch_id);
            $locked = SourceFile::lockForUpdate()->findOrFail($source->id);
            if ($locked->status === 'ready') {
                return;
            }
            if ($source->kind === 'gpx') {
                // Retry never duplicates points; transaction rolls all inserts back on failure.
                foreach ($result['waypoints'] as $point) {
                    Waypoint::create($point + ['batch_id' => $batch->id, 'source_file_id' => $source->id]);
                }
            }
            $processedMetadata = array_merge($locked->metadata ?? [], $metadata);
            unset($processedMetadata['error']);
            $locked->update(['status' => 'ready', 'metadata' => $processedMetadata]);
            $snapshots->invalidate($batch);
            $batch->increment('revision');
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $source = SourceFile::find($this->sourceId);
        if (! $source || $source->status === 'ready') {
            return;
        }
        DB::transaction(function () use ($source): void {
            $batch = SurveyBatch::lockForUpdate()->findOrFail($source->batch_id);
            $locked = SourceFile::lockForUpdate()->findOrFail($source->id);
            if ($locked->status === 'ready') {
                return;
            }
            $locked->update(['status' => 'failed', 'metadata' => array_merge($locked->metadata ?? [], ['error' => 'Source processing failed. Check worker logs and retry.'])]);
            app(SnapshotService::class)->invalidate($batch);
            $batch->increment('revision');
        });
    }
}
