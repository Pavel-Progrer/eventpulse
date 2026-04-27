<?php

declare(strict_types=1);

namespace EventPulse\Application\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Default `Clock` implementation: the wall-clock time of the running process,
 * always in UTC.
 *
 * UTC is forced because every persistence layer in EventPulse stores UTC and
 * every log record is UTC. Producing a non-UTC `DateTimeImmutable` here would
 * silently shift values across the boundary and is the kind of bug that
 * surfaces months after deployment as "the dashboard says I dispatched it
 * yesterday but the API says today."
 */
final class SystemClock implements Clock
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
