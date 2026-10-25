<?php

namespace App\Jobs\SurveyProgress;

use App\Models\SurveyProgress\Run;
use App\Services\SurveyProgress\LengthService;
use App\Services\SurveyProgress\Synchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class RunSurveyProgress implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId, ?int $timeout = null)
    {
        $this->timeout = $timeout ?? 3600;
    }

    public function handle(Synchronizer $sync, LengthService $length): void
    {
        $run = Run::findOrFail($this->runId);
        if (! in_array($run->status, $run->type === 'sync' ? ['queued', 'running'] : ['queued'], true)) {
            return;
        }
        if ($run->type !== 'sync') {
            $length->calculate($run);

            return;
        }
        if ($sync->advance($run)) {
            Bus::dispatch((new self($run->id, 300))->onConnection($this->connection ?? config('survey_progress.queue_connection'))->onQueue('survey-progress'));
        } elseif (in_array($run->fresh()->status, ['queued', 'running'], true)) {
            // Lock contention is not a failed attempt. Start a fresh queued attempt after
            // the other progress task releases the shared inventory lock.
            Bus::dispatch((new self($run->id, 300))->onConnection($this->connection ?? config('survey_progress.queue_connection'))->onQueue('survey-progress')->delay(30));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = Run::find($this->runId);
        if ($run && in_array($run->status, ['queued', 'running'], true)) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Survey progress worker failed or timed out. See the application worker log.']);
        }
    }
}
