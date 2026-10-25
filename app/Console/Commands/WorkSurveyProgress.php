<?php

namespace App\Console\Commands;

use App\Services\SurveyProgress\BackgroundRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WorkSurveyProgress extends Command
{
    protected $signature = 'survey-progress:work {--max-time=50 : Stop between jobs after this many seconds}';

    protected $description = 'Run due daily sync and drain only the dedicated survey-progress queue for hosting cron';

    public function handle(BackgroundRunner $runner): int
    {
        foreach (['survey_progress_runs', 'survey_progress_connections', 'survey_progress_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Missing survey progress table: '.$table);

                return self::FAILURE;
            }
        }
        if ((int) $this->option('max-time') < 1 || (int) $this->option('max-time') > 3600) {
            $this->error('--max-time must be between 1 and 3600 seconds.');

            return self::FAILURE;
        }
        $lock = Cache::lock('survey-progress:cron-worker', 7200);
        if (! $lock->get()) {
            $this->info('The survey-progress runner is already active.');

            return self::SUCCESS;
        }
        try {
            $runner->heartbeat();
            $run = $runner->scheduleIfDue();
            if ($run) {
                $this->info('Daily sync #'.$run->id.' queued.');
            }

            // max-time is checked between jobs. Existing length calculations may take longer.
            return $this->call('queue:work', ['connection' => config('survey_progress.queue_connection'), '--queue' => 'survey-progress',
                '--stop-when-empty' => true, '--max-time' => (int) $this->option('max-time'), '--timeout' => 3600, '--tries' => 1, '--sleep' => 1]);
        } catch (\Throwable $exception) {
            Log::error('Survey progress cron runner failed', ['exception_type' => get_class($exception)]);
            $this->error('Survey progress runner failed. Check the private application log and queue configuration.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
