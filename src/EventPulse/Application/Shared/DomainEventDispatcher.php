<?php

declare(strict_types=1);

namespace EventPulse\Application\Shared;

use EventPulse\Domain\DomainEvent;

/**
 * Application-layer port for releasing domain events to subscribers
 * (loggers, event bus, projection updaters).
 *
 * Why an interface — with a null implementation — before any real subscriber
 * exists:
 *   - Aggregates collect events in `$pendingEvents` during a use case; the
 *     application service is responsible for releasing them after persistence
 *     (ADR-0002 §"Domain events").
 *   - Calling `pullPendingEvents()` and discarding the result reads as a
 *     bug. Routing the same call through a named port makes the intent —
 *     "events are being released; today nothing listens" — visible at the
 *     call site, no comment needed.
 *   - Day 8 swaps `NullDomainEventDispatcher` for a real implementation
 *     (structured logging + event bus). Handlers do not change.
 *
 * The signature is single-event by design: subscribers normally care about
 * one event type at a time, and dispatching N events in a `foreach` loop is
 * clearer at the call site than passing arrays around.
 *
 * The parameter type is currently the notification-scoped `DomainEvent`
 * because that is the only event family in the project today. When other
 * bounded contexts add events, this widens to a shared base type — a
 * non-breaking change for every existing implementation.
 */
interface DomainEventDispatcher
{
    public function dispatch(DomainEvent $event): void;
}