<?php

return [
    'participant_session_minutes' => (int) env('PARTICIPANT_SESSION_MINUTES', 480),
    'verification_token_minutes' => (int) env('VERIFICATION_TOKEN_MINUTES', 30),
    'default_retention_days' => (int) env('PARTICIPANT_RETENTION_DAYS', 7),
    'public_verification_after_privacy_erasure' => filter_var(
        env('PUBLIC_CERTIFICATE_VERIFICATION_AFTER_ERASURE', false),
        FILTER_VALIDATE_BOOL,
    ),
    'certificate_disk' => env('CERTIFICATE_DISK', env('FILESYSTEM_DISK', 'local')),
    'support_email' => env('SUPPORT_EMAIL') ?: env('MAIL_FROM_ADDRESS'),
    'email' => [
        'provider' => env('TRANSACTIONAL_EMAIL_PROVIDER', 'log'),
        'queue' => env('EMAIL_QUEUE', 'emails'),
        'queue_connection' => env('EMAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database-emails')),
        'max_attempts' => (int) env('EMAIL_MAX_ATTEMPTS', 3),
    ],
];
