<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
$path = 'C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r101.json';
$hash = hash_file('sha256', $path);
$package = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$snapshot = $package['snapshot'];
foreach ($snapshot['sources'] as $source) {
    if (! hash_equals($source['sha256'], hash_file('sha256', 'C:/Users/sohai/Downloads/'.$source['original_name']))) {
        throw new RuntimeException('Original source hash mismatch.');
    }
}
$revision = new App\Models\Mdb\Revision(['batch_id' => 17, 'number' => 101, 'sha256' => $package['source']['revision_sha256'], 'snapshot' => $snapshot]);
$revision->id = $package['source']['revision_id'];
$template = new App\Models\Mdb\Template($snapshot['template'] + ['active' => true]);
$payload = app(App\Services\Mdb\ExportPayloadBuilder::class)->build($revision, $template, 5, hash('sha256', 'instsection-r101-complete-verification'));
$input = __DIR__.'/normal-r101-payload.json';
$output = __DIR__.'/normal-r101-final.mdb';
file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
$process = new Symfony\Component\Process\Process([App\Services\Mdb\AccessPowerShell::defaultPath(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', resource_path('mdb/synergee-empty.mdb'), '-OutputPath', $output]);
$process->setTimeout(90);
$process->mustRun();
$result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
$readback = app(App\Services\Mdb\ReadbackVerifier::class)->verify($output, $payload, $result);
if ($snapshot !== $revision->snapshot || $hash !== hash_file('sha256', $path)) {
    throw new RuntimeException('Source data changed.');
}
file_put_contents(__DIR__.'/normal-r101-readback.json', json_encode($readback, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['readback' => $readback, 'source_unchanged' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
