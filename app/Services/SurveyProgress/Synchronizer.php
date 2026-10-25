<?php

namespace App\Services\SurveyProgress;

use App\Models\Feeder;
use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use App\Models\SurveyProgress\SourceFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /** Existing CLI/service entry point; queued work processes one folder per job. */
    public function sync(Run $run): void
    {
        while ($this->advance($run)) {
            $run->refresh();
        }
    }

    /** Persist checkpoints in the existing result column without rewriting source evidence. */
    public function advance(Run $run): bool
    {
        $run->refresh();
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return false;
        }
        $lock = Cache::lock('survey-progress:work', 7200);
        if (! $lock->get()) {
            return false;
        }
        $result = $run->result ?? [];
        try {
            $run->refresh();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return false;
            }
            $result = $run->result ?? [];
            if (! isset($result['checkpoint'])) {
                $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'result' => $result + ['phase' => 'Reading parent folder', 'heartbeat_at' => now()->toIso8601String()]]);
                $result = $this->plan($result);
            } else {
                $task = array_shift($result['checkpoint']);
                if ($task) {
                    $result['phase'] = 'Scanning '.$task['folder']['name'];
                    $result['heartbeat_at'] = now()->toIso8601String();
                    // Keep this folder pending until its complete inventory transaction succeeds.
                    $run->update(['result' => array_replace($result, ['checkpoint' => [$task, ...$result['checkpoint']]])]);
                    $folder = $task['folder'];
                    try {
                        $record = $task['state_id'] ? $this->syncFeeder(ProgressFeeder::findOrFail($task['state_id']), $folder) : $this->unmappedFolder($folder, $task['ambiguous']);
                        $result['folders'][] = $record;
                        $result['metrics']['files_scanned'] += count($record['files']);
                        if ($task['state_id']) {
                            $result['metrics']['feeders_updated']++;
                            $result['metrics']['reported_transformers'] += $record['count'];
                            foreach ($record['issues'] as $issue) {
                                $result['issues'][] = $issue + ['feeder_id' => $record['feeder_id']];
                            }
                        }
                    } catch (Throwable $exception) {
                        $message = $this->failureMessage($exception);
                        if ($task['state_id'] && ($state = ProgressFeeder::find($task['state_id']))) {
                            $state->update(['sync_status' => 'failed', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated', 'issues' => [['code' => 'listing_failed', 'message' => $message]]]);
                        }
                        $result['metrics']['folders_failed']++;
                        $result['issues'][] = ['code' => 'listing_failed', 'folder_id' => $folder['id'], 'feeder_id' => $task['feeder_id'], 'message' => $message];
                        Log::warning('Survey progress folder scan failed', ['run_id' => $run->id, 'feeder_id' => $task['feeder_id'], 'error' => $message]);
                    }
                    $result['metrics']['folders_processed']++;
                }
            }
            $more = count($result['checkpoint']) > 0;
            $result['phase'] = $more ? 'Waiting for next folder' : 'Finished';
            $result['heartbeat_at'] = now()->toIso8601String();
            $run->update(['status' => $more ? 'running' : ($result['issues'] ? 'partial' : 'completed'), 'result' => $result, 'finished_at' => $more ? null : now()]);

            return $more;
        } catch (Throwable $exception) {
            foreach (ProgressFeeder::whereIn('feeder_id', $this->feeders()->select('id'))->get() as $state) {
                $state->update(['sync_status' => 'failed', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated']);
            }
            $message = $this->failureMessage($exception);
            $run->update(['status' => 'failed', 'result' => $result, 'error' => $message, 'finished_at' => now()]);
            Log::warning('Survey progress synchronization failed', ['run_id' => $run->id, 'error' => $message]);

            return false;
        } finally {
            $lock->release();
        }
    }

    private function plan(array $result): array
    {
        $feeders = $this->feeders()->get();
        if ($feeders->isEmpty()) {
            throw new RuntimeException('No active feeders match SURVEY_PROGRESS_PROJECT_CODE.');
        }
        $folders = array_values(array_filter($this->drive->children(config('survey_progress.parent_folder')), fn ($f) => $f['mimeType'] === 'application/vnd.google-apps.folder'));
        $result += ['folders' => [], 'issues' => []];
        $result['metrics'] = ['folders_total' => count($folders), 'folders_processed' => 0, 'folders_failed' => 0, 'files_scanned' => 0, 'reported_transformers' => 0, 'feeders_updated' => 0];
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

                return (! $urlId || $folder['id'] === $urlId) && $this->normalizeCode(explode('-', $folder['name'], 2)[0]) === $this->normalizeCode((string) $feeder->feeder_code);
            }));
            if (count($matches) !== 1) {
                $state->update(['sync_status' => 'mapping_required', 'length_status' => $state->calculated_at ? 'stale' : 'not_calculated', 'issues' => [['code' => 'missing_or_ambiguous_folder_mapping']]]);
                $result['issues'][] = ['code' => 'missing_or_ambiguous_folder_mapping', 'feeder_id' => $feeder->id];

                continue;
            }
            $mapping[$matches[0]['id']][] = $state;
        }
        $result['checkpoint'] = [];
        foreach ($folders as $folder) {
            $targets = $mapping[$folder['id']] ?? [];
            $ambiguous = count($targets) > 1;
            if ($ambiguous) {
                foreach ($targets as $state) {
                    $state->update(['sync_status' => 'mapping_required', 'issues' => [['code' => 'folder_mapped_to_multiple_feeders']]]);
                }
                $result['issues'][] = ['code' => 'folder_mapped_to_multiple_feeders', 'folder_id' => $folder['id']];
            } elseif (! $targets) {
                $result['issues'][] = ['code' => 'unmapped_drive_folder', 'folder_id' => $folder['id'], 'name' => $folder['name']];
            }
            $state = count($targets) === 1 ? $targets[0] : null;
            $result['checkpoint'][] = ['folder' => $folder, 'state_id' => $state?->id, 'feeder_id' => $state?->feeder_id, 'ambiguous' => $ambiguous];
        }

        return $result;
    }

    private function unmappedFolder(array $folder, bool $ambiguous): array
    {
        $files = $this->drive->inventory($folder['id']);

        return ['folder_id' => $folder['id'], 'feeder_id' => null, 'files' => $files, 'issues' => $ambiguous ? [['code' => 'folder_mapped_to_multiple_feeders']] : $this->inventory->analyze($files)['issues']];
    }

    private function failureMessage(Throwable $exception): string
    {
        return $exception instanceof RuntimeException && ! $exception instanceof QueryException ? mb_substr($exception->getMessage(), 0, 1000) : 'Synchronization could not finish. Check the private application log and database connection.';
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
