<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$t=app(App\Services\Mdb\SynergeeTransfer::class);
$p=$t->read(file_get_contents('C:/Users/BARQAAB/Downloads/synergee_11140223003_batch-17_r79.json'));
$r=$t->inspect($p);
echo json_encode(['issues'=>$r['issues'],'readiness'=>$r['modelReadiness']],JSON_PRETTY_PRINT).PHP_EOL;
if (!$r['issues']){
 $file=$t->generate($p); $out=base_path('outputs/mdb-comparison-r79/regenerated-with-coordinate-fix.mdb');copy($file,$out);echo 'Regenerated and read back: '.$out.PHP_EOL;
}
