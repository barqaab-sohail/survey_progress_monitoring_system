<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

$source = 'C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r96.json';
$before = hash_file('sha256', $source);
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
$payload = app(App\Services\Mdb\ExportPayloadBuilder::class)->build($revision, $template, $package['scope']['transformer_id'], hash('sha256', 'node-step-real-r96'));
$input = __DIR__.'/real-r96-payload.json';
$output = __DIR__.'/real-r96-node-step-final.mdb';
file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
$writer = new Symfony\Component\Process\Process([App\Services\Mdb\AccessPowerShell::defaultPath(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', resource_path('mdb/synergee-empty.mdb'), '-OutputPath', $output]);
$writer->setTimeout(90);
$writer->mustRun();
$result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
$readback = app(App\Services\Mdb\ReadbackVerifier::class)->verify($output, $payload, $result);
if ($before !== hash_file('sha256', $source) || $snapshot !== $revision->snapshot) {
    throw new RuntimeException('Source changed.');
}
file_put_contents(__DIR__.'/real-r96-readback.json', json_encode($readback, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['node_count' => count($payload['tables']['Node']), 'readback' => $readback, 'source_unchanged' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
