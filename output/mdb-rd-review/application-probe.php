<?php
// Read-only diagnostics: no imports, job dispatch, model saves or configuration changes.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$base = 'C:/Users/sohai/OneDrive/Desktop/HAZECO/';
$out = [];
$gpx = app(App\Services\Mdb\GpxParser::class)->parse(file_get_contents($base.'Waypoints_2026-10-08.gpx'));
$out['gpx'] = ['waypoint_count' => count($gpx['waypoints']), 'warnings' => $gpx['warnings']];
$kmz = app(App\Services\TransformerKmzImportService::class)->inspect($base.'NAWAN SHEHR (000407).kmz');
$out['kmz'] = array_diff_key($kmz, ['transformers' => true]);
$file = new Illuminate\Http\UploadedFile($base.'NAWAN SHEHR (000407).kmz', 'NAWAN SHEHR (000407).kmz', null, null, true);
$method = new ReflectionMethod(App\Services\Mdb\GenerationInputService::class, 'validateKmz');
$method->setAccessible(true);
$method->invoke(app(App\Services\Mdb\GenerationInputService::class), $file);
$out['generation_kmz_validation'] = 'passed';
try {
    $revision = new App\Models\Mdb\Revision;
    $revision->snapshot = [];
    app(App\Services\Mdb\ExportPayloadBuilder::class)->build($revision, new App\Models\Mdb\Template, null, str_repeat('0',64));
    $out['unapproved_input_gate'] = 'unexpected_pass';
} catch (Illuminate\Validation\ValidationException $e) {
    $out['unapproved_input_gate'] = $e->errors();
}
$out['local_runtime'] = ['queue_connection' => config('queue.default'), 'queue_name' => config('mdb_workflow.queue'),
    'export_driver' => config('mdb_workflow.export.driver'), 'python' => config('mdb_workflow.python')];
try {
    $out['local_configurations'] = App\Models\Mdb\Configuration::all()->map(fn($c) => [
        'project_id' => $c->project_id, 'epsg' => $c->epsg, 'approved' => (bool)($c->approved_by && $c->approved_at),
        'mapping_version' => $c->settings['mapping']['version'] ?? null,
        'mapped_network_count' => count($c->settings['mapping']['networks'] ?? []),
    ])->all();
    $out['local_templates'] = App\Models\Mdb\Template::all()->map(fn($t) => [
        'id' => $t->id, 'active' => $t->active, 'approved' => (bool)($t->approved_by && $t->approved_at),
        'version' => $t->version, 'synergee_version' => $t->synergee_version, 'sha256' => $t->sha256,
    ])->all();
} catch (Throwable $e) { $out['local_database_probe'] = 'unavailable'; }
file_put_contents(__DIR__.'/application-probe.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
