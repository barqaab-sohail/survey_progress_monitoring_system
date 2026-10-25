<?php

namespace App\Services\SurveyProgress;

use App\Jobs\SurveyProgress\RunSurveyProgress;
use App\Models\SurveyProgress\Run;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TaskQueue
{
    public function enqueue(string $type, ?int $actor = null, ?int $feeder = null): Run
    {
        return Cache::lock('survey-progress:enqueue', 15)->block(5, function () use ($type, $actor, $feeder) {
            $existing = Run::whereIn('status', ['queued', 'running'])->orderBy('id')->first();
            if ($existing) {
                if ($existing->type === $type && $existing->progress_feeder_id === $feeder) {
                    return $existing;
                }
                throw ValidationException::withMessages(['sync' => 'Survey progress task #'.$existing->id.' is already queued or running. Wait for it to finish.']);
            }
            $run = null;
            try {
                return DB::transaction(function () use ($type, $actor, $feeder, &$run) {
                    $run = Run::create(['type' => $type, 'progress_feeder_id' => $feeder, 'requested_by' => $actor]);
                    Bus::dispatch((new RunSurveyProgress($run->id, $type === 'sync' ? 300 : 3600))
                        ->onConnection(config('survey_progress.queue_connection'))->onQueue('survey-progress'));

                    return $run;
                });
            } catch (\Throwable $exception) {
                // A failed after-commit dispatch must never leave a new run blocking future attempts forever.
                if ($run?->exists && $run->fresh()) {
                    $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Could not queue the task. Check the survey-progress queue configuration and database.']);
                }
                Log::error('Survey progress dispatch failed', ['run_id' => $run?->id, 'exception_type' => get_class($exception)]);
                throw ValidationException::withMessages(['sync' => 'Could not queue synchronization. Check the survey-progress queue configuration and private application log.']);
            }
        });
    }
}
