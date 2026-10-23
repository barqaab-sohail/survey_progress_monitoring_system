<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local') || PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('Local Windows application expected.');
}
$directory = storage_path('app/private/mdb-local-worker');
if (!is_dir($directory)) mkdir($directory, 0700, true);
$path = $directory.'/runtime.json';
if (!is_file($path)) {
    file_put_contents($path, json_encode(['secret' => bin2hex(random_bytes(32)),
        'powershell' => config('data_entry_mdb.powershell'), 'root' => $directory.'/exports'], JSON_THROW_ON_ERROR));
}
echo $path;
