<?php

namespace App\Services\SurveyProgress;

use App\Models\Feeder;
use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use App\Models\SurveyProgress\SourceFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Synchronizer
{
    public function __construct(private DriveClient $drive, private SurveyInventory $inventory) {}

    public function feeders()
    {
        return Feeder::active()->whereHas('project', fn ($q) => $q->where('code', config('survey_progress.project_code')));
    }

    public function normalizeCode(string $code): string
    {
        $code = trim($code);

        return ctype_digit($code) ? (ltrim($code, '0') ?: '0') : strtoupper($code);
    }

    public function sync(Run $run): void
    {
        $lock = Cache::lock('survey-progress:work', 7200);
        if (! $lock->get()) {
            $run->update(['status' => 'skipped', 'error' => 'Another survey progress task is running.', 'finished_at' => now()]);

            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        $result = ['folders' => [], 'issues' => []];
        try {
            $feeders = $this->feeders()->get();
            if ($feeders->isEmpty()) {
                throw new RuntimeException('No active feeders match SURVEY_PROGRESS_PROJECT_CODE.');
            }
            $folders = array_values(array_filter($this->drive->children(config('survey_progress.parent_folder')), fn ($f) => $f['mimeType'] === 'application/vnd.google-apps.folder'));
            if (count($folders) !== (int) config('survey_progress.expected_folders')) {
                $result['issues'][] = ['code' => 'folder_count_mismatch', 'expected' => config('survey_progress.expected_folders'), 'found' => count($folders)];
            }
            $mapping = [];
            foreach ($feeders as $feeder) {
                $state = ProgressFeeder::firstOrCreate(['feeder_id' => $feeder->id]);
                $urlId = null;
                if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $feeder->survey_drive_url ?? '', $m)) {
                    $urlId = $m[1];
                }
                $matches = array_values(array_filter($folders, function ($folder) use ($state, $feeder, $urlId) {
                    if ($state->mapping_manual) {
                        return $folder['id'] === $state->folder_id;
                    }
                    $folderCode = explode('-', $folder['name'], 2)[0];

                    return (! $urlId || $folder['id'] === $urlId) && $this->normalizeCode($folderCode) === $this->normalizeCode((string) $feeder->feeder_code);
                }));
                if (count($matches) !== 1) {
                    $state->update(['sync_status' => 'mapping_required', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated', 'issues' => [['code' => 'missing_or_ambiguous_folder_mapping']]]);
                    $result['issues'][] = ['code' => 'missing_or_ambiguous_folder_mapping', 'feeder_id' => $feeder->id];

                    continue;
                }
                $mapping[$matches[0]['id']][] = [$state, $matches[0]];
            }
            foreach ($mapping as $folderId => $targets) {
                if (count($targets) !== 1) {
                    foreach ($targets as [$state]) {
                        $state->update(['sync_status' => 'mapping_required', 'issues' => [['code' => 'folder_mapped_to_multiple_feeders']]]);
                    }
                    $result['issues'][] = ['code' => 'folder_mapped_to_multiple_feeders', 'folder_id' => $folderId];
                    try {
                        $files = $this->drive->inventory($folderId);
                        $result['folders'][] = ['folder_id' => $folderId, 'feeder_id' => null, 'files' => $files, 'issues' => [['code' => 'folder_mapped_to_multiple_feeders']]];
                    } catch (Throwable) {
                        $result['issues'][] = ['code' => 'ambiguous_folder_listing_failed', 'folder_id' => $folderId];
                    }

                    continue;
                }
                [$state, $folder] = $targets[0];
                try {
                    $result['folders'][] = $this->syncFeeder($state, $folder);
                } catch (Throwable $e) {
                    $state->update(['sync_status' => 'failed', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated', 'issues' => [['code' => 'listing_failed', 'message' => mb_substr($e->getMessage(), 0, 500)]]]);
                    $result['issues'][] = ['code' => 'listing_failed', 'feeder_id' => $state->feeder_id];
                }
            }
            foreach ($folders as $folder) {
                if (! isset($mapping[$folder['id']])) {
                    $result['issues'][] = ['code' => 'unmapped_drive_folder', 'folder_id' => $folder['id'], 'name' => $folder['name']];
                    try {
                        $files = $this->drive->inventory($folder['id']);
                        $result['folders'][] = ['folder_id' => $folder['id'], 'feeder_id' => null, 'files' => $files, 'issues' => $this->inventory->analyze($files)['issues']];
                    } catch (Throwable $e) {
                        $result['issues'][] = ['code' => 'unmapped_folder_listing_failed', 'folder_id' => $folder['id']];
                    }
                }
            }
            $run->update(['status' => $result['issues'] ? 'partial' : 'completed', 'result' => $result, 'finished_at' => now()]);
        } catch (Throwable $e) {
            foreach (ProgressFeeder::whereIn('feeder_id', $this->feeders()->select('id'))->get() as $state) {
                $state->update(['sync_status' => 'failed', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated']);
            }
            $run->update(['status' => 'failed', 'result' => $result, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function syncFeeder(ProgressFeeder $state, array $folder): array
    {
        $files = $this->drive->inventory($folder['id']);
        $analysis = $this->inventory->analyze($files);
        if (! $state->feeder->baseline_pending && $state->feeder->total_transformers > 0 && $analysis['count'] > $state->feeder->total_transformers) {
            $analysis['issues'][] = ['code' => 'reported_count_exceeds_baseline'];
        }
        $hash = $this->inventory->hash($files);
        DB::transaction(function () use ($state, $folder, $files, $analysis, $hash) {
            $locked = ProgressFeeder::lockForUpdate()->findOrFail($state->id);
            if ($locked->mapping_manual && $locked->folder_id !== $folder['id']) {
                throw new RuntimeException('Folder mapping changed during synchronization.');
            }
            $locked->files()->update(['active' => false]);
            foreach ($files as $file) {
                SourceFile::updateOrCreate(['progress_feeder_id' => $locked->id, 'drive_id' => $file['id']], [
                    'name' => $file['name'], 'kind' => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)),
                    'survey_key' => $this->inventory->key($file['name']), 'metadata' => $file, 'active' => true,
                ]);
            }
            $locked->update(['folder_id' => $folder['id'], 'surveyed_count' => $analysis['count'], 'synced_at' => now(),
                'sync_status' => $analysis['issues'] ? 'needs_review' : 'synced', 'issues' => $analysis['issues'], 'manifest_hash' => $hash, 'aggregates' => ['by_group' => $analysis['by_group'], 'by_date' => $analysis['by_date']],
                'length_status' => $locked->manifest_hash !== $hash && $locked->calculated_at ? 'stale' : $locked->length_status,
            ]);
        });

        return ['feeder_id' => $state->feeder_id, 'folder_id' => $folder['id'], 'manifest_hash' => $hash, 'count' => $analysis['count'], 'by_group' => $analysis['by_group'], 'by_date' => $analysis['by_date'], 'issues' => $analysis['issues'], 'files' => $files];
    }
}
