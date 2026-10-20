<?php

use App\Services\Mdb\AccessPowerShell;

return [
    'powershell' => env('DATA_ENTRY_MDB_POWERSHELL') ?: AccessPowerShell::defaultPath(),
    'timeout' => (int) env('DATA_ENTRY_MDB_TIMEOUT', 180),
];
