<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;

interface Exporter
{
    public function configured(): bool;

    /** Writes a genuine MDB to a service-owned path and returns authenticated readback evidence. */
    public function export(array $payload, Template $template, string $outputPath): array;
}
