<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Entity\Attempt;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\Recipient;

/**
 * The data handed to a `ChannelDriver` to perform a single dispatch.
 *
 * Carrying a focused DTO rather than the full Notification aggregate buys
 * three things:
 *
 *  1. Drivers cannot mutate domain state. The DTO is `readonly` and the
 *     aggregate's mutators (`recordSuccess`, `recordFailure`,
 *     `beginAttempt`) are not reachable from this surface. The driver is
 *     therefore physically prevented from blurring the line between "did
 *     the I/O" and "the aggregate now reflects that I/O" — a separation
 *     ADR-0002 §6 establishes and that the strategy pattern relies on.
 *
 *  2. Drivers don't import aggregate types they don't need. An email or
 *     SMS driver has no business knowing about `Attempt`, `DeadLetterMark`,
 *     or `NotificationStatus`. Bundling only the fields needed for the
 *     wire send keeps each driver narrowly scoped.
 *
 *  3. The contract between application and infrastructure is explicit.
 *     Adding a new field a driver needs (e.g., per-destination signing
 *     metadata when Day 9 lands webhook signatures) is a deliberate, named
 *     change visible in this DTO and in the `ChannelDriver` signature —
 *     not a silent reach into another property of the aggregate.
 *
 * `attemptNumber` is included because some transports want it on the wire
 * for receiver-side dedup (the spec's `X-EventPulse-Attempt` header for
 * webhooks). Day 5 doesn't emit that header yet, but the data is here so
 * Day 9 doesn't need to widen the DTO.
 */
final readonly class DispatchRequest
{
    public function __construct(
        public NotificationId $notificationId,
        public Channel $channel,
        public Recipient $recipient,
        public NotificationPayload $payload,
        public CorrelationId $correlationId,
        public AttemptNumber $attemptNumber,
    ) {}

    /**
     * Build a `DispatchRequest` from the aggregate and its in-progress
     * attempt.
     *
     * Lives here, not on `Notification`, because the request is a transport
     * concern: the aggregate has no opinion on what shape a driver wants its
     * inputs in. Keeping this constructor in the application layer means
     * later changes (adding fields, narrowing types) don't ripple into the
     * domain.
     */
    public static function from(Notification $notification, Attempt $attempt): self
    {
        return new self(
            notificationId: $notification->id(),
            channel:        $notification->channel(),
            recipient:      $notification->recipient(),
            payload:        $notification->payload(),
            correlationId:  $notification->correlationId(),
            attemptNumber:  $attempt->number(),
        );
    }
}
