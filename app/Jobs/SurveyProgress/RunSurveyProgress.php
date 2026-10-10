<?php

namespace App\Jobs\SurveyProgress;

use App\Models\SurveyProgress\Run;
use App\Services\SurveyProgress\LengthService;
use App\Services\SurveyProgress\Synchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunSurveyProgress implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(Synchronizer $sync, LengthService $length): void
    {
        $run = Run::findOrFail($this->runId);
        if ($run->status !== 'queued') {
            return;
        }
        $run->type === 'sync' ? $sync->sync($run) : $length->calculate($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = Run::find($this->runId);
        if ($run && in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Survey progress worker failed or timed out. See the application worker log.']);
        }
    }
}
