<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Recipient::class)]
#[CoversClass(EmailRecipient::class)]
#[CoversClass(WebhookRecipient::class)]
#[CoversClass(SmsRecipient::class)]
final class RecipientTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // EmailRecipient
    // ---------------------------------------------------------------------------

    public function test_email_recipient_accepts_valid_address(): void
    {
        $r = EmailRecipient::fromString('user@example.com');
        self::assertSame('user@example.com', $r->toString());
    }

    public function test_email_recipient_normalises_to_lowercase(): void
    {
        $r = EmailRecipient::fromString('User@Example.COM');
        self::assertSame('user@example.com', $r->toString());
    }

    public function test_email_recipient_trims_whitespace(): void
    {
        $r = EmailRecipient::fromString('  user@example.com  ');
        self::assertSame('user@example.com', $r->toString());
    }

    public function test_email_recipient_to_string_magic(): void
    {
        $r = EmailRecipient::fromString('user@example.com');
        self::assertSame('user@example.com', (string) $r);
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_email_recipient_rejects_invalid_address(string $address): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmailRecipient::fromString($address);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'empty string' => [''],
            'missing @' => ['notanemail'],
            'missing domain' => ['user@'],
            'missing local part' => ['@example.com'],
            'double @' => ['user@@example.com'],
            'spaces in address' => ['user @example.com'],
        ];
    }

    public function test_email_recipients_with_same_address_are_equal(): void
    {
        $a = EmailRecipient::fromString('user@example.com');
        $b = EmailRecipient::fromString('user@example.com');
        self::assertTrue($a->equals($b));
    }

    public function test_email_recipients_with_different_addresses_are_not_equal(): void
    {
        $a = EmailRecipient::fromString('a@example.com');
        $b = EmailRecipient::fromString('b@example.com');
        self::assertFalse($a->equals($b));
    }

    public function test_email_recipient_is_not_equal_to_other_recipient_type(): void
    {
        $email = EmailRecipient::fromString('user@example.com');
        $webhook = WebhookRecipient::fromDestinationId('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d');
        self::assertFalse($email->equals($webhook));
    }

    // ---------------------------------------------------------------------------
    // WebhookRecipient
    // ---------------------------------------------------------------------------

    public function test_webhook_recipient_accepts_valid_uuid(): void
    {
        $uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $r = WebhookRecipient::fromDestinationId($uuid);
        self::assertSame($uuid, $r->destinationId());
        self::assertSame($uuid, $r->toString());
    }

    #[DataProvider('invalidUuidProvider')]
    public function test_webhook_recipient_rejects_invalid_uuid(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookRecipient::fromDestinationId($id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUuidProvider(): array
    {
        return [
            'empty string' => [''],
            'plain string' => ['not-a-uuid'],
            'uuid without dashes' => ['a1b2c3d4e5f64a7b8c9d0e1f2a3b4c5d'],
            'too short' => ['a1b2c3d4-e5f6-4a7b'],
            'wrong variant byte' => ['a1b2c3d4-e5f6-4a7b-0c9d-0e1f2a3b4c5d'],
        ];
    }

    public function test_webhook_recipients_with_same_id_are_equal(): void
    {
        $id = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        self::assertTrue(
            WebhookRecipient::fromDestinationId($id)->equals(
                WebhookRecipient::fromDestinationId($id)
            )
        );
    }

    public function test_webhook_recipients_with_different_ids_are_not_equal(): void
    {
        $a = WebhookRecipient::fromDestinationId('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d');
        $b = WebhookRecipient::fromDestinationId('b1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d');
        self::assertFalse($a->equals($b));
    }

    // ---------------------------------------------------------------------------
    // SmsRecipient
    // ---------------------------------------------------------------------------

    #[DataProvider('validE164Provider')]
    public function test_sms_recipient_accepts_valid_e164(string $number): void
    {
        $r = SmsRecipient::fromE164($number);
        self::assertSame($number, $r->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validE164Provider(): array
    {
        return [
            'Serbia mobile' => ['+381641234567'],
            'US number' => ['+12125551234'],
            'UK number' => ['+447911123456'],
            'short number' => ['+1212'],
        ];
    }

    #[DataProvider('invalidE164Provider')]
    public function test_sms_recipient_rejects_invalid_e164(string $number): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmsRecipient::fromE164($number);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidE164Provider(): array
    {
        return [
            'no plus prefix' => ['381641234567'],
            'leading zero after plus' => ['+0381641234567'],
            'too long (16 digits)' => ['+1234567890123456'],
            'contains letters' => ['+1234abc5678'],
            'empty string' => [''],
            'just a plus' => ['+'],
        ];
    }

    public function test_sms_recipients_with_same_number_are_equal(): void
    {
        $a = SmsRecipient::fromE164('+381641234567');
        $b = SmsRecipient::fromE164('+381641234567');
        self::assertTrue($a->equals($b));
    }

    public function test_sms_recipients_with_different_numbers_are_not_equal(): void
    {
        $a = SmsRecipient::fromE164('+381641234567');
        $b = SmsRecipient::fromE164('+381641234568');
        self::assertFalse($a->equals($b));
    }
}
