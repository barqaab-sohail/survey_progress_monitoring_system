<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$transfer=app(App\Services\Mdb\SynergeeTransfer::class);
$package=$transfer->read(file_get_contents('C:/Users/sohai/Downloads/synergee_11140223003_batch-17_r101.json'));
$inspection=$transfer->inspect($package);
if ($inspection['issues']) { echo json_encode($inspection['issues'],JSON_PRETTY_PRINT); exit(1); }
$p=$inspection['payload'];
file_put_contents(__DIR__.'/before-payload.json',json_encode($p,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_PRETTY_PRINT));
echo json_encode(['rows'=>array_map('count',$p['tables']),'feeder_sections'=>array_values(array_filter($p['tables']['InstSection'],fn($s)=>$s['Description']==='Feeder')),'load_customer_totals'=>array_map(fn($phase)=>array_sum(array_column($p['tables']['Loads'],'Phase'.$phase.'Customers')),[1,2,3])],JSON_PRETTY_PRINT);


