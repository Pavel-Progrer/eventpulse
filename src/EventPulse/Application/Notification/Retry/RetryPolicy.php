<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Retry;

use DateInterval;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;

/**
 * Per-channel retry policy: how many attempts a channel gets, and how
 * long to wait between a failed attempt and the next one.
 *
 * Why a single port covering both questions:
 *  Both methods are consumed at the same call site (DispatchNotificationJob,
 *  on every transient failure) with the same `Channel`. They share the same
 *  configuration table — the spec's §5.2 row for a channel binds
 *  max-attempts, base-delay, max-delay, and jitter-fraction together. A
 *  caller that has the policy for one always wants the policy for the
 *  other, so splitting them across two interfaces would be ceremony for
 *  no decoupling benefit.
 *
 * Why this lives in the Application layer:
 *  Retry policy is *not* a domain invariant. The aggregate accepts
 *  `recordFailure(maxAttempts, retryAfter)` as parameters precisely so
 *  the policy can change (per-channel, per-tenant, eventually per-
 *  destination) without the domain caring. The application layer is the
 *  natural home for "rules an operator tunes," and `DispatchNotificationJob`
 *  is the application's worker entry point that consumes them.
 *
 *  Compare: `NotificationStatus::canTransitionTo()` is a domain rule
 *  (which states are reachable from which) and lives on the enum; the
 *  retry ceiling and timing are *orchestration* rules that vary with
 *  business policy, not with what a notification fundamentally is.
 *
 * Implementations:
 *  - `ChannelRetryPolicy` (production): reads the spec's per-channel
 *    table from config and applies the exponential-with-jitter formula
 *    from specification §5.2.
 *  - `StaticRetryPolicy` (tests): returns deterministic values without
 *    randomness so retry assertions are reproducible.
 */
interface RetryPolicy
{
    /**
     * The total number of attempts (including the first) a notification on
     * this channel is allowed before being dead-lettered.
     *
     * Per specification §5.2: webhook 6, email 4, sms 3.
     *
     * The aggregate compares this to the current attempt number when a
     * transient failure is recorded; once `attempts >= maxAttempts`, the
     * notification is dead-lettered with reason `max_retries_exceeded`.
     */
    public function maxAttemptsFor(Channel $channel): int;

    /**
     * The delay between the just-failed attempt and the next attempt
     * about to be scheduled.
     *
     * The application layer adds this delay to "now" and passes the
     * absolute timestamp to `Notification::recordFailure(retryAfter:)` and
     * to `NotificationDispatchQueue::enqueue(availableAt:)`. The domain
     * sees only absolute timestamps; relative delays are an
     * implementation detail of the policy, not the aggregate.
     *
     * @param  AttemptNumber  $failedAttemptNumber  The number of the attempt
     *                                              that just failed. After attempt 1 fails the policy returns the
     *                                              delay before attempt 2 begins; the formula in the production
     *                                              implementation is `min(base * 2^(failedAttempt-1), max) * jitter`.
     */
    public function nextDelay(
        Channel $channel,
        AttemptNumber $failedAttemptNumber,
    ): DateInterval;
}
