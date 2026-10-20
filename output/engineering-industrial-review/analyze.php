<?php

// Read-only analysis; no Laravel bootstrap, database connection or import call.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$documents = [];
foreach (glob(__DIR__.'/*.inspection.json') as $path) {
    $documents[] = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
}
$analysis = (new App\Services\Mdb\ReferenceEngineeringImport)->analyze($documents, 2);
file_put_contents(__DIR__.'/engineering-analysis.json', json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo 'Read-only analysis complete: '.$analysis['settings']['reference_import']['consistent_parameter_count']." consistent parameters.\n";
