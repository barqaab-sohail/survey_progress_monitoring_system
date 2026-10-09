<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;

class DisabledExporter implements Exporter
{
    public function configured(): bool
    {
        return false;
    }

    public function export(array $payload, Template $template, string $outputPath): array
    {
        throw new WorkerNotConfigured('MDB export worker not configured. The reviewed payload is retained; no MDB has been generated.');
    }
}
