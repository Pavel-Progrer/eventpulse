<?php

declare(strict_types=1);

return [
    'webhook' => [
        // Per-request timeout, in seconds. Higher than the typical
        // receiver response time (subseconds) and well below the worker
        // timeout (DispatchNotificationJob::$timeout = 120).
        'timeout_seconds' => (int) env('EVENTPULSE_WEBHOOK_TIMEOUT', 30),

        // Sent as the User-Agent header on every webhook POST so
        // receivers can identify EventPulse traffic in their logs.
        'user_agent' => env('EVENTPULSE_WEBHOOK_USER_AGENT', 'EventPulse/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-channel retry policy
    |--------------------------------------------------------------------------
    |
    | Mirrors specification §5.2 one row per channel. The numbers here are
    | the *defaults* — a deployment can override any value through
    | EVENTPULSE_RETRY_<CHANNEL>_<FIELD> env vars without a code change.
    |
    | Formula: delay = min(base * 2^(failed_attempt - 1), max) * (1 + jitter)
    | where jitter is sampled uniformly from [-jitter_fraction, +jitter_fraction].
    |
    | If a value is reduced below an in-flight notification's already-
    | scheduled retry, the existing retry still fires at its persisted
    | timestamp — the policy is consulted on each new attempt's failure,
    | not retroactively.
    */
    'retry' => [
        'webhook' => [
            'max_attempts' => (int) env('EVENTPULSE_RETRY_WEBHOOK_MAX_ATTEMPTS', 6),
            'base_delay_seconds' => (int) env('EVENTPULSE_RETRY_WEBHOOK_BASE_DELAY', 10),
            'max_delay_seconds' => (int) env('EVENTPULSE_RETRY_WEBHOOK_MAX_DELAY', 3600),
            'jitter_fraction' => (float) env('EVENTPULSE_RETRY_WEBHOOK_JITTER', 0.25),
        ],
        'email' => [
            'max_attempts' => (int) env('EVENTPULSE_RETRY_EMAIL_MAX_ATTEMPTS', 4),
            'base_delay_seconds' => (int) env('EVENTPULSE_RETRY_EMAIL_BASE_DELAY', 30),
            'max_delay_seconds' => (int) env('EVENTPULSE_RETRY_EMAIL_MAX_DELAY', 1800),
            'jitter_fraction' => (float) env('EVENTPULSE_RETRY_EMAIL_JITTER', 0.25),
        ],
        'sms' => [
            'max_attempts' => (int) env('EVENTPULSE_RETRY_SMS_MAX_ATTEMPTS', 3),
            'base_delay_seconds' => (int) env('EVENTPULSE_RETRY_SMS_BASE_DELAY', 15),
            'max_delay_seconds' => (int) env('EVENTPULSE_RETRY_SMS_MAX_DELAY', 600),
            'jitter_fraction' => (float) env('EVENTPULSE_RETRY_SMS_JITTER', 0.25),
        ],
    ],
];
