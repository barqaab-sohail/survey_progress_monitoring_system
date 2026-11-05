<?php

namespace App\Services\Mdb;

use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkSection;
use App\Models\Mdb\PdfLine;
use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DriveStorage\FileStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class SourceReplacement
{
    public function replace(SurveyBatch $batch, SourceFile $source, User $actor, UploadedFile $file, int $revision, string $reason): void
    {
        abort_unless($actor->role?->value === 'super_admin' && $actor->isActive(), 403);
        abort_unless($source->batch_id === $batch->id, 404);
        $kind = strtolower($file->getClientOriginalExtension());
        $limit = $source->kind === 'gpx' ? 10 : (int) config('mdb_workflow.max_pdf_mb', 100);
        if ($kind !== $source->kind || ! $file->getSize() || $file->getSize() > $limit * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Choose a replacement '.strtoupper($source->kind).' up to '.$limit.' MB.']);
        }
        // Inspect the temporary upload before taking the batch lock or changing its working source.
        if ($kind === 'pdf') {
            try {
                $process = new Process([config('mdb_workflow.python', 'python'), base_path('scripts/mdb-workflow/inspect_pdf.py'), $file->getRealPath()]);
                $process->setTimeout(150)->mustRun();
                $metadata = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['file' => 'The replacement PDF could not be read. Choose a valid, nonempty PDF without a password.']);
            }
            $points = [];
        } else {
            $parsed = app(GpxParser::class)->parse(file_get_contents($file->getRealPath()));
            $points = $parsed['waypoints'];
            $metadata = ['waypoint_count' => count($points), 'warnings' => $parsed['warnings']];
        }

        $createdPaths = [];
        try {
            DB::transaction(function () use ($batch, $source, $actor, $file, $revision, $reason, $metadata, $points, &$createdPaths): void {
                $lockedBatch = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
                $locked = $lockedBatch->sources()->lockForUpdate()->findOrFail($source->id);
                if ($lockedBatch->revision !== $revision) {
                    throw ValidationException::withMessages(['revision' => 'This batch changed. Reload before replacing its source.']);
                }
                if (! in_array($locked->status, ['ready', 'failed'], true) || $locked->versions()->exists()) {
                    throw ValidationException::withMessages(['file' => 'Wait for source processing to finish and replace the latest version of the file.']);
                }
                if ($locked->kind === 'pdf') {
                    $this->checkPdfPages($lockedBatch, $locked, (int) $metadata['page_count']);
                }

                $before = $locked->only(['kind', 'disk', 'path', 'original_name', 'sha256', 'bytes', 'mime', 'version', 'uploaded_by', 'drive_stored_file_id']);
                $archivePath = 'mdb-workflow/sources/'.$batch->id.'/'.Str::uuid().'.json';
                $oldMetadata = $locked->metadata ?? [];
                $history = $oldMetadata['replacement_history'] ?? [];
                unset($oldMetadata['replacement_history']);
                $archive = ['source' => $before + ['metadata' => $oldMetadata], 'waypoints' => $locked->waypoints()->get()->toArray(),
                    'replaced_by' => $actor->id, 'replaced_at' => now('UTC')->toIso8601String(), 'reason' => $reason];
                $createdPaths[] = $archivePath;
                if (! Storage::disk('local')->put($archivePath, json_encode($archive, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                    throw new \RuntimeException('Could not preserve the previous source version.');
                }

                if ($locked->kind === 'gpx') {
                    $this->replaceWaypoints($lockedBatch, $locked, $points);
                }
                $path = 'mdb-workflow/sources/'.$batch->id.'/'.Str::uuid().'.'.$locked->kind;
                $createdPaths[] = $path;
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    if (! Storage::disk('local')->put($path, $stream, ['visibility' => 'private'])) {
                        throw new \RuntimeException('Could not store the replacement source.');
                    }
                } finally {
                    fclose($stream);
                }
                $history[] = $before + ['archive_path' => $archivePath, 'replaced_by' => $actor->id,
                    'replaced_at' => $archive['replaced_at'], 'reason' => $reason];
                // Keep the source ID stable: saved rows, PDF marks, headers and GPS selections retain their links.
                $locked->update(['disk' => 'local', 'path' => $path, 'drive_stored_file_id' => null,
                    'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'sha256' => hash_file('sha256', $file->getRealPath()), 'bytes' => $file->getSize(),
                    'version' => $locked->version + 1, 'uploaded_by' => $actor->id, 'status' => 'ready',
                    'metadata' => $metadata + ['replacement_history' => $history, 'uploaded_at_utc' => now('UTC')->toIso8601String()]]);
                if (config('drive_storage.enabled')) {
                    $stored = app(FileStorage::class)->register('local', $path, $locked->original_name, $locked->mime,
                        ['batch_id' => $batch->id, 'source_file_id' => $locked->id, 'project_id' => $batch->project_id, 'uploaded_by' => $actor->id]);
                    $locked->update(['drive_stored_file_id' => $stored->id]);
                }
                app(SnapshotService::class)->invalidate($lockedBatch);
                if ($lockedBatch->staged_workflow && ! in_array($lockedBatch->survey_status, ['entry', 'returned'], true)) {
                    $lockedBatch->survey_status = 'returned';
                }
                $lockedBatch->fill(['entry_completed_at' => null, 'survey_verifier_id' => null, 'survey_verified_at' => null]);
                if ($lockedBatch->staged_workflow && $lockedBatch->survey_status === 'returned') {
                    $lockedBatch->survey_remarks = 'A source file was replaced by Super Admin. Review the saved entries and submit the survey again.';
                }
                $lockedBatch->revision++;
                $lockedBatch->save();
                DB::table('mdb_review_checks')->whereIn('transformer_id', $lockedBatch->transformers()->select('id'))->delete();
                foreach ($lockedBatch->transformers()->get() as $transformer) {
                    $header = $transformer->header ?? [];
                    $header['manually_verified'] = false;
                    $transformer->update(['header' => $header]);
                    $transformer->entryRows()->update(['manually_verified' => false]);
                    foreach ($transformer->sections()->get() as $section) {
                        $section->update(['original_entry' => array_replace($section->original_entry ?? [], ['manually_verified' => false]),
                            'length_approved_by' => $locked->kind === 'gpx' ? null : $section->length_approved_by]);
                    }
                }
                app(AuditService::class)->record($actor, 'mdb.source_replaced', $locked, $before,
                    $locked->only(array_keys($before)) + ['batch_id' => $batch->id, 'archive_path' => $archivePath], $reason);
            });
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function checkPdfPages(SurveyBatch $batch, SourceFile $source, int $pageCount): void
    {
        $usedPage = max((int) EntryRow::whereIn('transformer_id', $batch->transformers()->select('id'))->where('source_pdf_id', $source->id)->max('source_page'),
            (int) NetworkSection::whereIn('transformer_id', $batch->transformers()->select('id'))->where('source_pdf_id', $source->id)->max('source_page'),
            (int) PdfRow::where('source_file_id', $source->id)->max('page'), (int) PdfLine::where('source_file_id', $source->id)->max('page'));
        foreach ($batch->transformers()->get() as $transformer) {
            $header = $transformer->header ?? [];
            if (($header['pdf_source_id'] ?? null) == $source->id) {
                $usedPage = max($usedPage, (int) ($header['pdf_start_page'] ?? 0));
            }
            foreach ($header['pages'] ?? [] as $page) {
                if (($page['source_pdf_id'] ?? null) == $source->id) {
                    $usedPage = max($usedPage, (int) ($page['page'] ?? 0));
                }
            }
        }
        if ($pageCount < $usedPage) {
            throw ValidationException::withMessages(['file' => 'The replacement PDF needs at least '.$usedPage.' pages because saved entries or page links use that page. Keep the original page order.']);
        }
    }

    private function replaceWaypoints(SurveyBatch $batch, SourceFile $source, array $points): void
    {
        $existing = $source->waypoints()->get();
        $byIdentity = collect($points)->groupBy(fn ($point) => $this->pointKey($point));
        $byName = collect($points)->groupBy('name');
        $oldByName = $existing->groupBy('name');
        $used = [];
        foreach ($existing as $point) {
            $matches = $byIdentity->get($this->pointKey($point->toArray()), collect());
            if ($matches->isEmpty() && $oldByName[$point->name]->count() === 1 && $byName->get($point->name, collect())->count() === 1) {
                $matches = $byName[$point->name];
            }
            // Do not guess which of several repeated GPS observations a saved identifier meant.
            if ($matches->count() !== 1 || isset($used[$this->pointKey($matches->first())])) {
                throw ValidationException::withMessages(['file' => 'The replacement GPX must retain each existing waypoint unambiguously. Check waypoint '.$point->name.' and its recording time.']);
            }
            $replacement = $matches->first();
            $used[$this->pointKey($replacement)] = true;
            $point->update($replacement + ['correction' => null, 'correction_approved_by' => null, 'correction_approved_at' => null]);
        }
        foreach ($points as $point) {
            if (! isset($used[$this->pointKey($point)])) {
                $source->waypoints()->create($point + ['batch_id' => $batch->id]);
            }
        }
    }

    private function pointKey(array $point): string
    {
        return json_encode([(string) $point['name'], empty($point['recorded_at']) ? null : CarbonImmutable::parse($point['recorded_at'], 'UTC')->utc()->format('Y-m-d H:i:s')], JSON_THROW_ON_ERROR);
    }
}
