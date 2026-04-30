<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Infrastructure\Notification\Retry;

use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Infrastructure\Notification\Retry\ChannelRetryPolicy;
use EventPulse\Infrastructure\Notification\Retry\RetrySettings;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Behaviour: `ChannelRetryPolicy` returns the per-channel max-attempts
 * from configuration and computes per-attempt delays using the
 * exponential-with-jitter formula from specification §5.2. The class
 * fails fast at construction if any `Channel` case has no settings,
 * and clamps growth at the configured maximum delay.
 */
#[CoversClass(ChannelRetryPolicy::class)]
#[CoversClass(RetrySettings::class)]
final class ChannelRetryPolicyTest extends TestCase
{
    /**
     * Spec values, used by tests that mirror the §5.2 table directly.
     */
    private const WEBHOOK_BASE   = 10;
    private const WEBHOOK_MAX    = 3600;
    private const EMAIL_BASE     = 30;
    private const EMAIL_MAX      = 1800;
    private const SMS_BASE       = 15;
    private const SMS_MAX        = 600;

    #[Test]
    public function max_attempts_returns_configured_value_per_channel(): void
    {
        $policy = $this->specPolicy();

        self::assertSame(6, $policy->maxAttemptsFor(Channel::Webhook));
        self::assertSame(4, $policy->maxAttemptsFor(Channel::Email));
        self::assertSame(3, $policy->maxAttemptsFor(Channel::Sms));
    }

