<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
$t=app(App\Services\Mdb\SynergeeTransfer::class);
$p=$t->read(file_get_contents('C:/Users/BARQAAB/Downloads/synergee_11140223003_batch-17_r75.json'));
$r=$t->inspect($p);
echo json_encode(['issues'=>$r['issues'],'payload'=>(bool)$r['payload']]).PHP_EOL;
if (!$r['issues']) { echo 'Generated: '.$t->generate($p).PHP_EOL; }
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode($e->errors()).PHP_EOL; }
