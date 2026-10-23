<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = config('data_entry_mdb.driver', 'auto');
echo json_encode(['environment' => app()->environment(), 'platform' => PHP_OS_FAMILY,
    'driver' => $driver, 'powershell' => config('data_entry_mdb.powershell')], JSON_UNESCAPED_SLASHES).PHP_EOL;
if (!app()->environment('local') || PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Local Windows application expected.');
}
$batch = App\Models\Mdb\SurveyBatch::query()->where('status', 'approved')->whereNotNull('approved_revision_id')
    ->orderByRaw('CASE WHEN id = 16 THEN 0 ELSE 1 END')->orderByDesc('id')->first();
if (!$batch) {
    echo "No approved local survey snapshot is available.\n";
    exit(0);
}
$exporter = app(App\Services\Mdb\DataEntryMdbExport::class);
$payload = $exporter->prepare($batch);
$file = $exporter->generate($payload);
echo json_encode(['batch' => $batch->id, 'revision' => $payload['revision_number'], 'observations' => count($payload['rows']),
    'mdb_file' => $file, 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)], JSON_UNESCAPED_SLASHES).PHP_EOL;
