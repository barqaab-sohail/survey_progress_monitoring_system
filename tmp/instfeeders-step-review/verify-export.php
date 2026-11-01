<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
$source = 'C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r96.json';
$sourceHash = hash_file('sha256', $source);
$package = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
$snapshot = $package['snapshot'];
foreach ($snapshot['sources'] as $original) {
    $path = 'C:/Users/sohai/Downloads/'.$original['original_name'];
    if (! is_file($path) || ! hash_equals($original['sha256'], hash_file('sha256', $path))) {
        throw new RuntimeException('Original source differs: '.$original['original_name']);
    }
}
$revision = new App\Models\Mdb\Revision(['batch_id' => $package['source']['batch_id'], 'number' => $package['source']['revision_number'],
    'sha256' => $package['source']['revision_sha256'], 'snapshot' => $snapshot]);
$revision->id = $package['source']['revision_id'];
$template = new App\Models\Mdb\Template($snapshot['template'] + ['active' => true]);
$payload = app(App\Services\Mdb\ExportPayloadBuilder::class)->build($revision, $template, $package['scope']['transformer_id'], hash('sha256', 'instfeeders-step-real-r96'));
$baseline = json_decode(file_get_contents(__DIR__.'/before-payload.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($payload['tables'] as $table => $rows) {
    $expected = $baseline['tables'][$table];
    if ($table === 'InstFeeders') {
        foreach ($rows as $i => $row) {
            foreach (['FeederId', 'SubstationId'] as $field) {
                unset($rows[$i][$field], $expected[$i][$field]);
            }
        }
    }
    if ($rows !== $expected) {
        throw new RuntimeException('Unrelated values changed: '.$table);
    }
}
if ($payload['settings']['electrical'] !== $baseline['settings']['electrical']) {
    throw new RuntimeException('Application settings changed.');
}
$input = __DIR__.'/payload.json';
$output = __DIR__.'/normal-export.mdb';
file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
$writer = new Symfony\Component\Process\Process([App\Services\Mdb\AccessPowerShell::defaultPath(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', resource_path('mdb/synergee-empty.mdb'), '-OutputPath', $output]);
$writer->setTimeout(90);
$writer->mustRun();
$result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
$readback = app(App\Services\Mdb\ReadbackVerifier::class)->verify($output, $payload, $result);
if ($sourceHash !== hash_file('sha256', $source) || $snapshot !== $revision->snapshot) {
    throw new RuntimeException('Source changed.');
}
$report = ['feeder' => $payload['tables']['InstFeeders'][0], 'all_other_columns_and_tables_unchanged' => true,
    'application_settings_unchanged' => true, 'source_unchanged' => true, 'readback' => $readback,
    'pdf_spelling_conflict_pending' => ['record' => '11140223003', 'saved_header' => '132kV Abbottabad', 'pdf' => '132 KVA Abbottabad']];
file_put_contents(__DIR__.'/readback.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
