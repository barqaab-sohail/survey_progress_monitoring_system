<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($public.$path)) {
    return false;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.local.root' => __DIR__.'/uploads']);
$app->handleRequest(Request::capture());