    #[Test]
    public function constructor_throws_if_a_channel_has_no_settings(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing settings for channel "sms"');

        new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => $this->settings(6, self::WEBHOOK_BASE, self::WEBHOOK_MAX, 0.0),
                Channel::Email->value   => $this->settings(4, self::EMAIL_BASE,   self::EMAIL_MAX,   0.0),
                // sms intentionally absent
            ],
            randomizer: $this->seededRandomizer(),
        );
    }

    #[Test]
    public function next_delay_with_zero_jitter_follows_doubling_formula(): void
    {
        // Without jitter the formula simplifies to:
        //   min(base * 2^(failed - 1), max)
        // which is purely a function of (failed_attempt_number, base, max).
        // We test this curve point-by-point against a no-jitter policy so
        // the assertion has zero tolerance.
        $policy = $this->zeroJitterPolicy();

        // Failed attempt 1 → 10 * 2^0 = 10.
        self::assertSame(
            10,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(1))),
        );

        // Failed attempt 2 → 10 * 2^1 = 20.
        self::assertSame(
            20,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(2))),
        );

        // Failed attempt 3 → 10 * 2^2 = 40.
        self::assertSame(
            40,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(3))),
        );

        // Failed attempt 5 → 10 * 2^4 = 160.
        self::assertSame(
            160,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(5))),
        );
    }

    #[Test]
    public function next_delay_is_capped_at_max(): void
    {
        // Webhook base 10s, max 3600s. The doubling sequence is
        // 10, 20, 40, 80, 160, 320, 640, 1280, 2560, 5120 — so the
        // cap kicks in at failed-attempt 10. We assert exactly that
        // and a few attempts beyond it to confirm the cap is sticky.
        $policy = $this->zeroJitterPolicy();

        self::assertSame(
            2560,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(9))),
        );

        self::assertSame(
            self::WEBHOOK_MAX,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(10))),
        );

        // And a long way past the cap — still capped, no overflow,
        // no negative values from a 2^N that wrapped past PHP_INT_MAX.
        self::assertSame(
            self::WEBHOOK_MAX,
            $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(40))),
        );
    }

    /**
     * @return iterable<string, array{0: Channel, 1: int, 2: int}>
     */
    public static function specBaseDelays(): iterable
    {
        yield 'webhook first failure → 10s base' => [Channel::Webhook, 1, self::WEBHOOK_BASE];
        yield 'email first failure → 30s base'   => [Channel::Email,   1, self::EMAIL_BASE];
        yield 'sms first failure → 15s base'     => [Channel::Sms,     1, self::SMS_BASE];
    }

    #[Test]
    #[DataProvider('specBaseDelays')]
    public function first_retry_uses_each_channels_base_delay(
        Channel $channel,
        int $attempt,
        int $expectedSeconds,
    ): void {
        $policy = $this->zeroJitterPolicy();

        self::assertSame(
            $expectedSeconds,
            $this->seconds($policy->nextDelay($channel, AttemptNumber::fromInt($attempt))),
        );
    }

    #[Test]
    public function jitter_keeps_delay_within_configured_band(): void
    {
        // With ±25% jitter, a 100s base + cap-far-above setup should
        // produce delays in [75, 125]. We sample many times with a
        // seeded Randomizer to assert both bounds are respected and
        // the variation is actually happening (not accidentally always
        // zero).
        $policy = new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => $this->settings(6, 100, 100_000, 0.25),
                Channel::Email->value   => $this->settings(4, self::EMAIL_BASE, self::EMAIL_MAX, 0.0),
                Channel::Sms->value     => $this->settings(3, self::SMS_BASE,   self::SMS_MAX,   0.0),
            ],
            randomizer: $this->seededRandomizer(),
        );

        $samples = [];
        for ($i = 0; $i < 200; $i++) {
            $samples[] = $this->seconds($policy->nextDelay(
                Channel::Webhook,
                AttemptNumber::fromInt(1),
            ));
        }

        $min = min($samples);
        $max = max($samples);

        // Every sample is inside the ±25% window around 100.
        self::assertGreaterThanOrEqual(75,  $min, 'A sample fell below the -25% jitter floor.');
        self::assertLessThanOrEqual(125, $max, 'A sample exceeded the +25% jitter ceiling.');

        // And jitter is actually doing something — not all 200
        // samples land on the same value (which would suggest the
        // randomizer is being consulted but ignored).
        self::assertGreaterThan(1, count(array_unique($samples)));
    }

    #[Test]
    public function delays_are_reproducible_with_a_seeded_randomizer(): void
    {
        // Two policies built with identical seeds must produce
        // identical delay sequences. This is what makes the production
        // formula testable: a seeded Mt19937 in tests reproduces the
        // same jitter offsets across runs.
        $a = new ChannelRetryPolicy($this->specSettings(), $this->seededRandomizer(42));
        $b = new ChannelRetryPolicy($this->specSettings(), $this->seededRandomizer(42));

        $aSamples = [];
        $bSamples = [];

        for ($i = 1; $i <= 5; $i++) {
            $aSamples[] = $this->seconds($a->nextDelay(Channel::Webhook, AttemptNumber::fromInt($i)));
            $bSamples[] = $this->seconds($b->nextDelay(Channel::Webhook, AttemptNumber::fromInt($i)));
        }

        self::assertSame($aSamples, $bSamples);
    }

    #[Test]
    public function settings_constructor_rejects_invalid_input(): void
    {
        // Spot-check the four invariants RetrySettings enforces. A
        // misconfiguration in any of them produces silently wrong
        // behaviour later — we want it to fail at construction.
        $this->expectExceptionAndRunSettings(0,  10, 100, 0.25, 'maxAttempts must be ≥ 1');
        $this->expectExceptionAndRunSettings(3, -5, 100, 0.25, 'baseDelaySeconds must be ≥ 0');
        $this->expectExceptionAndRunSettings(3, 100, 50, 0.25, 'maxDelaySeconds (50) must be ≥ baseDelaySeconds (100)');
        $this->expectExceptionAndRunSettings(3,  10, 100, 1.0,  'jitterFraction must be in [0, 1)');
    }

    private function expectExceptionAndRunSettings(
        int $maxAttempts,
        int $base,
        int $max,
        float $jitter,
        string $expectedMessage,
    ): void {
        $thrown = null;
        try {
            new RetrySettings($maxAttempts, $base, $max, $jitter);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, sprintf(
            'Expected RetrySettings(%d, %d, %d, %f) to throw.',
            $maxAttempts, $base, $max, $jitter,
        ));
        self::assertStringContainsString($expectedMessage, $thrown->getMessage());
    }

    /** Builds the spec table policy with zero jitter for deterministic curve assertions. */
    private function zeroJitterPolicy(): ChannelRetryPolicy
    {
        return new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => $this->settings(6, self::WEBHOOK_BASE, self::WEBHOOK_MAX, 0.0),
                Channel::Email->value   => $this->settings(4, self::EMAIL_BASE,   self::EMAIL_MAX,   0.0),
                Channel::Sms->value     => $this->settings(3, self::SMS_BASE,     self::SMS_MAX,     0.0),
            ],
            randomizer: $this->seededRandomizer(),
        );
    }

    /** Builds the spec policy as written in §5.2, jitter included. */
    private function specPolicy(): ChannelRetryPolicy
    {
        return new ChannelRetryPolicy($this->specSettings(), $this->seededRandomizer());
    }

    /** @return array<string, RetrySettings> */
    private function specSettings(): array
    {
        return [
            Channel::Webhook->value => $this->settings(6, self::WEBHOOK_BASE, self::WEBHOOK_MAX, 0.25),
            Channel::Email->value   => $this->settings(4, self::EMAIL_BASE,   self::EMAIL_MAX,   0.25),
            Channel::Sms->value     => $this->settings(3, self::SMS_BASE,     self::SMS_MAX,     0.25),
        ];
    }

    private function settings(int $max, int $base, int $maxDelay, float $jitter): RetrySettings
    {
        return new RetrySettings(
            maxAttempts:      $max,
            baseDelaySeconds: $base,
            maxDelaySeconds:  $maxDelay,
            jitterFraction:   $jitter,
        );
    }

    private function seededRandomizer(int $seed = 1): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    /**
     * Convert the policy's `DateInterval` return value to a whole
     * number of seconds for assertions. The intervals produced by the
     * implementation use only the `s` field (constructed from
     * `PT{N}S`); higher units would mean a bug.
     */
    private function seconds(\DateInterval $interval): int
    {
        // The implementation only sets the seconds field; days,
        // months, etc. are zero. We assert that and compose the
        // value back to a single integer.
        self::assertSame(0, $interval->y);
        self::assertSame(0, $interval->m);
        self::assertSame(0, $interval->d);
        self::assertSame(0, $interval->h);
        self::assertSame(0, $interval->i);

        return $interval->s;
    }
}
