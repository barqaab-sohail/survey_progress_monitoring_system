<?php

namespace App\Jobs\Mdb;

use App\Models\Mdb\ExportJob;
use App\Services\Mdb\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateMdbExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mdb-export-'.$this->exportId))->releaseAfter(30)->expireAfter(360)];
    }

    public function handle(ExportService $exports): void
    {
        $exports->run($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        ExportJob::whereKey($this->exportId)->whereNull('superseded_at')->where('status', '!=', 'generated')
            ->update(['status' => 'failed', 'log' => mb_substr($exception?->getMessage() ?? 'MDB worker exhausted queue retries.', 0, 60000), 'completed_at' => now('UTC')]);
    }
}
