<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateStockPreset(60, 50, [2, 5, 2, 5, 5, 5, 5, 5, 2], [3, 5, 3, 5, 5, 5, 5, 5, 3],
            'Frequency is copied exactly from the references. Do not silently replace the recorded value with a locally expected frequency.',
            'The reference MDBs recorded 60 Hz. New project defaults use 50 Hz as the selected local default; verify frequency for each project. The original 60 Hz observations remain recorded in this evidence.');
    }

    public function down(): void
    {
        $this->updateStockPreset(50, 60, [3, 5, 3, 5, 5, 5, 5, 5, 3], [2, 5, 2, 5, 5, 5, 5, 5, 2],
            'The reference MDBs recorded 60 Hz. New project defaults use 50 Hz as the selected local default; verify frequency for each project. The original 60 Hz observations remain recorded in this evidence.',
            'Frequency is copied exactly from the references. Do not silently replace the recorded value with a locally expected frequency.');
    }

    private function updateStockPreset(int $fromFrequency, int $toFrequency, array $fromRates, array $toRates, string $fromNote, string $toNote): void
    {
        if (! Schema::hasTable('mdb_engineering_presets')) {
            return;
        }

        $preset = DB::table('mdb_engineering_presets')->where('code', 'reference-mdb-design-v1')->first();
        if (! $preset || (int) $preset->revision !== 1) {
            return;
        }

        $configuration = json_decode($preset->configuration, true);
        if (! is_array($configuration)
            || (int) ($configuration['settings']['frequency_hz'] ?? 0) !== $fromFrequency
            || (int) ($configuration['settings']['mapping']['table_values']['SAI_Control']['Frequency'] ?? 0) !== $fromFrequency) {
            return;
        }

        $categories = ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'];
        $storedRates = [];
        foreach ($categories as $category) {
            $storedRates[] = (float) ($configuration['load_assumptions']['rates'][$category]['kva'] ?? -1);
        }
        if ($storedRates !== array_map('floatval', $fromRates)) {
            return;
        }

        $configuration['settings']['frequency_hz'] = $toFrequency;
        $configuration['settings']['mapping']['table_values']['SAI_Control']['Frequency'] = $toFrequency;
        foreach ($categories as $index => $category) {
            $configuration['load_assumptions']['rates'][$category]['kva'] = $toRates[$index];
        }
        $evidence = json_decode($preset->evidence, true);
        if (is_array($evidence['notes'] ?? null)) {
            $evidence['notes'] = array_map(fn ($note) => $note === $fromNote ? $toNote : $note, $evidence['notes']);
        }
        DB::table('mdb_engineering_presets')->where('id', $preset->id)->update([
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
