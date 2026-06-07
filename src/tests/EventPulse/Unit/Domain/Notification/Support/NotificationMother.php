<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Support;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Object Mother for Notification aggregates in tests.
 *
 * Provides minimal-but-complete defaults for constructing Notification
 * instances in any state. Tests override only the values they care about.
 */
final class NotificationMother
{
    public static function id(): NotificationId
    {
        return NotificationId::generate();
    }

    public static function correlationId(): CorrelationId
    {
        return CorrelationId::generate();
    }

    public static function idempotencyKey(string $value = 'test-idempotency-key-001'): IdempotencyKey
    {
        return IdempotencyKey::fromString($value);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-04-21 10:00:00');
    }

    public static function later(int $secondsOffset = 60): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-04-21 10:%02d:00', $secondsOffset));
    }

    public static function emailNotification(
        ?NotificationId $id = null,
        Priority $priority = Priority::Normal,
        string $apiKeyId = 'api-key-uuid-0001',
    ): Notification {
        return Notification::request(
            id: $id ?? self::id(),
            channel: Channel::Email,
            recipient: EmailRecipient::fromString('test@example.com'),
            rawPayload: ['subject' => 'Hello', 'text' => 'World'],
            priority: $priority,
            idempotencyKey: self::idempotencyKey(),
            apiKeyId: $apiKeyId,
            correlationId: self::correlationId(),
            now: self::now(),
        );
    }

    public static function webhookNotification(
        ?NotificationId $id = null,
        string $apiKeyId = 'api-key-uuid-0001',
    ): Notification {
        return Notification::request(
            id: $id ?? self::id(),
            channel: Channel::Webhook,
            recipient: WebhookRecipient::fromDestinationId('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'),
            rawPayload: ['event' => 'order.created', 'order_id' => 42],
            priority: Priority::Normal,
            idempotencyKey: self::idempotencyKey(),
            apiKeyId: $apiKeyId,
            correlationId: self::correlationId(),
            now: self::now(),
        );
    }

    public static function smsNotification(?NotificationId $id = null): Notification
    {
        return Notification::request(
            id: $id ?? self::id(),
            channel: Channel::Sms,
            recipient: SmsRecipient::fromE164('+381641234567'),
            rawPayload: ['body' => 'Your code is 1234'],
            priority: Priority::High,
            idempotencyKey: self::idempotencyKey(),
            apiKeyId: 'api-key-uuid-0001',
            correlationId: self::correlationId(),
            now: self::now(),
        );
    }

    public static function processingNotification(): Notification
    {
        $n = self::emailNotification();
        $n->beginAttempt(self::now());
        $n->pullPendingEvents();

        return $n;
    }

    public static function deadLetteredNotification(int $maxAttempts = 3): Notification
    {
        $n = self::emailNotification();
        $retryAfter = self::later(30);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $n->beginAttempt(self::now());
            $n->recordFailure(
                FailureClassification::Transient,
                'Connection timeout',
                $maxAttempts,
                self::now(),
                $retryAfter,
            );
        }

        $n->pullPendingEvents();

        return $n;
    }
}
