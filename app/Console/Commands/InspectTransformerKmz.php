<?php

namespace App\Console\Commands;

use App\Services\TransformerKmzImportService;
use Illuminate\Console\Command;

class InspectTransformerKmz extends Command
{
    protected $signature = 'hazeco:inspect-kmz {file : Absolute or relative path to a KMZ file}';

    protected $description = 'Validate a transformer KMZ without importing it';

    public function handle(TransformerKmzImportService $service): int
    {
        $file = (string) $this->argument('file');
        $path = realpath($file);
        if ($path === false) {
            $this->error('File not found: '.$file);

            return self::FAILURE;
        }

        try {
            $result = $service->inspect($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Check', 'Result'], [
            ['Source feeder', $result['source_feeder_name']],
            ['Source substation', $result['source_substation_name'] ?? 'Multiple / not supplied'],
            ['All placemarks', $result['total_placemarks']],
            ['Point placemarks', $result['point_placemarks']],
            ['Transformer count', $result['transformer_count']],
            ['Count only', $result['count_only'] ? 'Yes' : 'No'],
            ['Validated transformers', count($result['transformers'])],
        ]);
        $this->info('KMZ validation passed. No database records were changed.');

        return self::SUCCESS;
    }
}
