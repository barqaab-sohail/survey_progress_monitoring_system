<?php

return [
    'powershell' => env('MDB_POWERSHELL', (getenv('WINDIR') ?: 'C:\\Windows').'\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe'),
    'template' => env('MDB_TEMPLATE', resource_path('mdb/synergee-empty.mdb')),
    'timeout' => 90,
];
