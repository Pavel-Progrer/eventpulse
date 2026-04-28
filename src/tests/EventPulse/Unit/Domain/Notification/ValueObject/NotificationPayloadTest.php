<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests cover invariant 5.1.10 — payload shape must match channel.
 * Every rule in NotificationPayload's private validators is tested
 * for both the valid and invalid path.
 */
#[CoversClass(NotificationPayload::class)]
final class NotificationPayloadTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Email payload
    // ---------------------------------------------------------------------------

    public function test_email_payload_with_subject_and_text_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['subject' => 'Hello', 'text' => 'World'],
            Channel::Email,
        );
        self::assertSame(Channel::Email, $p->channel());
        self::assertSame(['subject' => 'Hello', 'text' => 'World'], $p->toArray());
    }

    public function test_email_payload_with_subject_and_html_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['subject' => 'Hello', 'html' => '<b>World</b>'],
            Channel::Email,
        );
        self::assertSame(Channel::Email, $p->channel());
    }

    public function test_email_payload_with_subject_and_both_bodies_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['subject' => 'Hello', 'text' => 'World', 'html' => '<b>World</b>'],
            Channel::Email,
        );
        self::assertSame(Channel::Email, $p->channel());
    }

    public function test_email_payload_missing_subject_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subject/');
        NotificationPayload::forChannel(['text' => 'World'], Channel::Email);
    }

    public function test_email_payload_with_empty_subject_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationPayload::forChannel(['subject' => '', 'text' => 'World'], Channel::Email);
    }

    public function test_email_payload_missing_both_bodies_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/text.*html|html.*text/i');
        NotificationPayload::forChannel(['subject' => 'Hello'], Channel::Email);
    }

    public function test_email_payload_with_empty_text_and_no_html_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationPayload::forChannel(['subject' => 'Hello', 'text' => ''], Channel::Email);
    }

    // ---------------------------------------------------------------------------
    // Webhook payload
    // ---------------------------------------------------------------------------

    public function test_webhook_payload_with_any_non_empty_array_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['event' => 'order.created', 'order_id' => 99],
            Channel::Webhook,
        );
        self::assertSame(Channel::Webhook, $p->channel());
    }

    public function test_webhook_payload_with_nested_structure_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['data' => ['id' => 1, 'items' => [1, 2, 3]]],
            Channel::Webhook,
        );
        self::assertSame(Channel::Webhook, $p->channel());
    }

    public function test_webhook_payload_empty_array_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty/i');
        NotificationPayload::forChannel([], Channel::Webhook);
    }

    // ---------------------------------------------------------------------------
    // SMS payload
    // ---------------------------------------------------------------------------

    public function test_sms_payload_with_body_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['body' => 'Your code is 1234'],
            Channel::Sms,
        );
        self::assertSame(Channel::Sms, $p->channel());
    }

    public function test_sms_payload_at_exactly_1600_chars_is_valid(): void
    {
        $p = NotificationPayload::forChannel(
            ['body' => str_repeat('x', 1600)],
            Channel::Sms,
        );
        self::assertSame(Channel::Sms, $p->channel());
    }

    public function test_sms_payload_missing_body_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/body/i');
        NotificationPayload::forChannel([], Channel::Sms);
    }

    public function test_sms_payload_empty_body_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationPayload::forChannel(['body' => ''], Channel::Sms);
    }

    public function test_sms_payload_exceeding_1600_chars_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/1600/');
        NotificationPayload::forChannel(
            ['body' => str_repeat('x', 1601)],
            Channel::Sms,
        );
    }

    // ---------------------------------------------------------------------------
    // Equality
    // ---------------------------------------------------------------------------

    public function test_payloads_with_same_data_and_channel_are_equal(): void
    {
        $a = NotificationPayload::forChannel(['body' => 'Hello'], Channel::Sms);
        $b = NotificationPayload::forChannel(['body' => 'Hello'], Channel::Sms);
        self::assertTrue($a->equals($b));
    }

    public function test_payloads_with_different_data_are_not_equal(): void
    {
        $a = NotificationPayload::forChannel(['body' => 'Hello'], Channel::Sms);
        $b = NotificationPayload::forChannel(['body' => 'Goodbye'], Channel::Sms);
        self::assertFalse($a->equals($b));
    }

    public function test_payloads_with_different_channels_are_not_equal(): void
    {
        $emailPayload   = NotificationPayload::forChannel(
            ['subject' => 'Hi', 'text' => 'There'],
            Channel::Email,
        );
        $webhookPayload = NotificationPayload::forChannel(
            ['subject' => 'Hi', 'text' => 'There'],
            Channel::Webhook,
        );
        self::assertFalse($emailPayload->equals($webhookPayload));
    }

    /**
     * Persistence round-trips through PostgreSQL `jsonb` (and any JSON
     * transport) do not preserve key declaration order. Equality must be
     * order-insensitive for associative arrays so a save → load → compare
     * cycle reports the same payload as equal — this is what makes
     * idempotent replay work.
     */
    public function test_payloads_with_same_keys_in_different_order_are_equal(): void
    {
        $a = NotificationPayload::forChannel(
            ['subject' => 'Hello', 'text' => 'World'],
            Channel::Email,
        );
        $b = NotificationPayload::forChannel(
            ['text' => 'World', 'subject' => 'Hello'],
            Channel::Email,
        );
        self::assertTrue($a->equals($b));
    }

    /**
     * Webhook payloads are arbitrary JSON. Order-insensitive equality must
     * recurse into nested associative arrays — otherwise nested key
     * reordering (which `jsonb` may produce) would falsely report a
     * conflict.
     */
    public function test_webhook_payloads_with_nested_keys_in_different_order_are_equal(): void
    {
        $a = NotificationPayload::forChannel(
            ['event' => 'order.created', 'data' => ['a' => 1, 'b' => 2]],
            Channel::Webhook,
        );
        $b = NotificationPayload::forChannel(
            ['data' => ['b' => 2, 'a' => 1], 'event' => 'order.created'],
            Channel::Webhook,
        );
        self::assertTrue($a->equals($b));
    }

    /**
     * Order *is* meaningful inside list-shaped (zero-indexed sequential)
     * arrays — e.g., an ordered sequence of webhook headers. Reordering
     * elements of such a list changes the payload's meaning and must
     * compare unequal.
     */
    public function test_webhook_payloads_with_list_elements_in_different_order_are_not_equal(): void
    {
        $a = NotificationPayload::forChannel(
            ['headers' => [['name' => 'X-First'], ['name' => 'X-Second']]],
            Channel::Webhook,
        );
        $b = NotificationPayload::forChannel(
            ['headers' => [['name' => 'X-Second'], ['name' => 'X-First']]],
            Channel::Webhook,
        );
        self::assertFalse($a->equals($b));
    }
}