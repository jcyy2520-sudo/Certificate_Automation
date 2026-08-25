<?php

return [
    // Audit trails are pseudonymized but still security-sensitive. Keep them
    // long enough for incident review without retaining them indefinitely.
    'audit_log_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),

    'email_delivery_retention_days' => (int) env('EMAIL_DELIVERY_RETENTION_DAYS', 30),

    // Public limits combine identity/form budgets with shared-network budgets.
    // The defaults allow a large venue NAT while retaining global cost caps.
    'rate_limits' => [
        'public_form_view_ip_per_minute' => (int) env('PUBLIC_FORM_VIEW_IP_PER_MINUTE', 600),
        'public_form_submit_identity_per_minute' => (int) env('PUBLIC_FORM_SUBMIT_IDENTITY_PER_MINUTE', 5),
        'public_form_submit_ip_per_minute' => (int) env('PUBLIC_FORM_SUBMIT_IP_PER_MINUTE', 600),
        'public_form_submit_form_per_minute' => (int) env('PUBLIC_FORM_SUBMIT_FORM_PER_MINUTE', 1200),
        'public_form_thanks_ip_per_minute' => (int) env('PUBLIC_FORM_THANKS_IP_PER_MINUTE', 600),
        'participant_access_ip_per_minute' => (int) env('PARTICIPANT_ACCESS_IP_PER_MINUTE', 600),
        'participant_access_global_per_minute' => (int) env('PARTICIPANT_ACCESS_GLOBAL_PER_MINUTE', 500),
        'participant_access_confirm_ip_per_minute' => (int) env('PARTICIPANT_ACCESS_CONFIRM_IP_PER_MINUTE', 600),

        // Longer-window budgets for participant access links. The defaults are
        // sized for routine events; a single large webinar can legitimately
        // exceed the per-form hourly budget, so raise these deliberately rather
        // than letting real attendees collect HTTP 429 at the door.
        'participant_access_form_per_hour' => (int) env('PARTICIPANT_ACCESS_FORM_PER_HOUR', 1000),
        'participant_access_global_per_hour' => (int) env('PARTICIPANT_ACCESS_GLOBAL_PER_HOUR', 2000),
        'participant_access_global_per_day' => (int) env('PARTICIPANT_ACCESS_GLOBAL_PER_DAY', 10000),
        'participant_access_email_per_hour' => (int) env('PARTICIPANT_ACCESS_EMAIL_PER_HOUR', 12),
        'participant_access_email_per_day' => (int) env('PARTICIPANT_ACCESS_EMAIL_PER_DAY', 30),
    ],

    'require_admin_two_factor' => (bool) env('SECURITY_REQUIRE_ADMIN_TWO_FACTOR', true),

    // Persistent recaller cookies weaken the value of MFA on shared or stolen
    // devices. Operators may opt in only after accepting that risk explicitly.
    'allow_admin_remember_me' => (bool) env('SECURITY_ALLOW_ADMIN_REMEMBER_ME', false),

    'require_sensitive_action_password_confirmation' => (bool) env(
        'SECURITY_REQUIRE_SENSITIVE_ACTION_PASSWORD_CONFIRMATION',
        env('SECURITY_REQUIRE_EXPORT_PASSWORD_CONFIRMATION', true),
    ),

    'password_confirmation_timeout' => (int) env('SECURITY_PASSWORD_CONFIRMATION_TIMEOUT', 900),

    'behind_proxy' => (bool) env('SECURITY_BEHIND_PROXY', false),

    // SQS visibility is configured outside Laravel. Operators using SQS must
    // assert the real value here so the production preflight can compare it to
    // the longest-running job timeout.
    'sqs_visibility_timeout' => (int) env('SQS_VISIBILITY_TIMEOUT', 0),

    // Boolean only: the credential itself never enters the cached configuration
    // or command output. Legacy bootstrap passwords are forbidden in production.
    'bootstrap_admin_password_present' => filled(env('ADMIN_PASSWORD')),

    // A nonce-based CSP is enforced outside local development. Inline styles
    // remain for dynamic progress geometry, but executable attributes do not.
    'content_security_policy' => [
        'enabled' => (bool) env(
            'SECURITY_CSP_ENABLED',
            env('APP_ENV', 'production') !== 'local',
        ),
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
    ],

    'hsts' => [
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],
];
