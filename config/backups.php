<?php

return [
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'folder_id' => env('BACKUP_DRIVE_FOLDER_ID'),
    'path' => env('BACKUP_PATH', dirname(base_path()).'/.'.basename(base_path()).'-backups'),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'timezone' => 'Asia/Karachi',
    // Conservative internal limits; no additional environment settings are required.
    'timeout' => 1800,
    'http_timeout' => 120,
    'attempts' => 3,
    'max_workspace_bytes' => 5368709120,
    'min_free_bytes' => 268435456,
    'include' => ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'scripts', 'mobile'],
    'root_files' => ['artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock',
        'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'vite.config.js', '.htaccess', 'README.md'],
    'exclude' => ['bootstrap/cache', 'public/storage', 'public/uploads', 'public/backups'],
];
