<?php

declare(strict_types=1);

namespace EventPulse\Application\Shared;

use DateTimeImmutable;

/**
 * A source of "now" for application services.
 *
 * Why an interface: the domain aggregate accepts `DateTimeImmutable $now` as
 * a parameter (see `Notification::request()` and friends), which means the
 * application layer must produce that value. Producing it via `new
 * DateTimeImmutable('now')` directly inside a handler makes the handler
 * non-deterministic and slow to test. A `Clock` abstraction lets tests
 * substitute a fixed-time implementation.
 *
 * Why in the Application namespace and not Domain: the domain itself does
 * not need a clock — it only needs the timestamp value. Producing that
 * value is an application concern, like producing identifiers. The
 * interface is therefore a peer of repositories and DTOs, not of value
 * objects.
 *
 * Implementations:
 *  - `SystemClock` (production): `new DateTimeImmutable('now')`.
 *  - `FixedClock` (tests, in `tests/EventPulse/Unit/Application/Support`):
 *    returns a configured instant.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
