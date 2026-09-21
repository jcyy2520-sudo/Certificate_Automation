<?php

return [
    // These are explicit operator attestations backed by backup and restore
    // evidence. security:check supports either managed remote PostgreSQL with
    // verified TLS or strictly local self-managed PostgreSQL with local-only
    // ingress; both profiles require backup and restore-rehearsal evidence.
    'managed_postgres' => (bool) env('MANAGED_POSTGRES', false),
    'backups_enabled' => (bool) env('BACKUPS_ENABLED', false),
    'backup_last_restore_at' => env('BACKUP_LAST_RESTORE_AT'),
    'backup_restore_reference' => env('BACKUP_RESTORE_REFERENCE'),
    'backup_restore_max_age_days' => (int) env('BACKUP_RESTORE_MAX_AGE_DAYS', 90),
    'service_heartbeat_max_age_minutes' => (int) env('SERVICE_HEARTBEAT_MAX_AGE_MINUTES', 5),
];
