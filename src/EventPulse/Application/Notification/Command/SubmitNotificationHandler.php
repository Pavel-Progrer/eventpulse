<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use EventPulse\Application\Shared\Clock;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Use case: a caller submits a new notification.
 *
 * Responsibilities of this handler:
 *  1. Resolve the raw recipient string into the channel-typed `Recipient`
 *     value object. Recipient/channel mismatch is caught by the aggregate
 *     itself, but here we attempt the right factory based on the channel
 *     to give callers an early, accurate error.
 *  2. Generate identity (`NotificationId`) and a `CorrelationId` if the
 *     caller did not supply one.
 *  3. Construct the aggregate via `Notification::request()`. The aggregate
 *     enforces all domain invariants synchronously — this handler does not
 *     re-implement any of them.
 *  4. Persist the aggregate via the repository.
 *  5. Pull pending domain events and hand them to a downstream dispatcher
 *     for structured logging / future event bus delivery.
 *  6. Return a `SubmitNotificationResult` for the HTTP layer.
 *
 * Cross-cutting concerns this handler is *not* responsible for (per the
 * architectural rules):
 *  - Authentication and scope checking — HTTP middleware.
 *  - Idempotency dedup (Day 4) — see the comment in `__invoke()`.
 *  - HTTP serialisation — controller and API resource.
 *  - Channel dispatch — Day 5; the queue worker picks up persisted
 *    notifications and dispatches them.
 *
 * Framework note: this handler imports nothing from `Illuminate\*`. It accepts
 * its dependencies via the constructor and is testable in pure PHPUnit
 * without Laravel.
 *
 * @see EventPulse\Domain\Notification\Aggregate\Notification::request()
 * @see docs/adr/0003-http-boundary-and-application-services.md
 */
final class SubmitNotificationHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly Clock $clock,
    ) {}

    // TODO(Day 8) Day 8: emit NotificationRequested to the structured logger here
    public function __invoke(SubmitNotificationCommand $command): SubmitNotificationResult
    {
        $idempotencyKey = IdempotencyKey::fromString($command->idempotencyKey);

        // ---------------------------------------------------------------------
        // Day 4 will add idempotency dedup here:
        //
        //     $existing = $this->repository->findByIdempotencyKey($command->apiKeyId, $idempotencyKey);
        //     if ($existing !== null) {
        //         return SubmitNotificationResult::fromAggregate($existing, wasIdempotentReplay: true);
        //     }
        //
        // For Day 3 the handler always proceeds to creation. The idempotency
        // key is still validated and stored so the column is populated in
        // every persisted row, which Day 4 needs for the dedup query.
        // ---------------------------------------------------------------------

        $correlationId = $command->correlationId === null
            ? CorrelationId::generate()
            : CorrelationId::fromString($command->correlationId);

        $now = $this->clock->now();
        $id  = NotificationId::generate();

        $notification = Notification::request(
            id:             $id,
            channel:        $command->channel,
            recipient:      $this->resolveRecipient($command->channel, $command->recipient),
            rawPayload:     $command->payload,
            priority:       $command->priority,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       $command->apiKeyId,
            correlationId:  $correlationId,
            now:            $now,
        );

        $this->repository->save($notification);

        // Pull events so they are not silently dropped. Day 4 wires these into
        // the structured-logger / event-bus bridge; for Day 3 we drain the
        // queue defensively so the aggregate does not hold them across the
        // handler boundary.
        // TODO(Day 4) Add transaction boundary
        $notification->pullPendingEvents();

        return new SubmitNotificationResult(
            id:                  $notification->id(),
            status:              $notification->status(),
            correlationId:       $notification->correlationId(),
            createdAt:           $notification->createdAt(),
            wasIdempotentReplay: false,
        );
    }

    /**
     * Build the channel-typed recipient from the raw string the caller supplied.
     *
     * The aggregate also checks recipient/channel consistency (invariant 5.1.9),
     * but choosing the right factory here means the caller gets a *precise*
     * error ("not a valid email address") rather than the generic mismatch
     * exception the aggregate would otherwise throw. Both exceptions are
     * caught at the HTTP boundary and surfaced as 422 ValidationError; this
     * is purely a quality-of-error improvement.
     */
    private function resolveRecipient(Channel $channel, string $raw): Recipient
    {
        return match ($channel) {
            Channel::Email   => EmailRecipient::fromString($raw),
            Channel::Sms     => SmsRecipient::fromE164($raw),
            Channel::Webhook => WebhookRecipient::fromDestinationId($raw),
        };
    }
}
