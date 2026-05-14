<?php

declare(strict_types=1);

namespace EventPulse\Application\Shared;

use EventPulse\Domain\DomainEvent;

/**
 * No-op `DomainEventDispatcher`.
 *
 * Wired in `EventPulseServiceProvider` until Day 8 introduces structured
 * logging and the event bus bridge. Handlers can release events
 * unconditionally — the port is always there; today it just listens to
 * nothing.
 *
 * The deliberate design choice: prefer a named no-op over conditional
 * dispatching at the call site (`if ($this->events !== null) ...`).
 * Project conventions allow a stubbed implementation as long as it has a
 * clear no-op behaviour and a comment explaining when it will be replaced
 * — both of which apply here.
 */
final class NullDomainEventDispatcher implements DomainEventDispatcher
{
    #[\Override]
    public function dispatch(DomainEvent $event): void
    {
        // Intentionally no-op until the Day 8 dispatcher is wired.
    }
}