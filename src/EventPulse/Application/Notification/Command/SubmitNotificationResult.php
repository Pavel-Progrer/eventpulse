<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * The output of `SubmitNotificationHandler`.
 *
 * A thin acceptance receipt — not a full Notification view. The HTTP response
 * for `POST /notifications` is intentionally minimal (id + status +
 * correlation_id + created_at), per the OpenAPI `NotificationResource` schema.
 * Full status (with attempts, dispatched_at, etc.) is exposed by
 * `GET /notifications/{id}`, which is a different operation.
 *
 * Why a separate DTO instead of returning the aggregate: the aggregate is
 * a domain object whose identity is mutating state. Returning it from a
 * handler invites callers to call mutating methods on something that should
 * already be persisted and forgotten. A read-only DTO with only the fields
 * the caller needs is safer and self-documenting (see ADR-0003 §4).
 *
 * `wasIdempotentReplay` distinguishes a fresh accept (HTTP 202) from a replay
 * of a previously-stored idempotency record (HTTP 200). The actual replay
 * detection logic lands in Day 4; for Day 3 the handler always returns false
 * here, but the field exists so the controller is already wired up correctly.
 */
// TODO(Day 4): Day 4 will add idempotency dedup here
final readonly class SubmitNotificationResult
{
    public function __construct(
        public NotificationId $id,
        public NotificationStatus $status,
        public CorrelationId $correlationId,
        public DateTimeImmutable $createdAt,
        public bool $wasIdempotentReplay = false,
    ) {}
}
