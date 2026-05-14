<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;


/**
 * Abstract base for the typed Recipient hierarchy (domain.md §7).
 *
 * A Recipient is channel-polymorphic: the concrete type is determined by
 * the Channel of the notification and validated at Notification construction
 * time (invariant 5.1.9). This abstract base exists so the aggregate can
 * hold a single `Recipient` property without caring which concrete type it is
 * until it needs to.
 *
 * Each concrete subclass validates its own format. "Changing" a recipient
 * means constructing a new instance — there are no setters.
 */
abstract class Recipient
{
    abstract public function toString(): string;

    abstract public function equals(self $other): bool;

    public function __toString(): string
    {
        return $this->toString();
    }
}
