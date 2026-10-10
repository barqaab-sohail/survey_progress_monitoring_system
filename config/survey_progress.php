<?php

return [
    'automatic' => env('SURVEY_PROGRESS_AUTOMATIC', true),
    'parent_folder' => env('SURVEY_PROGRESS_PARENT_FOLDER', '1vu51Yb-eq2hqFaHsmv9km6QZySDoU9sK'),
    'project_code' => env('SURVEY_PROGRESS_PROJECT_CODE', 'HAZECO-TDL'),
    'expected_folders' => 154,
    'timezone' => 'Asia/Karachi',
    'sync_time' => env('SURVEY_PROGRESS_SYNC_TIME', '02:00'),
    'queue_connection' => env('SURVEY_PROGRESS_QUEUE_CONNECTION', 'survey-progress'),
    'client_id' => env('SURVEY_PROGRESS_GOOGLE_CLIENT_ID', env('GOOGLE_DRIVE_CLIENT_ID')),
    'client_secret' => env('SURVEY_PROGRESS_GOOGLE_CLIENT_SECRET', env('GOOGLE_DRIVE_CLIENT_SECRET')),
    'redirect_uri' => env('SURVEY_PROGRESS_GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/survey-progress/drive/callback'),
    'max_file_bytes' => 100 * 1024 * 1024,
    // Supports both ExtendedData and HTML table attributes in placemark descriptions.
    'kmz_transformer_field' => 'Equip_Type',
    'kmz_waypoint_field' => 'GPS_No',
];
