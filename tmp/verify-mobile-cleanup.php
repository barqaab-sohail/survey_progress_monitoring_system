<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'Synthetic users remaining: '.Illuminate\Support\Facades\DB::table('users')->where('email','mobile-smoke@example.test')->count().PHP_EOL;
echo 'Synthetic projects remaining: '.Illuminate\Support\Facades\DB::table('projects')->where('code','MOBILE-SMOKE')->count().PHP_EOL;
echo 'Field-survey tables ready: '.(Illuminate\Support\Facades\Schema::hasTable('field_surveys')?'yes':'no').PHP_EOL;
