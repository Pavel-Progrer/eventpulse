<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Retry;

use DateInterval;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use Random\Randomizer;

/**
 * Production implementation of `RetryPolicy`.
 *
 * Reads per-channel `RetrySettings` from configuration and applies the
 * specification §5.2 formula:
 *
 *     delay = min(base * 2^(failedAttempt - 1), max) * (1 + j)
 *     where j ∈ [-jitterFraction, +jitterFraction]
 *
 * Why exponential with cap rather than linear or fixed:
 *  Exponential matches the shape of typical receiver outages — a load
 *  spike or a deploy is usually short, but the longer it lasts the
 *  longer it tends to last, so doubling the wait is a reasonable
 *  prior. The cap prevents us from waiting an absurd amount of time
 *  on a long-running outage (the spec puts webhook's cap at one hour;
 *  beyond that the operator likely has a different problem to solve).
 *
 * Why jitter:
 *  Without jitter, every notification that failed in the same minute
 *  retries at the same instant, producing a thundering herd against a
 *  receiver that is just coming back up. ±25% (the spec's value) is
 *  enough to spread retries across a window large enough to absorb
 *  realistic recovery patterns without spreading them so wide that
 *  the operator loses the "wave" pattern in their dashboard.
 *
 * Why a `Randomizer` injection rather than calling `random_int()`
 * directly:
 *  Tests need determinism. Injecting `Random\Randomizer` (PHP 8.2+,
 *  recommended for new code in PHP 8.4) lets a test substitute a
 *  seeded `Mt19937` engine and assert exact delay values. Calling the
 *  global `random_int` makes every backoff test inherently flaky.
 *  The cost is one extra constructor argument; the saving is real
 *  unit-testability of the delay curve.
 *
 * What this class does *not* implement: receiver-controlled override
 * (HTTP `Retry-After` header on a webhook 408/429 response). That is a
 * separate feature with a separate code path — the driver would carry
 * the seconds value out on `DispatchOutcome` and the job would prefer
 * it over the formula. Recorded as a "trigger to revisit" in ADR-0005.
 */
final class ChannelRetryPolicy implements RetryPolicy
{
    /**
     * Resolution at which jitter is sampled. The randomizer draws a
     * uniform integer in `[0, 2 * GRANULARITY]`, which we centre and
     * scale to `[-jitterFraction, +jitterFraction]`. One million gives
     * six significant figures — comfortably finer than the integer-
     * second resolution that actually reaches the queue.
     */
    private const JITTER_GRANULARITY = 1_000_000;

    /**
     * @param array<string, RetrySettings> $settings Keyed by `Channel->value`.
     *   The constructor verifies every `Channel` case has a settings row
     *   so that a missing channel surfaces at boot, not at the first
     *   failed dispatch.
     */
    public function __construct(
        private readonly array $settings,
        private readonly Randomizer $randomizer,
    ) {
        foreach (Channel::cases() as $channel) {
            if (! isset($this->settings[$channel->value])) {
                throw new \LogicException(sprintf(
                    'ChannelRetryPolicy is missing settings for channel "%s". '
                    . 'Add a row to config/eventpulse.php under "retry".',
                    $channel->value,
                ));
            }
        }
    }

    #[\Override]
    public function maxAttemptsFor(Channel $channel): int
    {
        return $this->settings[$channel->value]->maxAttempts;
    }

    #[\Override]
    public function nextDelay(
        Channel $channel,
        AttemptNumber $failedAttemptNumber,
    ): DateInterval {
        $settings = $this->settings[$channel->value];

        // Exponential growth, capped at the configured maximum. The
        // exponent is `failedAttempt - 1` so the *first* failure
        // schedules a delay equal to `base` (not `base * 2`). This
        // matches the spec table's "Base delay" column meaning
        // "the delay after the first failure."
        $baseSeconds = $settings->baseDelaySeconds;
        $maxSeconds  = $settings->maxDelaySeconds;
        $exponent    = $failedAttemptNumber->toInt() - 1;

        // 2^exponent grows fast; we clamp before multiplication to
        // avoid overflow for large attempt numbers (theoretical — the
        // policy caps attempts at ≤6, but the clamp is a one-line
        // safety belt and costs nothing).
        $multiplier = $exponent >= 31 ? PHP_INT_MAX : (1 << $exponent);

        $rawDelaySeconds = $baseSeconds * $multiplier;
        $cappedSeconds   = min($rawDelaySeconds, $maxSeconds);

        // Jitter: a uniform random offset in [-jitter, +jitter] applied
        // as a multiplicative factor (1 + offset). The result is always
        // positive because `jitterFraction < 1.0` is enforced by
        // RetrySettings; the cast to int truncates fractional seconds —
        // sub-second precision is not meaningful for retry scheduling
        // (queue dequeue latency dwarfs it).
        $jitter = $this->sampleJitter($settings->jitterFraction);

        $finalSeconds = max(0, (int) round($cappedSeconds * (1.0 + $jitter)));

        return new DateInterval(sprintf('PT%dS', $finalSeconds));
    }

    /**
     * Sample a uniformly distributed value in `[-fraction, +fraction]`.
     *
     * `Randomizer::getInt` produces a uniform distribution over a
     * bounded integer range; we scale and centre it around zero, then
     * multiply by the requested fraction.
     */
    private function sampleJitter(float $fraction): float
    {
        if ($fraction === 0.0) {
            return 0.0;
        }

        $sample = $this->randomizer->getInt(0, 2 * self::JITTER_GRANULARITY);

        // Map [0, 2g] → [-1, +1] → [-fraction, +fraction].
        $centred = ($sample - self::JITTER_GRANULARITY) / self::JITTER_GRANULARITY;

        return $centred * $fraction;
    }
}
