<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$f = App\Models\Feeder::whereIn('feeder_code', ['12803','012803'])->first();
echo json_encode($f?->only(['id','feeder_code','total_transformers'])).PHP_EOL;
if ($f) {
echo 'Records: '.$f->transformers()->count().PHP_EOL;
foreach (App\Models\TransformerKmzImport::where('feeder_id',$f->id)->get() as $i) {
echo json_encode($i->only(['id','stored_path','status','created_rows'])).PHP_EOL;
echo json_encode(array_slice($i->errors ?? [],0,3)).PHP_EOL;
$p = Illuminate\Support\Facades\Storage::disk('local')->path($i->stored_path);
if (is_file($p)) { $r = app(App\Services\TransformerKmzImportService::class)->inspect($p); echo json_encode(['validated'=>count($r['transformers']),'warnings'=>array_slice($r['warnings'],0,3)]).PHP_EOL; }
}
}
