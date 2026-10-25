<?php

namespace App\Services\SurveyProgress;

use App\Models\SurveyProgress\Connection;
use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncStatus
{
    public function health(): array
    {
        $missing = array_values(array_filter(['survey_progress_connections', 'survey_progress_runs', 'survey_progress_jobs', 'survey_progress_feeders', 'survey_progress_files'], fn ($table) => ! Schema::hasTable($table)));
        $connection = null;
        $connectionError = false;
        try {
            $connection = ! in_array('survey_progress_connections', $missing) ? Connection::find(1)?->token : null;
        } catch (\Throwable) {
            $connectionError = true;
        }
        $name = config('survey_progress.queue_connection');
        $queue = config('queue.connections.'.$name, []);
        $pending = null;
        if (($queue['driver'] ?? null) === 'database' && isset($queue['table']) && Schema::connection($queue['connection'] ?? null)->hasTable($queue['table'])) {
            $pending = DB::connection($queue['connection'] ?? null)->table($queue['table'])->where('queue', 'survey-progress')->count();
        }
        $seen = Cache::get('survey-progress:runner-last-seen');

        return ['ready' => $missing === [], 'missing_tables' => $missing,
            'configured' => (bool) (config('survey_progress.client_id') && config('survey_progress.client_secret')),
            'connected' => $connection !== null, 'token_read_error' => $connectionError,
            'scope_ok' => in_array(DriveClient::SCOPE, explode(' ', $connection['scope'] ?? ''), true),
            'refresh_available' => ! empty($connection['refresh_token']), 'automatic' => (bool) config('survey_progress.automatic'),
            'daily_time' => config('survey_progress.sync_time'), 'timezone' => config('survey_progress.timezone'),
            'queue_connection' => $name, 'queue_driver' => $queue['driver'] ?? null, 'queue_table' => $queue['table'] ?? null,
            'pending_jobs' => $pending, 'runner_last_seen' => $seen,
            'runner_recent' => $seen && Carbon::parse($seen)->gt(now()->subMinutes(5))];
    }

    public function run(?Run $run): ?array
    {
        if (! $run) {
            return null;
        }
        $result = $run->result ?? [];
        $folders = $result['folders'] ?? [];
        $metrics = $result['metrics'] ?? ['folders_total' => count($folders), 'folders_processed' => count($folders), 'folders_failed' => 0,
            'files_scanned' => array_sum(array_map(fn ($f) => count($f['files'] ?? []), $folders)),
            'reported_transformers' => array_sum(array_map(fn ($f) => ! empty($f['feeder_id']) ? (int) ($f['count'] ?? 0) : 0, $folders)),
            'feeders_updated' => count(array_filter($folders, fn ($f) => ! empty($f['feeder_id'])))];
        $active = in_array($run->status, ['queued', 'running'], true);

        return ['id' => $run->id, 'type' => $run->type, 'status' => $run->status, 'active' => $active,
            'phase' => $result['phase'] ?? ($run->status === 'queued' ? 'Waiting for background runner' : ucfirst($run->status)),
            'metrics' => $metrics, 'error' => $run->error,
            'issues' => array_slice($result['issues'] ?? [], 0, 100), 'issue_count' => count($result['issues'] ?? []),
            'created_at' => $run->created_at->toIso8601String(), 'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(), 'heartbeat_at' => $result['heartbeat_at'] ?? $run->updated_at->toIso8601String(),
            'waiting_too_long' => $active && ($run->status === 'queued' ? $run->created_at->lt(now()->subMinutes(2)) : $run->updated_at->lt(now()->subMinutes(10)))];
    }

    public function snapshot(?int $id = null): array
    {
        $health = $this->health();
        $run = $health['ready'] ? ($id ? Run::findOrFail($id) : (Run::whereIn('status', ['queued', 'running'])->oldest('id')->first() ?? Run::where('type', 'sync')->latest('id')->first())) : null;
        $last = $health['ready'] ? ProgressFeeder::whereIn('feeder_id', app(Synchronizer::class)->feeders()->select('id'))->max('synced_at') : null;

        return ['health' => $health, 'run' => $this->run($run), 'last_successful_sync' => $last ? Carbon::parse($last, config('app.timezone'))->toIso8601String() : null];
    }
}
