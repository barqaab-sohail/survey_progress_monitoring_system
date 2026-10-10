<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('survey-progress:sync', function () {
    if (! config('survey_progress.automatic')) { $this->info('Automatic survey progress is disabled.'); return 0; }
    $run = \App\Models\SurveyProgress\Run::create(['type' => 'sync']);
    app(\App\Services\SurveyProgress\Synchronizer::class)->sync($run);
    $run->refresh();
    $this->info('Survey progress synchronization #'.$run->id.': '.$run->status);
    if ($run->error) { $this->error($run->error); }
    return in_array($run->status, ['failed', 'partial'], true) ? 1 : 0;
})->purpose('Synchronize HAZECO survey progress from Google Drive without changing MDB records');

\Illuminate\Support\Facades\Schedule::command('survey-progress:sync')
    ->dailyAt(config('survey_progress.sync_time'))->timezone(config('survey_progress.timezone'))
    ->when(fn () => config('survey_progress.automatic'))->withoutOverlapping(120);
