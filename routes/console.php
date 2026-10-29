<?php

use App\Models\SurveyProgress\Run;
use App\Services\SurveyProgress\Synchronizer;
use App\Services\SurveyProgress\TaskQueue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('survey-progress:sync {--queue : Queue the sync for the background runner}', function () {
    if (! config('survey_progress.automatic')) {
        $this->info('Automatic survey progress is disabled.');

        return 0;
    }
    if ($this->option('queue')) {
        $run = app(TaskQueue::class)->enqueue('sync');
        $this->info('Survey progress synchronization #'.$run->id.' queued.');

        return 0;
    }
    $run = Run::create(['type' => 'sync']);
    app(Synchronizer::class)->sync($run);
    $run->refresh();
    $this->info('Survey progress synchronization #'.$run->id.': '.$run->status);
    if ($run->error) {
        $this->error($run->error);
    }

    return in_array($run->status, ['failed', 'partial', 'queued', 'running'], true) ? 1 : 0;
})->purpose('Synchronize HAZECO survey progress from Google Drive without changing MDB records');

Schedule::command('survey-progress:work')
    ->everyMinute()->withoutOverlapping(120)->appendOutputTo(storage_path('logs/survey-progress-worker.log'));

Schedule::command('drive-storage:retry')->everyFiveMinutes()->withoutOverlapping(10);

// The runner owns a process-lifetime flock shared by scheduled and manual runs.
// On Bluehost plans permitting only 15-minute cron, invoke this command directly (docs/BACKUPS.md).
Schedule::command('backup:run --scheduled')->everyFifteenMinutes();
