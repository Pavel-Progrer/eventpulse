<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Enum;

use EventPulse\Domain\Notification\Enum\Channel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Channel::class)]
final class ChannelTest extends TestCase
{
    public function test_cases_have_correct_string_values(): void
    {
        self::assertSame('email', Channel::Email->value);
        self::assertSame('webhook', Channel::Webhook->value);
        self::assertSame('sms', Channel::Sms->value);
    }

    public function test_can_be_created_from_string_value(): void
    {
        self::assertSame(Channel::Email, Channel::from('email'));
        self::assertSame(Channel::Webhook, Channel::from('webhook'));
        self::assertSame(Channel::Sms, Channel::from('sms'));
    }

    public function test_from_unknown_string_throws(): void
    {
        $this->expectException(\ValueError::class);
        Channel::from('push');
    }

    public function test_try_from_unknown_string_returns_null(): void
    {
        self::assertNull(Channel::tryFrom('push'));
    }

    public function test_labels_are_human_readable(): void
    {
        self::assertSame('Email', Channel::Email->label());
        self::assertSame('Webhook', Channel::Webhook->label());
        self::assertSame('SMS', Channel::Sms->label());
    }

    public function test_all_cases_have_labels(): void
    {
        foreach (Channel::cases() as $channel) {
            self::assertNotEmpty($channel->label());
        }
    }
}
