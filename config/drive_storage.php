<?php

return [
    'json_export_folder_id' => env('GOOGLE_DRIVE_JSON_EXPORT_FOLDER_ID', '1lIJSvVYucz6UWqKPCKRWYtcse-7Rgi6-'),
    'enabled' => env('GOOGLE_DRIVE_STORAGE_ENABLED', false),
    'folder_id' => env('GOOGLE_DRIVE_STORAGE_FOLDER_ID'),
    'folder_name' => env('GOOGLE_DRIVE_STORAGE_FOLDER_NAME', 'Survey source files'),
    'queue' => env('GOOGLE_DRIVE_STORAGE_QUEUE', 'drive-storage'),
    'connection' => env('GOOGLE_DRIVE_STORAGE_QUEUE_CONNECTION', 'drive-storage'),
    'timeout' => (int) env('GOOGLE_DRIVE_STORAGE_TIMEOUT', 180),
];
