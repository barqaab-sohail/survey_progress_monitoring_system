<?php

namespace App\Services\Mdb;

class ExporterFactory
{
    public static function make(): Exporter
    {
        return match (config('mdb_workflow.export.driver')) {
            'windows' => app(WindowsExporter::class),
            'http' => app(HttpExporter::class),
            default => new DisabledExporter,
        };
    }
}
