<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$documents = [];
foreach (glob(__DIR__.'/*.inspection.json') as $path) {
    $documents[] = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
}
$analysis = (new App\Services\Mdb\ReferenceEngineeringImport)->analyze($documents, 9);
file_put_contents(__DIR__.'/analysis.json', json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$evidence = $analysis['settings']['reference_import'];
unset($analysis['settings']['reference_import']);
// Component-only observations remain evidence; they are not unconditional defaults.
foreach ($evidence['conditional_table_values'] as $table => $values) {
    foreach (array_keys($values) as $field) {
        unset($analysis['settings']['mapping']['table_values'][$table][$field]);
    }
}
$manifest = ['version' => 1, 'code' => 'reference-mdb-design-v1', 'name' => 'Consolidated reference MDB design',
    'configuration' => ['epsg' => $analysis['epsg'], 'settings' => $analysis['settings'], 'load_assumptions' => $analysis['load_assumptions'], 'equipment_references' => $evidence['equipment_references']],
    'evidence' => $evidence + ['field_evidence' => array_filter($analysis['field_evidence'], static fn ($field) => ! $field['instance_specific'])]];
file_put_contents(dirname(__DIR__, 2).'/resources/mdb/engineering-defaults.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo count($documents).' sources; '.$evidence['consistent_parameter_count'].' consistent parameters; '.count($evidence['conflicts'])." conflicts.\n";
