<?php

return [
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'schedule' => env('BACKUP_SCHEDULE', '0 2 * * *'),
    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Karachi'),
    // Deliberately outside both the project and Laravel's file-serving disks.
    'path' => env('BACKUP_PATH', dirname(base_path()).'/.'.basename(base_path()).'-backups'),
    // Comma-separated additional absolute document roots for unusual hosting layouts.
    'web_roots' => array_filter(explode(',', env('BACKUP_WEB_ROOTS', ''))),
    'public_key' => env('BACKUP_PUBLIC_KEY'),
    'folder_id' => env('BACKUP_DRIVE_FOLDER_ID'),
    'set' => env('BACKUP_SET', 'survey-production'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'attempts' => (int) env('BACKUP_ATTEMPTS', 3),
    'retry_minutes' => (int) env('BACKUP_RETRY_MINUTES', 60),
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),
    'http_timeout' => (int) env('BACKUP_HTTP_TIMEOUT', 120),
    'max_workspace_bytes' => (int) env('BACKUP_MAX_WORKSPACE_BYTES', 5368709120),
    'min_free_bytes' => (int) env('BACKUP_MIN_FREE_BYTES', 268435456),
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    // SMTP must be configured; database notifications are always attempted separately.
    'alert_email' => env('BACKUP_ALERT_EMAIL'),
    // All local uploads are included by default. Add local persistent directories as needed.
    'include' => ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'scripts', 'mobile', 'storage/app', 'storage/*.key'],
    'root_files' => ['artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
        'vite.config.js', '.env', '.env.example', '.htaccess', 'README.md'],
    'exclude' => ['bootstrap/cache', 'storage/app/private/drive-storage/work', 'storage/app/private/exports',
        'storage/app/private/tmp', 'storage/app/private/livewire-tmp', 'storage/app/private/mdb-exports',
        'storage/app/private/mdb-worker-staging', 'storage/app/private/data-entry-mdb',
        'storage/app/private/synergee-offline', 'public/storage', 'public/hot', 'public/backups'],
];
