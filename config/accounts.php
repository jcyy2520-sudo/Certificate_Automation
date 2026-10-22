<?php

return [
    'verification_minutes' => (int) env('ACCOUNT_VERIFICATION_MINUTES', 60),
    'resend_seconds' => (int) env('ACCOUNT_RESEND_SECONDS', 120),
    'email_queue' => env('ACCOUNT_EMAIL_QUEUE', 'account-emails'),
    'unlock_minutes' => (int) env('WEBINAR_UNLOCK_MINUTES', 120),
    'email_per_minute' => (int) env('EMAIL_SENDS_PER_MINUTE', 30),
    // Zero disables the optional local budget. Set this below your provider allowance.
    'email_per_day' => (int) env('EMAIL_SENDS_PER_DAY', 0),
];
