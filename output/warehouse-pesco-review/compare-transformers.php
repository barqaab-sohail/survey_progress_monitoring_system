<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$warehouse = json_decode(file_get_contents(__DIR__.'/key-equipment.json'), true)['DevTransformers'];
$defaults = json_decode(file_get_contents(__DIR__.'/../../resources/mdb/engineering-defaults.json'), true)['configuration']['equipment_references']['transformers'];
$configs = App\Models\Mdb\Configuration::all()->map(fn ($c) => ['project_id' => $c->project_id, 'transformer_types' => $c->settings['engineering']['transformer_types'] ?? [], 'template_id' => $c->settings['template_id'] ?? null])->all();
$templates = App\Models\Mdb\Template::where('active', true)->get()->map(fn ($t) => ['id' => $t->id, 'transformers' => $t->metadata['equipment_references']['transformers'] ?? []])->all();
$ids = array_column($warehouse, 'TransformerName');
$result = ['source' => 'Warehouse_PESCO - 14DEC2025.mdb (saved extraction)', 'warehouse_count' => count($ids), 'bundled_ids' => $defaults, 'missing_from_bundled' => array_values(array_diff($ids, $defaults)), 'live_configurations' => $configs, 'active_templates' => $templates, 'distribution_11kv_415v' => array_values(array_filter($warehouse, fn ($r) => $r['HighSideRatedKv'] == 11 && $r['LowSideRatedKv'] == 0.415))];
file_put_contents(__DIR__.'/transformer-comparison.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
