<?php

declare(strict_types=1);

namespace EventPulse\Domain;

use DateTimeImmutable;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;

/**
 * Base contract for all domain events (domain.md §6).
 *
 * Every event carries:
 *  - `occurredAt`   — the moment the domain fact happened.
 *  - `correlationId` — ties this event to the originating HTTP request.
 *
 * Domain events are value objects that describe something that happened.
 * They are not commands; they carry no intent. The aggregate records them
 * internally; the application layer surfaces them via structured logging
 * and, eventually, to an event bus.
 *
 * This is an abstract class rather than an interface so that the shared
 * `occurredAt`/`correlationId` properties don't need to be re-declared
 * in every concrete event.
 */
abstract class DomainEvent
{
    public function __construct(
        private readonly DateTimeImmutable $occurredAt,
        private readonly CorrelationId $correlationId,
    ) {}

    final public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    final public function correlationId(): CorrelationId
    {
        return $this->correlationId;
    }

    /**
     * Snake-case event name for log records and future event-bus envelopes.
     * Derived from the class name by convention so callers don't need a
     * separate registry.
     */
    final public function eventName(): string
    {
        $parts = explode('\\', static::class);
        $class = end($parts);

        // Convert CamelCase to snake_case.
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
