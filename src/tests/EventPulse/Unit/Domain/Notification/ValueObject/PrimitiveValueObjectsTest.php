<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyKey::class)]
#[CoversClass(CorrelationId::class)]
#[CoversClass(AttemptNumber::class)]
#[CoversClass(NotificationId::class)]
final class PrimitiveValueObjectsTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // IdempotencyKey
    // ---------------------------------------------------------------------------

    public function test_idempotency_key_accepts_valid_string(): void
    {
        $key = IdempotencyKey::fromString('order-123-retry-1');
        self::assertSame('order-123-retry-1', $key->toString());
        self::assertSame('order-123-retry-1', (string) $key);
    }

    public function test_idempotency_key_accepts_single_character(): void
    {
        $key = IdempotencyKey::fromString('x');
        self::assertSame('x', $key->toString());
    }

    public function test_idempotency_key_accepts_255_characters(): void
    {
        $value = str_repeat('a', 255);
        $key = IdempotencyKey::fromString($value);
        self::assertSame($value, $key->toString());
    }

    public function test_idempotency_key_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyKey::fromString('');
    }

    public function test_idempotency_key_rejects_256_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyKey::fromString(str_repeat('a', 256));
    }

    public function test_idempotency_key_rejects_space(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyKey::fromString('key with space');
    }

    public function test_idempotency_key_rejects_control_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdempotencyKey::fromString("key\twith\ttabs");
    }

    public function test_idempotency_key_equality(): void
    {
        $a = IdempotencyKey::fromString('same-key');
        $b = IdempotencyKey::fromString('same-key');
        $c = IdempotencyKey::fromString('different-key');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    // ---------------------------------------------------------------------------
    // CorrelationId
    // ---------------------------------------------------------------------------

    public function test_correlation_id_can_be_generated(): void
    {
        $id = CorrelationId::generate();
        self::assertNotEmpty($id->toString());
        self::assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $id->toString());
    }

    public function test_generated_correlation_ids_are_unique(): void
    {
        $a = CorrelationId::generate();
        $b = CorrelationId::generate();
        self::assertFalse($a->equals($b));
    }

    public function test_correlation_id_from_string_accepts_valid_value(): void
    {
        $id = CorrelationId::fromString('trace-abc-123');
        self::assertSame('trace-abc-123', $id->toString());
    }

    public function test_correlation_id_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CorrelationId::fromString('');
    }

    public function test_correlation_id_rejects_string_exceeding_128_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CorrelationId::fromString(str_repeat('a', 129));
    }

    public function test_correlation_id_equality(): void
    {
        $a = CorrelationId::fromString('corr-001');
        $b = CorrelationId::fromString('corr-001');
        $c = CorrelationId::fromString('corr-002');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    // ---------------------------------------------------------------------------
    // AttemptNumber
    // ---------------------------------------------------------------------------

    public function test_first_attempt_is_one(): void
    {
        self::assertSame(1, AttemptNumber::first()->toInt());
    }

    public function test_from_int_accepts_one(): void
    {
        self::assertSame(1, AttemptNumber::fromInt(1)->toInt());
    }

    public function test_from_int_accepts_large_numbers(): void
    {
        self::assertSame(100, AttemptNumber::fromInt(100)->toInt());
    }

    public function test_from_int_rejects_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttemptNumber::fromInt(0);
    }

    public function test_from_int_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttemptNumber::fromInt(-1);
    }

    public function test_next_increments_by_one(): void
    {
        $first = AttemptNumber::first();
        $second = $first->next();
        $third = $second->next();

        self::assertSame(1, $first->toInt());
        self::assertSame(2, $second->toInt());
        self::assertSame(3, $third->toInt());
    }

    public function test_next_returns_a_new_instance(): void
    {
        $first = AttemptNumber::first();
        $second = $first->next();

        self::assertSame(1, $first->toInt());
        self::assertSame(2, $second->toInt());
    }

    public function test_attempt_number_equality(): void
    {
        $a = AttemptNumber::fromInt(3);
        $b = AttemptNumber::fromInt(3);
        $c = AttemptNumber::fromInt(4);

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    // ---------------------------------------------------------------------------
    // NotificationId
    // ---------------------------------------------------------------------------

    public function test_notification_id_can_be_generated(): void
    {
        $id = NotificationId::generate();
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->toString()
        );
    }

    public function test_generated_notification_ids_are_unique(): void
    {
        $a = NotificationId::generate();
        $b = NotificationId::generate();
        self::assertFalse($a->equals($b));
    }

    public function test_notification_id_from_string_accepts_valid_uuid(): void
    {
        $uuid = '550e8400-e29b-4d00-a716-446655440000';
        $id = NotificationId::fromString($uuid);
        self::assertSame($uuid, $id->toString());
        self::assertSame($uuid, (string) $id);
    }

    public function test_notification_id_rejects_non_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationId::fromString('not-a-uuid');
    }

    public function test_notification_id_rejects_uuid_v1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationId::fromString('550e8400-e29b-1d00-a716-446655440000');
    }

    public function test_notification_id_equality(): void
    {
        $uuid = '550e8400-e29b-4d00-a716-446655440000';
        $a = NotificationId::fromString($uuid);
        $b = NotificationId::fromString($uuid);
        $c = NotificationId::generate();

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
