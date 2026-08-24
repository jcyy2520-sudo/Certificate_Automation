<?php

return [
    'participant_session_minutes' => (int) env('PARTICIPANT_SESSION_MINUTES', 120),
    'participant_pass_hours' => (int) env('PARTICIPANT_PASS_HOURS', 24),
    'verification_token_minutes' => (int) env('VERIFICATION_TOKEN_MINUTES', 15),
    'maximum_live_verification_tokens' => (int) env('MAXIMUM_LIVE_VERIFICATION_TOKENS', 12),
    'unverified_participant_retention_minutes' => (int) env('UNVERIFIED_PARTICIPANT_RETENTION_MINUTES', 1440),
    'default_retention_days' => (int) env('PARTICIPANT_RETENTION_DAYS', 30),
    // Once personal data is erased, the former public verification record is
    // linkable to the named PDF its holder received. Keep it unavailable unless
    // the deployer has documented a specific lawful-retention decision.
    'public_verification_after_privacy_erasure' => filter_var(
        env('PUBLIC_CERTIFICATE_VERIFICATION_AFTER_ERASURE', false),
        FILTER_VALIDATE_BOOL,
    ),
    'certificate_disk' => env('CERTIFICATE_DISK', env('FILESYSTEM_DISK', 'local')),
    'email' => [
        'provider' => env('TRANSACTIONAL_EMAIL_PROVIDER', 'log'),
        'queue' => env('EMAIL_QUEUE', 'emails'),
        'queue_connection' => env('EMAIL_QUEUE_CONNECTION', 'database-emails'),
        'max_attempts' => (int) env('EMAIL_MAX_ATTEMPTS', 3),
    ],
];
