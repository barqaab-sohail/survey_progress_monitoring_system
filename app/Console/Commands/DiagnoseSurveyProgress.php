<?php

namespace App\Console\Commands;

use App\Services\SurveyProgress\DriveClient;
use App\Services\SurveyProgress\SurveyInventory;
use App\Services\SurveyProgress\Synchronizer;
use App\Services\SurveyProgress\SyncStatus;
use Illuminate\Console\Command;

class DiagnoseSurveyProgress extends Command
{
    protected $signature = 'survey-progress:diagnose {--check-drive : Verify read access to the configured parent folder} {--feeder= : Also inspect one feeder folder without saving survey data}';

    protected $description = 'Report safe sync, OAuth, queue and runner diagnostics without displaying tokens or changing survey records';

    public function handle(SyncStatus $status, DriveClient $drive): int
    {
        $report = $status->snapshot();
        $report['runtime'] = ['php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'php_execution_limit' => ini_get('max_execution_time'),
            'pcntl_available' => extension_loaded('pcntl'), 'cache_driver' => config('cache.default'), 'project_code' => config('survey_progress.project_code')];
        $failure = ! $report['health']['ready'] || ! $report['health']['configured'] || ! $report['health']['connected'] || ! $report['health']['scope_ok'] || ! $report['health']['refresh_available'];
        if ($this->option('check-drive') || $this->option('feeder')) {
            try {
                $folders = $drive->children(config('survey_progress.parent_folder'));
                $report['drive'] = ['parent_access' => 'ok', 'feeder_folders_found' => count(array_filter($folders, fn ($f) => $f['mimeType'] === 'application/vnd.google-apps.folder')),
                    'expected_folders' => config('survey_progress.expected_folders')];
                $code = $this->option('feeder');
                if ($code !== null && $code !== '') {
                    $sync = app(Synchronizer::class);
                    $matches = array_values(array_filter($folders, fn ($folder) => $folder['mimeType'] === 'application/vnd.google-apps.folder'
                        && $sync->normalizeCode(explode('-', $folder['name'], 2)[0]) === $sync->normalizeCode($code)));
                    if (count($matches) !== 1) {
                        throw new \RuntimeException('The feeder code does not identify one Drive folder. Check its mapping.');
                    }
                    $files = $drive->inventory($matches[0]['id']);
                    $analysis = app(SurveyInventory::class)->analyze($files);
                    $report['drive']['sample'] = ['files_scanned' => count($files), 'reported_transformers' => $analysis['count'], 'issues' => array_slice($analysis['issues'], 0, 20)];
                }
            } catch (\Throwable $exception) {
                $report['drive'] ??= ['parent_access' => 'failed'];
                $report['drive']['error'] = $exception instanceof \RuntimeException ? mb_substr($exception->getMessage(), 0, 1000) : 'Read access could not be verified. Check the private application log.';
                if ($report['drive']['parent_access'] === 'ok') {
                    $report['drive']['sample_access'] = 'failed';
                }
                $failure = true;
            }
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $failure ? self::FAILURE : self::SUCCESS;
    }
}
