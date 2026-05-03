<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Infrastructure\Notification\Retry;

use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Infrastructure\Notification\Retry\ChannelRetryPolicy;
use EventPulse\Infrastructure\Notification\Retry\RetrySettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Edge-case coverage for `ChannelRetryPolicy`.
 *
 * Complements the existing `ChannelRetryPolicyTest` (which covers the
 * happy curve and the spec table from specification §5.2). The cases
 * here are the corners — values at boundaries of the input space, and
 * configurations the spec doesn't specify but that would arise during
 * a misconfiguration or a future tuning exercise. If the policy is
 * going to break, it'll break in one of these spots first.
 *
 * Specific shapes covered:
 *   - base delay of zero (all delays are zero, regardless of jitter),
 *   - max delay equal to base delay (zero-growth — clamps immediately),
 *   - per-channel SMS/email caps fire at the documented attempt number,
 *   - per-channel settings don't bleed across channels,
 *   - jitter at the floor (0.0) returns the bare exponential curve,
 *   - large attempt numbers don't overflow when computing 2^(N-1).
 *
 * The existing test already covers reproducibility-with-seed and the
 * jitter band's shape, so neither is repeated here.
 */
#[CoversClass(ChannelRetryPolicy::class)]
#[CoversClass(RetrySettings::class)]
final class ChannelRetryPolicyEdgeCasesTest extends TestCase
{
    #[Test]
    public function zero_base_delay_returns_zero_for_every_attempt_regardless_of_jitter(): void
    {
        // A misconfigured channel with base=0 must still produce valid
        // intervals — and they must all be zero seconds. This is the
        // "the operator wants no delay" knob; the policy must honour it.
        // Crucially, the jitter formula `delay * (1 ± jitter)` collapses
        // to 0 when delay is 0, so jitter must not introduce a stray
        // non-zero value.
        $policy = new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => new RetrySettings(6, 0, 0,    0.25),
                Channel::Email->value   => new RetrySettings(4, 30, 1800, 0.0),
                Channel::Sms->value     => new RetrySettings(3, 15, 600,  0.0),
            ],
            randomizer: $this->seeded(),
        );

        for ($n = 1; $n <= 20; $n++) {
            self::assertSame(
                0,
                $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt($n))),
                sprintf('attempt %d: zero-base-delay channel must return 0', $n),
            );
        }
    }

    #[Test]
    public function max_delay_equal_to_base_delay_clamps_immediately(): void
    {
        // base=60, max=60: every retry returns the base. Useful for
        // "fixed retry interval, no growth" channel configurations
        // (rare but legitimate — e.g. a channel with a known steady
        // rate limit that doesn't deserve exponential backoff).
        $policy = new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => new RetrySettings(6, 60, 60, 0.0),
                Channel::Email->value   => new RetrySettings(4, 30, 1800, 0.0),
                Channel::Sms->value     => new RetrySettings(3, 15, 600,  0.0),
            ],
            randomizer: $this->seeded(),
        );

        for ($n = 1; $n <= 10; $n++) {
            self::assertSame(
                60,
                $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt($n))),
                sprintf('attempt %d: zero-growth setting must always return base', $n),
            );
        }
    }

    #[Test]
    public function sms_curve_caps_quickly_due_to_low_max(): void
    {
        // SMS spec values: base 15s, max 600s. Doubling sequence:
        //   15, 30, 60, 120, 240, 480, 960 → cap at 480 → cap at 600.
        // The cap isn't crossed cleanly in this sequence — 480 < 600 < 960
        // — so we want to confirm the policy returns exactly the spec
        // max once doubling would exceed it, not that it sticks at 480.
        $policy = $this->zeroJitterPolicy();

        self::assertSame(15,  $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(1))));
        self::assertSame(30,  $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(2))));
        self::assertSame(60,  $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(3))));
        self::assertSame(120, $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(4))));
        self::assertSame(240, $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(5))));
        self::assertSame(480, $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(6))));

        // 960 would exceed the 600 cap → expect 600.
        self::assertSame(
            600,
            $this->seconds($policy->nextDelay(Channel::Sms, AttemptNumber::fromInt(7))),
            'SMS doubling crosses the cap between attempts 6 and 7; expected exactly the spec max',
        );
    }

    #[Test]
    public function email_curve_caps_at_spec_max(): void
    {
        // Email: base 30s, max 1800s. Doubling sequence:
        //   30, 60, 120, 240, 480, 960, 1920 → cap.
        // The cap kicks in at attempt 7.
        $policy = $this->zeroJitterPolicy();

        self::assertSame(30,   $this->seconds($policy->nextDelay(Channel::Email, AttemptNumber::fromInt(1))));
        self::assertSame(960,  $this->seconds($policy->nextDelay(Channel::Email, AttemptNumber::fromInt(6))));
        self::assertSame(1800, $this->seconds($policy->nextDelay(Channel::Email, AttemptNumber::fromInt(7))));
        self::assertSame(1800, $this->seconds($policy->nextDelay(Channel::Email, AttemptNumber::fromInt(8))));
    }

    #[Test]
    public function each_channel_uses_its_own_settings(): void
    {
        // Misconfigured-by-mistake regression: a previous version of the
        // policy could resolve settings by case insensitivity or by the
        // first matching channel. Confirm explicitly that asking the
        // policy for `Email` returns the Email base, not Webhook's.
        $policy = $this->zeroJitterPolicy();

        $webhook = $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(1)));
        $email   = $this->seconds($policy->nextDelay(Channel::Email,   AttemptNumber::fromInt(1)));
        $sms     = $this->seconds($policy->nextDelay(Channel::Sms,     AttemptNumber::fromInt(1)));

        self::assertSame(10, $webhook);
        self::assertSame(30, $email);
        self::assertSame(15, $sms);

        // And the sanity check: distinct values mean the lookup didn't
        // collapse to a single channel's settings.
        self::assertCount(3, array_unique([$webhook, $email, $sms]));
    }

    #[Test]
    public function jitter_at_zero_returns_the_bare_exponential_curve(): void
    {
        // jitterFraction = 0.0 means `delay * (1 ± 0)` = `delay`.
        // The formula must short-circuit (or behave equivalently) so the
        // randomizer's output never affects the result. We assert this by
        // building two policies with the same jitter (0.0) but *different*
        // randomizer seeds and confirming identical outputs across many
        // attempts.
        $a = $this->zeroJitterPolicyWithSeed(1);
        $b = $this->zeroJitterPolicyWithSeed(9999);

        for ($n = 1; $n <= 8; $n++) {
            self::assertSame(
                $this->seconds($a->nextDelay(Channel::Webhook, AttemptNumber::fromInt($n))),
                $this->seconds($b->nextDelay(Channel::Webhook, AttemptNumber::fromInt($n))),
                "attempt $n: zero-jitter policies must agree regardless of seed",
            );
        }
    }

    #[Test]
    public function very_large_attempt_numbers_do_not_overflow(): void
    {
        // 2^(failed-1) for a large failed value would overflow PHP_INT_MAX
        // if computed directly. The policy is supposed to clamp at maxDelay
        // *before* doing the multiplication or to use a saturating arithmetic.
        // Either way the externally-visible result must be the cap — never
        // a negative number, never zero.
        $policy = $this->zeroJitterPolicy();

        // AttemptNumber's upper bound is generous; we pick something well
        // above the spec's largest configured maxAttempts (6) but well
        // below any plausible PHP_INT_MAX trouble for downstream Carbon
        // arithmetic (~63).
        $delay = $this->seconds($policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(60)));

        self::assertSame(3600, $delay, 'large attempt numbers must clamp to max, not overflow');
    }

    #[Test]
    public function jitter_band_is_lower_inclusive_at_minus_one_fraction(): void
    {
        // ±100% jitter is rejected by RetrySettings (must be in [0, 1));
        // the next valid step below that is 0.99 (or any value < 1).
        // We pick 0.99 to verify the band's lower edge — with base=100
        // the jitter floor is 100 * (1 - 0.99) = 1, never lower. Many
        // samples confirm we never see 0 (which would mean a sign error
        // pushed the band below the floor).
        $policy = new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => new RetrySettings(6, 100, 100_000, 0.99),
                Channel::Email->value   => new RetrySettings(4, 30, 1800, 0.0),
                Channel::Sms->value     => new RetrySettings(3, 15, 600, 0.0),
            ],
            randomizer: $this->seeded(),
        );

        $samples = [];
        for ($i = 0; $i < 300; $i++) {
            $samples[] = $this->seconds(
                $policy->nextDelay(Channel::Webhook, AttemptNumber::fromInt(1)),
            );
        }

        self::assertGreaterThanOrEqual(1, min($samples), 'jitter floor at -99% must not push delay below 1s');
        self::assertLessThanOrEqual(199, max($samples), 'jitter ceiling at +99% must not push delay above 199s');
    }

    // -----------------------------------------------------------------------
    // Helpers — same shape as the existing ChannelRetryPolicyTest so
    // both files read consistently.
    // -----------------------------------------------------------------------

    private function zeroJitterPolicy(): ChannelRetryPolicy
    {
        return $this->zeroJitterPolicyWithSeed(1);
    }

    private function zeroJitterPolicyWithSeed(int $seed): ChannelRetryPolicy
    {
        return new ChannelRetryPolicy(
            settings: [
                Channel::Webhook->value => new RetrySettings(6, 10, 3600, 0.0),
                Channel::Email->value   => new RetrySettings(4, 30, 1800, 0.0),
                Channel::Sms->value     => new RetrySettings(3, 15, 600,  0.0),
            ],
            randomizer: new Randomizer(new Mt19937($seed)),
        );
    }

    private function seeded(int $seed = 1): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    private function seconds(\DateInterval $interval): int
    {
        // Same invariant as the sibling test: only the seconds field is set.
        self::assertSame(0, $interval->y);
        self::assertSame(0, $interval->m);
        self::assertSame(0, $interval->d);
        self::assertSame(0, $interval->h);
        self::assertSame(0, $interval->i);

        return $interval->s;
    }
}
