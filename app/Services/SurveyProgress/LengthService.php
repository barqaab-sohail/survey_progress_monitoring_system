<?php

namespace App\Services\SurveyProgress;

use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use App\Models\SurveyProgress\Transcription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class LengthService
{
    public function __construct(private DriveClient $drive, private SurveyInventory $inventory, private SourceParser $parser, private SpanCalculator $calculator) {}

    public function calculate(Run $run): void
    {
        $lock = Cache::lock('survey-progress:work', 7200);
        if (! $lock->get()) {
            $run->update(['status' => 'skipped', 'finished_at' => now(), 'error' => 'Another survey progress task is running. Retry after it finishes.']);

            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        $state = null;
        try {
            $state = ProgressFeeder::findOrFail($run->progress_feeder_id);
            if (! $state->folder_id || ! $state->synced_at || in_array($state->sync_status, ['mapping_required', 'failed'], true)) {
                throw new RuntimeException('Synchronize this feeder and resolve its folder mapping first.');
            }
            $files = $this->drive->inventory($state->folder_id);
            $hash = $this->inventory->hash($files);
            if ($hash !== $state->manifest_hash) {
                $state->update(['length_status' => 'stale']);
                throw new RuntimeException('Drive files changed. Synchronize before calculating LT length.');
            }
            if (array_sum(array_map(fn ($f) => (int) ($f['size'] ?? 0), $files)) > 500 * 1024 * 1024) {
                throw new RuntimeException('Feeder source files exceed the 500 MB calculation limit.');
            }
            $analysis = $this->inventory->analyze($files);
            $issues = $analysis['issues'];
            $historical = [];
            $surveys = [];
            $transcriptions = Transcription::where('progress_feeder_id', $state->id)->latest('id')->get()->unique('survey_key')->keyBy('survey_key');
            $transcriptionIds = $transcriptions->pluck('id')->sort()->values()->all();
            $disk = Storage::disk('local');
            $directory = 'survey-progress/runs/'.$run->id;
            $disk->makeDirectory($directory);
            $paths = [];
            $evidence = [];
            foreach ($files as $file) {
                $path = $disk->path($directory.'/'.$file['id'].'.'.strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)));
                try {
                    $this->drive->download($file, $path);
                    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf' && file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                        throw new RuntimeException('Source is not a valid PDF.');
                    }
                    $paths[$file['id']] = $path;
                    $evidence[] = ['drive_id' => $file['id'], 'name' => $file['name'], 'sha256' => hash_file('sha256', $path), 'path' => $directory.'/'.basename($path)];
                } catch (Throwable $e) {
                    $issues[] = ['code' => 'download_failed', 'file' => $file['name'], 'message' => mb_substr($e->getMessage(), 0, 500)];
                }
            }
            foreach ($analysis['kmz'] as $file) {
                if (! isset($paths[$file['id']])) {
                    continue;
                }
                try {
                    $parsed = $this->parser->kmz($paths[$file['id']], (string) $state->feeder->feeder_code);
                    $issues = array_merge($issues, $parsed['issues']);
                    foreach ($parsed['roots'] as $reference => $roots) {
                        $historical[$reference] = array_merge($historical[$reference] ?? [], $roots);
                    }
                } catch (Throwable $e) {
                    $issues[] = ['code' => 'kmz_parse_failed', 'file' => $file['name'], 'message' => mb_substr($e->getMessage(), 0, 500)];
                }
            }
            foreach ($analysis['pairs'] as $key => $pair) {
                if (! isset($paths[$pair['pdf']['id']], $paths[$pair['gpx']['id']])) {
                    continue;
                }
                $transcription = $transcriptions->get($key);
                if (! $transcription || ! $transcription->reviewed_at || $transcription->pdf_drive_id !== $pair['pdf']['id'] || $transcription->pdf_checksum !== $pair['pdf']['md5Checksum'] || $transcription->gpx_drive_id !== $pair['gpx']['id'] || $transcription->gpx_checksum !== $pair['gpx']['md5Checksum']) {
                    $issues[] = ['code' => 'reviewed_transcription_required', 'survey' => $key];

                    continue;
                }
                try {
                    $gpx = $this->parser->gpx(file_get_contents($paths[$pair['gpx']['id']]));
                    foreach ($transcription->data['networks'] as $network) {
                        $surveys[] = ['key' => $key, 'pdf' => ['spans' => $network['spans'], 'roots' => [$network['root']], 'issues' => []], 'gpx' => $gpx];
                    }
                    $evidence[] = ['transcription_id' => $transcription->id, 'version' => $transcription->version, 'reviewed_by' => $transcription->reviewed_by, 'reviewed_at' => $transcription->reviewed_at->toIso8601String(), 'data' => $transcription->data];
                } catch (Throwable $e) {
                    $issues[] = ['code' => 'survey_parse_failed', 'survey' => $key, 'message' => mb_substr($e->getMessage(), 0, 500)];
                }
            }
            $result = $this->calculator->calculate($surveys, $historical);
            $result['issues'] = array_merge($issues, $result['issues']);
            $result['files'] = $files;
            $result['evidence'] = $evidence;
            if ($this->inventory->hash($this->drive->inventory($state->folder_id)) !== $hash) {
                throw new RuntimeException('Drive inventory changed during calculation. Synchronize and recalculate.');
            }
            DB::transaction(function () use ($state, $run, $hash, $result, $transcriptionIds) {
                $locked = ProgressFeeder::lockForUpdate()->findOrFail($state->id);
                if ($locked->manifest_hash !== $hash) {
                    throw new RuntimeException('Feeder inventory changed during calculation.');
                }
                $currentIds = Transcription::where('progress_feeder_id', $locked->id)->latest('id')->get()->unique('survey_key')->pluck('id')->sort()->values()->all();
                if ($currentIds !== $transcriptionIds) {
                    throw new RuntimeException('Transcription changed during calculation. Recalculate using the current reviewed version.');
                }
                $status = $result['issues'] ? 'needs_review' : 'completed';
                $locked->update(['length_status' => $status, 'confirmed_km' => $result['spans'] ? $result['confirmed_km'] : null,
                    'unverified_horizontal_km' => $result['unverified_horizontal_km'], 'unresolved_spans' => $result['unresolved_spans'], 'calculated_at' => now(), 'length_issues' => $result['issues']]);
                $run->update(['status' => $status, 'manifest_hash' => $hash, 'result' => $result, 'finished_at' => now()]);
            });
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            if ($state && $state->length_status !== 'stale') {
                $state->update(['length_status' => 'failed']);
            }
        } finally {
            $lock->release();
        }
    }
}
