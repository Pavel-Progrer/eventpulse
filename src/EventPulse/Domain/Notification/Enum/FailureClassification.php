<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Enum;

/**
 * How a dispatch attempt's failure is classified (domain.md §4, §6.1).
 *
 * The classification drives two things:
 *   - whether the notification is re-queued for a retry or dead-lettered;
 *   - what `NotificationDispatchFailed` carries in its payload.
 *
 * Classification happens at the domain layer (not infrastructure) so that
 * callers of the aggregate never need to inspect raw HTTP codes or exception
 * types. The infrastructure adapter translates its errors into one of these
 * three cases before calling back into the domain.
 *
 * See domain.md §9 (modeling decisions worth their own ADRs) for the
 * rationale for classifying here rather than in infrastructure.
 */
enum FailureClassification: string
{
    /**
     * The delivery mechanism is temporarily unavailable. The operation may
     * succeed if retried later (e.g., HTTP 429, HTTP 503, network timeout).
     */
    case Transient = 'transient';

    /**
     * The request is permanently invalid — retrying will not help
     * (e.g., HTTP 400, invalid recipient address, destination disabled).
     */
    case Permanent = 'permanent';

    /**
     * Something catastrophically wrong that shouldn't happen in normal
     * operation — e.g., the webhook destination was deleted between
     * submission and worker pickup. Dead-letters immediately; no retry.
     */
    case Unrecoverable = 'unrecoverable';

    public function isRetryEligible(): bool
    {
        return $this === self::Transient;
    }
}
