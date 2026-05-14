<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Aggregate\Notification;
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
 * of a previously-stored idempotency record (HTTP 200). The handler chooses
 * which named constructor to invoke; callers should never construct this
 * directly.
 */
final readonly class SubmitNotificationResult
{
    public function __construct(
        public NotificationId $id,
        public NotificationStatus $status,
        public CorrelationId $correlationId,
        public DateTimeImmutable $createdAt,
        public bool $wasIdempotentReplay = false,
    ) {}

    /**
     * The result for a freshly accepted submission.
     *
     * Maps to HTTP 202 in the controller.
     */
    public static function accepted(Notification $notification): self
    {
        return new self(
            id:                  $notification->id(),
            status:              $notification->status(),
            correlationId:       $notification->correlationId(),
            createdAt:           $notification->createdAt(),
            wasIdempotentReplay: false,
        );
    }

    /**
     * The result for a replay of a previously persisted submission.
     *
     * Maps to HTTP 200 in the controller. The aggregate's *current* state is
     * returned (status, correlation id, created_at) — not the state at the
     * moment of original submission. A caller polling on idempotent replay
     * would observe the latest known state.
     */
    public static function idempotentReplay(Notification $notification): self
    {
        return new self(
            id:                  $notification->id(),
            status:              $notification->status(),
            correlationId:       $notification->correlationId(),
            createdAt:           $notification->createdAt(),
            wasIdempotentReplay: true,
        );
    }
}