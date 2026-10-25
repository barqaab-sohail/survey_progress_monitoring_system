<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$transfer = app(App\Services\Mdb\SynergeeTransfer::class);
$package = $transfer->read(file_get_contents('C:/Users/BARQAAB/Downloads/synergee_11140223003_batch-17_r70.json'));
$report = $transfer->inspect($package);
echo json_encode(['simple'=>App\Services\Mdb\GenerationMode::simple($package['snapshot']['configuration']), 'issues'=>$report['issues'], 'warning_counts'=>array_count_values(array_map(fn($w)=>$w['code']??'note',$report['warnings'])), 'payload'=>(bool)$report['payload']],JSON_PRETTY_PRINT);
