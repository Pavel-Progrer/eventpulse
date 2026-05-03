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
 *    subscribers would multiply the indirection without changing what
 *    happens.
 *  - The `match` is exhaustive: a new domain event added later won't compile
 *    against this dispatcher until a render branch is added — which is
 *    exactly the visibility we want for "did the operator add this event
 *    to the observability surface?" PHPStan / Psalm catch the gap.
 *  - The match-driven design keeps log shapes per-event-type stable. Adding
 *    a new field to one event's log shape is editing one branch, not
 *    threading a method down through every event class.
 *
 * Why correlation id is *the* anchor field:
 *  - The base `DomainEvent` class already carries one — every event has a
 *    correlation id by construction.
 *  - One correlation id flows through the HTTP request → the notification
 *    aggregate → every event the aggregate raises → every log line the
 *    dispatcher emits. A single grep against the JSON logs reconstructs the
 *    full lifecycle of one user-visible request, across the HTTP path and
 *    the worker path.
 *
 * Why event_name instead of class names:
 *  - Operator dashboards filter on `event` column values. Class names
 *    (FQCNs) are noisy and break when the namespace changes; the
 *    `eventName()` derived from the class basename is human-readable and
 *    stable across refactors.
 *  - A new query layer (e.g. when someone wants "show me every
 *    notification that scheduled a retry in the last hour") matches on
 *    `event_name = 'notification_scheduled_for_retry'` directly.
 *
 * What this dispatcher does *not* do:
 *  - It does not emit metrics. Metrics come from the same JSON stream via a
 *    log-to-metrics pipeline (LogQL or similar) that aggregates by
 *    `event_name` and `channel`. Doing it twice would multiply storage cost
 *    and risk drift.
 *  - It does not publish to an external event bus. That seam exists at the
 *    `DomainEventDispatcher` interface — a future `CompositeDomainEvent
 *    Dispatcher` can fan out to both this logger and a Kafka/NATS bus
 *    without changing any application service or the aggregate.
 */
final class StructuredLogDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function dispatch(DomainEvent $event): void
    {
        // The render method returns (level, message, context). Splitting
        // those out makes per-event-type-level decisions explicit and
        // testable.
        [$level, $context] = $this->render($event);

        $context['event']          = $event->eventName();
        $context['correlation_id'] = $event->correlationId()->toString();
        $context['occurred_at']    = $event->occurredAt()->format(\DateTimeInterface::ATOM);

        // The log message itself uses the event_name as the message string.
        // Most structured-log pipelines key on the message text for indexing
        // ahead of context fields; aligning the message and the `event`
        // context field means dashboards work regardless of which the
        // operator chose to filter on.
        $this->logger->log($level, $event->eventName(), $context);
    }

    /**
     * Map an event to (log level, structured context).
     *
     * The match is over `instanceof` because PHP doesn't yet support
     * matching on class types directly. Every domain event in the
     * Notification context has a branch; adding a new event without
     * adding a branch falls through to the default and throws —
     * intentional, because we'd rather know at boot than ship silent
     * observability gaps.
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

            // No match: a domain event was added without a render branch.
            // Fail loudly at the call site so the operator notices today,
            // not after a week of missing log entries.
            default => throw new \LogicException(sprintf(
                'StructuredLogDomainEventDispatcher has no render branch for event class "%s". '
                . 'Add a match arm in render().',
                $event::class,
            )),
        };
    }
}
