<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Retry\Doubles;

use DateInterval;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;

/**
 * A `RetryPolicy` that returns deterministic values, configured by
 * channel.
 *
 * Use in tests that need predictable retry timing (e.g.
 * `DispatchNotificationJobTest` asserting "the re-enqueue's
 * availableAt is exactly now + 60s") without dragging in the
 * production formula's exponential growth or jitter randomness. The
 * two together would force every assertion to use ranges and tolerate
 * variability that comes from infrastructure, not from behaviour.
 *
 * Each channel can have a different (max, delay) — useful for tests
 * that exercise per-channel routing — but the most common shape in
 * tests is "use the same numbers everywhere," available through the
 * `uniform()` named constructor.
 */
final class StaticRetryPolicy implements RetryPolicy
{
    /**
     * @param array<string, array{max:int, delay:DateInterval}> $byChannel
     *   Keyed by `Channel->value`. Channels not present default to the
     *   `$default` row.
     * @param array{max:int, delay:DateInterval} $default
     *   The fallback row used for any channel not in `$byChannel`.
     */
    private function __construct(
        private readonly array $byChannel,
        private readonly array $default,
    ) {}

    /**
     * Same `(max, delaySeconds)` for every channel. The most common
     * test shape — one number to read in the assertion.
     */
    public static function uniform(int $max, int $delaySeconds): self
    {
        return new self(
            byChannel: [],
            default:   [
                'max'   => $max,
                'delay' => new DateInterval(sprintf('PT%dS', $delaySeconds)),
            ],
        );
    }

    /**
     * Different values per channel. Use when a single test exercises
     * the "max-attempts and delay differ between channels" behaviour.
     *
     * @param array<string, array{max:int, delay_seconds:int}> $overrides
     *   Keyed by `Channel->value`. Each row supplies an override for
     *   the corresponding channel; channels not listed fall back to
     *   `$defaultMax` / `$defaultDelaySeconds`.
     */
    public static function perChannel(
        int $defaultMax = 3,
        int $defaultDelaySeconds = 60,
        array $overrides = [],
    ): self {
        $by = [];

        foreach ($overrides as $channelValue => $row) {
            $by[$channelValue] = [
                'max'   => (int) $row['max'],
                'delay' => new DateInterval(sprintf('PT%dS', (int) $row['delay_seconds'])),
            ];
        }

        return new self(
            byChannel: $by,
            default:   [
                'max'   => $defaultMax,
                'delay' => new DateInterval(sprintf('PT%dS', $defaultDelaySeconds)),
            ],
        );
    }

    #[\Override]
    public function maxAttemptsFor(Channel $channel): int
    {
        return ($this->byChannel[$channel->value] ?? $this->default)['max'];
    }

    #[\Override]
    public function nextDelay(
        Channel $channel,
        AttemptNumber $failedAttemptNumber,
    ): DateInterval {
        return ($this->byChannel[$channel->value] ?? $this->default)['delay'];
    }
}
