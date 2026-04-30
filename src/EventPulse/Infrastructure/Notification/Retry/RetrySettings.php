<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Retry;

/**
 * Per-channel retry parameters, read from `config/eventpulse.php`.
 *
 * The four fields together describe one row of specification §5.2's
 * retry table — they always travel together because the retry formula
 * is parameterised on all four. Splitting them across separate values
 * would invite a future caller to e.g. tune `baseDelaySeconds` without
 * looking at `maxDelaySeconds`, producing a curve the spec didn't
 * envisage.
 *
 * The constructor enforces the constraints the formula relies on:
 *  - non-negative attempt count and delays,
 *  - `base ≤ max` (otherwise the cap is unreachable and the formula
 *    silently degenerates),
 *  - jitter in `[0, 1)` (a jitter ≥ 1 would let the formula return zero
 *    or even negative effective delays, retrying instantly and possibly
 *    before "now").
 *
 * Why not a Domain value object: retry policy is not a domain invariant
 * (see `RetryPolicy` docblock). These numbers live where the policy
 * implementation lives — in infrastructure — because they are tuning
 * parameters of a strategy, not facts about a notification.
 */
final readonly class RetrySettings
{
    public function __construct(
        public int $maxAttempts,
        public int $baseDelaySeconds,
        public int $maxDelaySeconds,
        public float $jitterFraction,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'maxAttempts must be ≥ 1; got %d.',
                $maxAttempts,
            ));
        }

        if ($baseDelaySeconds < 0) {
            throw new \InvalidArgumentException(sprintf(
                'baseDelaySeconds must be ≥ 0; got %d.',
                $baseDelaySeconds,
            ));
        }

        if ($maxDelaySeconds < $baseDelaySeconds) {
            throw new \InvalidArgumentException(sprintf(
                'maxDelaySeconds (%d) must be ≥ baseDelaySeconds (%d).',
                $maxDelaySeconds,
                $baseDelaySeconds,
            ));
        }

        // Jitter is a *fraction* of the computed delay — a 0.25 means
        // "+/- 25%". Anything ≥ 1.0 would let the multiplier reach zero
        // or go negative.
        if ($jitterFraction < 0.0 || $jitterFraction >= 1.0) {
            throw new \InvalidArgumentException(sprintf(
                'jitterFraction must be in [0, 1); got %f.',
                $jitterFraction,
            ));
        }
    }
}
