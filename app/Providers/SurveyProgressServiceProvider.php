<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class SurveyProgressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['queue.connections.survey-progress' => [
            'driver' => 'database', 'connection' => null, 'table' => 'survey_progress_jobs',
            'queue' => 'survey-progress', 'retry_after' => 3700, 'after_commit' => true,
        ]]);
    }
}
