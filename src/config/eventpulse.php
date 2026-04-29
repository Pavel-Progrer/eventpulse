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
];
