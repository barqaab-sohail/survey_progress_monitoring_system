<?php

return [
    'payload_bytes' => (int) env('AUDIT_PAYLOAD_BYTES', 16384),
    'routine_days' => (int) env('AUDIT_ROUTINE_DAYS', 30),
    'important_days' => (int) env('AUDIT_IMPORTANT_DAYS', 180),
    // Opt in only after reviewing the dry-run and verifying a recoverable database backup.
    'cleanup_enabled' => (bool) env('AUDIT_CLEANUP_ENABLED', false),
    'cleanup_time' => '04:00',
    'timezone' => 'Asia/Karachi',
    'batch_size' => 100,
    'max_records' => 10000,
    'max_seconds' => 45,
    'backup_max_hours' => 48,
    // An allowlist: every unknown/new action is IMPORTANT, never routine by default.
    'routine_actions' => [
        'mdb.pdf_line_drawn', 'mdb.pdf_line_removed', 'mdb.pdf_row_layout_saved',
        'mdb.pdf_row_progress_updated', 'mdb.verification_values_checked',
        'gpx.calculated', 'gpx.verified_survey_loaded',
    ],
    // Historical workflow ownership is read from audit_logs by AssignmentOverview.
    // Never prune it automatically; it is application data as well as audit history.
    'protected_actions' => ['mdb.workflow_task_assigned', 'drive.duplicate_cleanup_requested'],
];
