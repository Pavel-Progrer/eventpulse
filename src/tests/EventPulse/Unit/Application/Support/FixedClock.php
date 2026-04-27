<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Support;

use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Application\Shared\Clock;

/**
 * A `Clock` that always returns a configured instant.
 *
 * Use in unit tests where deterministic timestamps make assertions clearer
 * and faster than reading wall-clock time.
 */
final class FixedClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public static function at(string $iso8601): self
    {
        return new self(new DateTimeImmutable($iso8601, new DateTimeZone('UTC')));
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
