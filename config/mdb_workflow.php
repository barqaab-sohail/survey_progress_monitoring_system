<?php

return [
    'disk' => env('MDB_WORKFLOW_DISK', 'local'),
    'queue' => env('MDB_WORKFLOW_QUEUE', 'mdb'),
    'source_max_kb' => (int) env('MDB_SOURCE_MAX_KB', 102400),
    'python' => env('MDB_SOURCE_PYTHON', 'python'),
    'max_pdf_mb' => (int) env('MDB_PDF_MAX_MB', 100),
    'mapping_version' => 'synergee-reviewed-v1',
    'entry' => ['pole_height_unit' => env('MDB_ENTRY_POLE_HEIGHT_UNIT')],
    'export' => [
        'driver' => env('MDB_EXPORT_DRIVER', 'disabled'),
        'powershell' => env('MDB_EXPORT_POWERSHELL', (getenv('WINDIR') ?: 'C:\\Windows').'\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe'),
        'timeout' => (int) env('MDB_EXPORT_TIMEOUT', 180),
        'http_url' => env('MDB_EXPORT_WORKER_URL'),
        'secret' => env('MDB_EXPORT_WORKER_SECRET'),
        'max_output_bytes' => (int) env('MDB_EXPORT_MAX_BYTES', 104857600),
    ],
    'projection' => [
        'python' => env('MDB_PROJECTION_PYTHON', 'python'),
        'timeout' => (int) env('MDB_PROJECTION_TIMEOUT', 30),
    ],
];
