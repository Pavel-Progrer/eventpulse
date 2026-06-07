<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use EventPulse\Application\Notification\Exception\IdempotencyConflictException;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Use case: a caller submits a new notification for asynchronous dispatch.
 *
 * Responsibilities:
 *   1. Resolve the command into typed domain values (recipient, payload).
 *   2. Deduplicate by `(api_key_id, idempotency_key)`:
 *        - existing + same body      → 200 replay (no re-persist, no re-enqueue).
 *        - existing + different body → 409 conflict (throws).
 *        - no existing               → continue.
 *   3. Construct the aggregate via `Notification::request()`, persist it,
 *      release domain events through the event dispatcher, enqueue dispatch.
 *
 * Cross-cutting concerns this handler is *not* responsible for:
 *   - Authentication, scope checking — HTTP middleware.
 *   - HTTP serialisation — controller and API resource.
 *   - Channel dispatch — `DispatchNotificationJob` (Day 5).
 *
 * Idempotency dedup is DB-based: a single indexed lookup on
 * `notifications_idem_unique`. The notification row is the single source of
 * truth; no Redis response cache. Trade-off documented in
 * `docs/decisions/idempotency-storage.md` — revisit if dedup lookups
 * dominate the endpoint's latency budget.
 *
 * Framework note: this handler imports nothing from `Illuminate\*`. Queue
 * access goes through `NotificationDispatchQueue`; event release goes
 * through `DomainEventDispatcher`. Both are application-layer ports.
 *
 * @see EventPulse\Domain\Notification\Aggregate\Notification::request()
 * @see EventPulse\Domain\Notification\Aggregate\Notification::matchesSubmission()
 * @see docs/adr/0003-http-boundary-and-application-services.md
 * @see docs/decisions/idempotency-storage.md
 */
final class SubmitNotificationHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly NotificationDispatchQueue $dispatchQueue,
        private readonly DomainEventDispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(SubmitNotificationCommand $command): SubmitNotificationResult
    {
        $idempotencyKey = IdempotencyKey::fromString($command->idempotencyKey);
        $recipient = $this->resolveRecipient($command->channel, $command->recipient);
        $payload = NotificationPayload::forChannel($command->payload, $command->channel);

        // Dedup runs *before* identifier generation, so a replay never
        // accidentally creates a fresh aggregate.
        $existing = $this->repository->findByIdempotencyKey(
            $command->apiKeyId,
            $idempotencyKey,
        );

        if ($existing !== null) {
            return $this->handleExistingSubmission($existing, $command, $recipient, $payload);
        }

        return $this->acceptFreshSubmission($command, $recipient, $idempotencyKey);
    }

    /**
     * Persist a fresh submission, release its domain events, and enqueue
     * asynchronous dispatch. Returns the acceptance receipt for HTTP 202.
     */
    private function acceptFreshSubmission(
        SubmitNotificationCommand $command,
        Recipient $recipient,
        IdempotencyKey $idempotencyKey,
    ): SubmitNotificationResult {
        $correlationId = $command->correlationId === null
            ? CorrelationId::generate()
            : CorrelationId::fromString($command->correlationId);

        $notification = Notification::request(
            id: NotificationId::generate(),
            channel: $command->channel,
            recipient: $recipient,
            rawPayload: $command->payload,
            priority: $command->priority,
            idempotencyKey: $idempotencyKey,
            apiKeyId: $command->apiKeyId,
            correlationId: $correlationId,
            now: $this->clock->now(),
        );

        $this->repository->save($notification);

        // Release domain events through the application-layer port. The Day 8
        // implementation will route these to structured logs and the event
        // bus; today's `NullDomainEventDispatcher` is a no-op so the call
        // shape is correct now and the wiring change is infrastructure-only.
        foreach ($notification->pullPendingEvents() as $event) {
            $this->events->dispatch($event);
        }

        // Hand off to the queue. Asynchronous from here on; the worker picks
        // this up in `DispatchNotificationJob`.
        $this->dispatchQueue->enqueue(
            notificationId: $notification->id(),
            correlationId: $notification->correlationId(),
            priority: $notification->priority(),
        );

        return SubmitNotificationResult::accepted($notification);
    }

    /**
     * Decide what to do when the (api_key_id, idempotency_key) tuple is
     * already present:
     *   - same body      → idempotent replay (200), return the existing aggregate.
     *   - different body → 409 conflict, throw.
     *
     * In the replay path we deliberately do *not* re-enqueue the dispatch
     * job. The original submission already enqueued it; whether that work
     * has completed by now is irrelevant — re-enqueueing would risk a
     * duplicate dispatch if the worker had not yet picked it up.
     */
    private function handleExistingSubmission(
        Notification $existing,
        SubmitNotificationCommand $command,
        Recipient $recipient,
        NotificationPayload $payload,
    ): SubmitNotificationResult {
        $matches = $existing->matchesSubmission(
            $command->channel,
            $recipient,
            $payload,
            $command->priority,
        );

        if (! $matches) {
            throw new IdempotencyConflictException(
                apiKeyId: $command->apiKeyId,
                idempotencyKey: $existing->idempotencyKey(),
            );
        }

        return SubmitNotificationResult::idempotentReplay($existing);
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
            Channel::Email => EmailRecipient::fromString($raw),
            Channel::Sms => SmsRecipient::fromE164($raw),
            Channel::Webhook => WebhookRecipient::fromDestinationId($raw),
        };
    }
}
