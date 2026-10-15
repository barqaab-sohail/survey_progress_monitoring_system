<?php

return [
    'enabled' => env('GOOGLE_DRIVE_STORAGE_ENABLED', false),
    'folder_id' => env('GOOGLE_DRIVE_STORAGE_FOLDER_ID'),
    'folder_name' => env('GOOGLE_DRIVE_STORAGE_FOLDER_NAME', 'Survey source files'),
    'queue' => env('GOOGLE_DRIVE_STORAGE_QUEUE', 'drive-storage'),
    'connection' => env('GOOGLE_DRIVE_STORAGE_QUEUE_CONNECTION', 'drive-storage'),
    'timeout' => (int) env('GOOGLE_DRIVE_STORAGE_TIMEOUT', 180),
];
