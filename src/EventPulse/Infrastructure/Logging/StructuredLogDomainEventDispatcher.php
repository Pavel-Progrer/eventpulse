<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Logging;

use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Event\NotificationDeadLettered;
use EventPulse\Domain\Notification\Event\NotificationDispatchAttempted;
use EventPulse\Domain\Notification\Event\NotificationDispatched;
use EventPulse\Domain\Notification\Event\NotificationDispatchFailed;
use EventPulse\Domain\Notification\Event\NotificationReplayed;
use EventPulse\Domain\Notification\Event\NotificationRequested;
use EventPulse\Domain\Notification\Event\NotificationScheduledForRetry;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationDisabled;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationRegistered;
use Psr\Log\LoggerInterface;

/**
 * Structured-logging implementation of `DomainEventDispatcher`.
 *
 * Replaces `NullDomainEventDispatcher` in production. Every domain event
 * released by an application service becomes one JSON log line at INFO
 * level — except for `NotificationDispatchFailed` (warning) and
 * `NotificationDeadLettered` (error), where the level reflects how the
 * record will land on dashboards and pager rotations.
 *
 * Why a single dispatcher with a `match` rather than per-event subscribers:
 *  - Every event today has the same destination (PSR-3 logger). Per-event
 *    subscribers would multiply the indirection without changing what happens.
 *  - The `match` is exhaustive: a new domain event added later won't compile
 *    against this dispatcher until a render branch is added — which is exactly
 *    the visibility we want for "did the operator add this event to the
 *    observability surface?" PHPStan / Psalm catch the gap.
 *  - The match-driven design keeps log shapes per-event-type stable. Adding a
 *    new field to one event's log shape is editing one branch, not threading a
 *    method down through every event class.
 *
 * Day 9 additions:
 *  - `WebhookDestinationRegistered` — info; logs destination id, api_key_id,
 *    and url. The signing secret is intentionally absent.
 *  - `WebhookDestinationDisabled`   — warning; logs destination id and
 *    api_key_id. Warning level because disabling a destination silently breaks
 *    in-flight webhook notifications that reference it.
 */
final class StructuredLogDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function dispatch(DomainEvent $event): void
    {
        [$level, $context] = $this->render($event);

        $context['event']          = $event->eventName();
        $context['correlation_id'] = $event->correlationId()->toString();
        $context['occurred_at']    = $event->occurredAt()->format(\DateTimeInterface::ATOM);

        $this->logger->log($level, $event->eventName(), $context);
    }

    /**
     * Map an event to (log level, structured context).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function render(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof NotificationRequested => [
                'info',
                [
                    'notification_id' => $event->notificationId()->toString(),
                    'channel'         => $event->channel()->value,
                    'priority'        => $event->priority()->value,
                    'idempotency_key' => $event->idempotencyKey()->toString(),
                    'recipient'       => $event->recipient()->toString(),
                ],
            ],

            $event instanceof NotificationDispatchAttempted => [
                'info',
                [
                    'notification_id' => $event->notificationId()->toString(),
                    'attempt_number'  => $event->attemptNumber()->toInt(),
                ],
            ],

            $event instanceof NotificationDispatched => [
                'info',
                [
                    'notification_id'      => $event->notificationId()->toString(),
                    'succeeded_on_attempt' => $event->succeededOnAttempt()->toInt(),
                ],
            ],

            $event instanceof NotificationDispatchFailed => [
                'warning',
                [
                    'notification_id' => $event->notificationId()->toString(),
                    'attempt_number'  => $event->attemptNumber()->toInt(),
                    'classification'  => $event->classification()->value,
                    'reason'          => $event->reason(),
                ],
            ],

            $event instanceof NotificationScheduledForRetry => [
                'info',
                [
                    'notification_id'       => $event->notificationId()->toString(),
                    'failed_attempt_number' => $event->failedAttemptNumber()->toInt(),
                    'next_attempt_number'   => $event->nextAttemptNumber()->toInt(),
                    'retry_after'           => $event->retryAfter()->format(\DateTimeInterface::ATOM),
                ],
            ],

            $event instanceof NotificationDeadLettered => [
                'error',
                [
                    'notification_id' => $event->notificationId()->toString(),
                    'total_attempts'  => $event->totalAttempts()->toInt(),
                    'reason'          => $event->reason(),
                ],
            ],

            $event instanceof NotificationReplayed => [
                'info',
                [
                    'original_notification_id' => $event->originalNotificationId()->toString(),
                    'replay_notification_id'   => $event->replayNotificationId()->toString(),
                ],
            ],

            // ── Day 9: webhook destination lifecycle ─────────────────────────

            $event instanceof WebhookDestinationRegistered => [
                // Info: a new delivery target is available. The signing secret
                // is intentionally omitted — it must never appear in logs.
                'info',
                [
                    'destination_id' => $event->destinationId()->toString(),
                    'api_key_id'     => $event->apiKeyId(),
                    'url'            => $event->url(),
                    'name'           => $event->name(),
                ],
            ],

            $event instanceof WebhookDestinationDisabled => [
                // Warning: disabling a destination will cause Permanent failures
                // on any in-flight or subsequently submitted webhook notifications
                // that reference it. Operators should be aware.
                'warning',
                [
                    'destination_id' => $event->destinationId()->toString(),
                    'api_key_id'     => $event->apiKeyId(),
                ],
            ],

            // No match: a domain event was added without a render branch.
            // Fail loudly so the gap is caught in tests, not after a week of
            // missing log entries in production.
            default => throw new \LogicException(sprintf(
                'StructuredLogDomainEventDispatcher has no render branch for event class "%s". '
                . 'Add a match arm in render().',
                $event::class,
            )),
        };
    }
}
