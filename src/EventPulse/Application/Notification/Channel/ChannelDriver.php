<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

use EventPulse\Domain\Notification\Enum\Channel;

/**
 * A strategy implementation: knows how to deliver a notification through
 * exactly one channel.
 *
 * Drivers are pure transport adapters. They:
 *  - take a `DispatchRequest` (read-only data),
 *  - perform the channel-specific I/O,
 *  - return a `DispatchOutcome`.
 *
 * Drivers do NOT:
 *  - mutate domain aggregates (the application orchestrates that),
 *  - decide whether to retry (the aggregate plus retry policy decide that),
 *  - persist anything (the application persists outcomes via the repository),
 *  - dispatch domain events (released by the application after persist).
 *
 * This narrowness is what makes the strategy pattern earn its keep here:
 * each driver is a small, replaceable component with a single I/O concern,
 * and it can be unit-tested by faking its single transport dependency
 * (Mailer, HTTP client, or an SMS provider SDK) without bringing the
 * notification aggregate or persistence layer into the test.
 *
 * Drivers MUST NOT throw on a failed delivery — return
 * `DispatchOutcome::failure()` instead. The classification (transient,
 * permanent, unrecoverable) is the driver's domain expertise: it knows
 * that a 503 from a webhook is retryable but a 410 is not, that an SMTP
 * 5xx address-syntax error is permanent but a connection refused is
 * transient. Translating provider-specific failure modes into the three
 * domain classifications is the single piece of channel-specific
 * reasoning that genuinely belongs here.
 *
 * Drivers MAY throw on programmer-error conditions — misconfiguration,
 * malformed request, missing required field — because those are not
 * domain failures and the worker should fail loudly so the operator sees
 * the problem in logs rather than silently dead-lettering valid traffic.
 */
interface ChannelDriver
{
    /**
     * The channel this driver handles.
     *
     * Returning the enum (not a string) lets `ChannelDispatcher` index
     * drivers statically and gives the static analyser an exhaustiveness
     * check at the registration site. A new `Channel` case without a
     * matching driver becomes a boot-time `LogicException`, not a 500
     * the first time a customer uses that channel.
     */
    public function channel(): Channel;

    /**
     * Perform a single dispatch attempt and report what happened.
     *
     * The caller has already begun the attempt on the aggregate
     * (`Notification::beginAttempt()`); the driver is the I/O step in the
     * middle.
     */
    public function dispatch(DispatchRequest $request): DispatchOutcome;
}
