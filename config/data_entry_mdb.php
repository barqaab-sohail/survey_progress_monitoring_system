<?php

use App\Services\Mdb\AccessPowerShell;

return [
    'driver' => env('DATA_ENTRY_MDB_DRIVER', 'auto'),
    'powershell' => env('DATA_ENTRY_MDB_POWERSHELL') ?: AccessPowerShell::defaultPath(),
    'timeout' => (int) env('DATA_ENTRY_MDB_TIMEOUT', 180),
    'http_url' => env('DATA_ENTRY_MDB_WORKER_URL', env('MDB_EXPORT_WORKER_URL')),
    'secret' => env('DATA_ENTRY_MDB_WORKER_SECRET', env('MDB_EXPORT_WORKER_SECRET')),
    'max_output_bytes' => (int) env('DATA_ENTRY_MDB_MAX_BYTES', 104857600),
];
