<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Mdb\ApprovedWarehouse as W;
if(count(W::conductors()) !== 156 || !in_array('ANT',W::conductors(),true) || in_array('ABC 50 MM2',W::conductors(),true) || !W::transformerMatches('100 KVA',100) || W::transformerMatches('100 KVA',50) || in_array(63,W::capacities())) exit(1);
echo "Approved warehouse checks passed: 156 conductors; exact transformer name, rating and voltage checks.\n";
