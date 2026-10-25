<?php

namespace App\Services\SurveyProgress;

use App\Models\SurveyProgress\Connection;
use App\Models\SurveyProgress\Run;
use Illuminate\Support\Facades\Cache;

class BackgroundRunner
{
    /** Catch up a missed daily cron tick, but do not duplicate successful or currently active work. */
    public function scheduleIfDue(): ?Run
    {
        if (! config('survey_progress.automatic') || ! Connection::whereKey(1)->exists()) {
            return null;
        }
        $now = now(config('survey_progress.timezone'));
        $due = $now->copy()->startOfDay()->setTimeFromTimeString(config('survey_progress.sync_time'));
        if ($now->lt($due) || Run::whereIn('status', ['queued', 'running'])->exists()) {
            return null;
        }
        $latest = Run::where('type', 'sync')->where('created_at', '>=', $due->copy()->timezone(config('app.timezone')))->latest('id')->first();
        if ($latest) {
            $failedFolders = (int) ($latest->result['metrics']['folders_failed'] ?? 0);
            if ($latest->status === 'completed' || ($latest->status === 'partial' && $failedFolders === 0)) {
                return null;
            }
            if (($latest->finished_at ?? $latest->created_at)->gt(now()->subMinutes(15))) {
                return null;
            }
        }

        return app(TaskQueue::class)->enqueue('sync');
    }

    public function heartbeat(): void
    {
        Cache::put('survey-progress:runner-last-seen', now()->toIso8601String(), now()->addDays(2));
    }
}
