<?php

use App\Services\Mdb\AccessPowerShell;

return [
    'powershell' => env('MDB_POWERSHELL') ?: AccessPowerShell::defaultPath(),
    'template' => env('MDB_TEMPLATE', resource_path('mdb/synergee-empty.mdb')),
    'timeout' => 90,
];
