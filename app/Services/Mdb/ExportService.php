<?php

namespace App\Services\Mdb;

use App\Jobs\Mdb\GenerateMdbExport;
use App\Models\Mdb\ExportJob;
use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExportService
{
    public function __construct(
        private readonly ExportPayloadBuilder $payloads,
        private readonly SnapshotService $snapshots,
        private readonly Exporter $exporter,
        private readonly ReadbackVerifier $readback,
        private readonly WorkflowAccess $access,
        private readonly AuditService $audit,
    ) {}

    public function request(SurveyBatch $batch, User $actor, ?int $transformerId = null): ExportJob
    {
        $this->access->authorize($actor, $batch, 'export');

        return DB::transaction(function () use ($batch, $actor, $transformerId) {
            $locked = SurveyBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $revision = $this->assertApproved($locked);
            $template = Template::findOrFail($revision->snapshot['template']['id'] ?? null);
            $key = hash('sha256', implode('|', [$revision->sha256, $transformerId ?? 'feeder', $template->sha256, ExportPayloadBuilder::VERSION]));
            $existing = ExportJob::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $payload = $this->payloads->build($revision, $template, $transformerId, $key);
            $disk = config('mdb_workflow.disk', 'local');
            $this->assertPrivateDisk($disk);
            $job = ExportJob::create([
                'batch_id' => $locked->id, 'revision_id' => $revision->id, 'transformer_id' => $transformerId,
                'template_id' => $template->id, 'idempotency_key' => $key, 'status' => 'queued',
                'mapping_version' => ExportPayloadBuilder::VERSION, 'template_version' => $template->version,
                'settings' => $payload['settings'], 'requested_by' => $actor->id, 'output_disk' => $disk,
            ]);
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $path = 'mdb-workflow/exports/'.$job->id.'/approved-payload.json';
            if (! Storage::disk($disk)->put($path, $json)) {
                throw new \RuntimeException('Could not retain the approved export payload.');
            }
            $job->update(['payload_path' => $path, 'payload_sha256' => hash('sha256', $json)]);
            $this->audit->record($actor, 'mdb_workflow.export_requested', $job, [], ['revision_id' => $revision->id, 'revision_sha256' => $revision->sha256, 'payload_sha256' => $job->payload_sha256, 'template_version' => $template->version, 'mapping_version' => ExportPayloadBuilder::VERSION]);
            GenerateMdbExport::dispatch($job->id)->onQueue(config('mdb_workflow.queue', 'mdb'))->afterCommit();

            return $job;
        });
    }

    public function retry(ExportJob $export, User $actor): ExportJob
    {
        $this->access->authorize($actor, $export->batch, 'export');

        return DB::transaction(function () use ($export, $actor) {
            $batch = SurveyBatch::whereKey($export->batch_id)->lockForUpdate()->firstOrFail();
            $locked = ExportJob::whereKey($export->id)->lockForUpdate()->firstOrFail();
            $revision = $this->assertApproved($batch);
            $this->require($revision->id === $locked->revision_id && ! $locked->superseded_at, 'Export belongs to a superseded revision. Request export of the current approval.');
            $this->require(in_array($locked->status, ['failed', 'worker_not_configured'], true), 'Only failed exports or exports waiting for a worker can be retried.');
            $old = ['status' => $locked->status];
            $locked->update(['status' => 'queued', 'completed_at' => null, 'log' => ($locked->log ? $locked->log."\n" : '').'Retry requested at '.now('UTC')->toIso8601String()]);
            $this->audit->record($actor, 'mdb_workflow.export_retried', $locked, $old, ['status' => 'queued']);
            GenerateMdbExport::dispatch($locked->id)->onQueue(config('mdb_workflow.queue', 'mdb'))->afterCommit();

            return $locked;
        });
    }

    /** A status lease and queue overlap lock protect duplicate deliveries. Completion rechecks approval. */
    public function run(int $id): void
    {
        $metadata = ExportJob::findOrFail($id);
        try {
            $job = DB::transaction(function () use ($id, $metadata) {
                $batch = SurveyBatch::whereKey($metadata->batch_id)->lockForUpdate()->firstOrFail();
                $job = ExportJob::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($job->superseded_at || $batch->approved_revision_id !== $job->revision_id) {
                    $job->update(['status' => 'superseded', 'superseded_at' => $job->superseded_at ?? now('UTC')]);

                    return null;
                }
                if (in_array($job->status, ['generated', 'worker_not_configured'], true)) {
                    return null;
                }
                if ($job->status === 'running' && $job->started_at?->gt(now('UTC')->subSeconds((int) config('mdb_workflow.export.timeout', 180) + 60))) {
                    return null;
                }
                $this->assertApproved($batch);
                $job->update(['status' => 'running', 'started_at' => now('UTC'), 'attempts' => $job->attempts + 1]);

                return $job;
            });
        } catch (Throwable $exception) {
            ExportJob::whereKey($id)->whereNull('superseded_at')->where('status', '!=', 'generated')->update(['status' => 'failed', 'log' => mb_substr($exception->getMessage(), 0, 60000), 'completed_at' => now('UTC')]);
            $this->audit->record(User::findOrFail($metadata->requested_by), 'mdb_workflow.export_failed', $metadata, [], ['status' => 'failed'], mb_substr($exception->getMessage(), 0, 4000));
            throw $exception;
        }
        if (! $job) {
            return;
        }
        $stage = storage_path('app/private/mdb-worker-staging/'.$id.'/'.Str::uuid());
        File::ensureDirectoryExists($stage);
        try {
            $this->assertPrivateDisk($job->output_disk);
            $json = Storage::disk($job->output_disk)->get($job->payload_path);
            $this->require(hash_equals($job->payload_sha256, hash('sha256', $json)), 'Retained approved export payload SHA-256 differs.');
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $template = $job->template;
            $this->require($template->approved_by && $template->approved_at && $template->active && hash_equals($template->sha256, $payload['template']['sha256']), 'Approved template has changed or been revoked.');
            $output = $stage.'/approved.mdb';
            $result = $this->exporter->export($payload, $template, $output);
            $readback = $this->readback->verify($output, $payload, $result);
            DB::transaction(function () use ($job, $output, $readback, $result) {
                $batch = SurveyBatch::whereKey($job->batch_id)->lockForUpdate()->firstOrFail();
                $locked = ExportJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                if ($locked->superseded_at || $batch->approved_revision_id !== $locked->revision_id) {
                    $locked->update(['status' => 'superseded', 'superseded_at' => now('UTC'), 'log' => 'Approval changed while the worker was running; artifact withheld.']);

                    return;
                }
                $this->assertApproved($batch);
                $path = 'mdb-workflow/exports/'.$locked->id.'/approved.mdb';
                $stream = fopen($output, 'rb');
                try {
                    $this->require(Storage::disk($locked->output_disk)->put($path, $stream), 'MDB artifact could not be retained in private storage.');
                } finally {
                    fclose($stream);
                }
                $locked->update([
                    'status' => 'generated', 'output_path' => $path, 'output_sha256' => hash_file('sha256', $output),
                    'readback' => $readback, 'log' => mb_substr((string) ($result['log'] ?? 'DAO readback verified. SynerGEE engineering acceptance pending.'), 0, 60000),
                    'completed_at' => now('UTC'), 'generated_at' => now('UTC'),
                ]);
                $this->audit->record(User::findOrFail($locked->requested_by), 'mdb_workflow.export_generated', $locked, ['status' => 'running'], ['status' => 'generated', 'output_sha256' => $locked->output_sha256, 'revision_id' => $locked->revision_id, 'synergee_validation' => 'pending']);
            });
        } catch (WorkerNotConfigured $exception) {
            ExportJob::whereKey($id)->whereNull('superseded_at')->update(['status' => 'worker_not_configured', 'log' => $exception->getMessage(), 'completed_at' => now('UTC')]);
            $this->audit->record(User::findOrFail($job->requested_by), 'mdb_workflow.export_waiting_for_worker', $job, [], ['status' => 'worker_not_configured'], $exception->getMessage());
        } catch (Throwable $exception) {
            ExportJob::whereKey($id)->whereNull('superseded_at')->where('status', '!=', 'generated')->update(['status' => 'failed', 'log' => mb_substr($exception->getMessage(), 0, 60000), 'completed_at' => now('UTC')]);
            $this->audit->record(User::findOrFail($job->requested_by), 'mdb_workflow.export_failed', $job, [], ['status' => 'failed'], mb_substr($exception->getMessage(), 0, 4000));
            throw $exception;
        } finally {
            $resolved = realpath($stage);
            $root = realpath(storage_path('app/private/mdb-worker-staging'));
            if ($resolved !== false && $root !== false && str_starts_with(strtolower($resolved), strtolower($root).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }

    private function assertApproved(SurveyBatch $batch): Revision
    {
        $revision = $batch->approvedRevision;
        $this->require($batch->status === 'approved' && $revision !== null && $revision->batch_id === $batch->id, 'Final MDB export requires a current approved immutable revision.');
        $this->require(hash_equals($revision->sha256, $this->snapshots->hash($revision->snapshot)), 'Approved revision SHA-256 differs from its frozen contents.');
        $this->require(hash_equals($revision->sha256, $this->snapshots->hash($this->snapshots->snapshot($batch))), 'Survey data or project settings differ from the approved revision. Reverification is required.');
        foreach ($revision->snapshot['sources'] ?? [] as $source) {
            $disk = Storage::disk($source['disk']);
            $this->require($disk->exists($source['path']), 'An approved original source file is no longer retrievable.');
            $stream = $disk->readStream($source['path']);
            $this->require(is_resource($stream), 'An approved original source file could not be read.');
            try {
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                $actual = hash_final($context);
            } finally {
                fclose($stream);
            }
            $this->require(hash_equals($source['sha256'], $actual), 'Approved original source SHA-256 differs: '.$source['original_name'].'.');
        }

        return $revision;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }

    private function assertPrivateDisk(string $disk): void
    {
        $this->require($disk !== 'public' && config('filesystems.disks.'.$disk.'.visibility', 'private') !== 'public', 'MDB workflow payloads and outputs require a private storage disk.');
    }
}
