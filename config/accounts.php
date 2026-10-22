<?php

return [
    'email_provider' => env('ACCOUNT_EMAIL_PROVIDER', env('TRANSACTIONAL_EMAIL_PROVIDER', 'log')),
    'verification_minutes' => (int) env('ACCOUNT_VERIFICATION_MINUTES', 60),
    'resend_seconds' => (int) env('ACCOUNT_RESEND_SECONDS', 120),
    'email_queue' => env('ACCOUNT_EMAIL_QUEUE', 'account-emails'),
    'unlock_minutes' => (int) env('WEBINAR_UNLOCK_MINUTES', 120),
    'email_per_minute' => (int) env('EMAIL_SENDS_PER_MINUTE', 30),
    // Shared by all live messages; zero still enforces the free-plan ceiling.
    'email_per_day' => (int) env('EMAIL_SENDS_PER_DAY', 300),
    'verify_provider_quota' => (bool) env('EMAIL_VERIFY_PROVIDER_QUOTA', env('APP_ENV') !== 'testing'),
];
