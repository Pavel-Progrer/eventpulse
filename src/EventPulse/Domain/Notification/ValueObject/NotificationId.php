<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;
use EventPulse\Domain\Shared\UuidGenerator;

/**
 * The identity of a Notification aggregate (domain.md §3.1).
 *
 * UUID v4, validated on construction. Wrapped in a named class rather than
 * carried as a raw string so that callers cannot accidentally pass an
 * ApiKeyId or WebhookDestinationId where a NotificationId is expected —
 * a distinction that a plain string would not enforce.
 */
final class NotificationId
{
    private function __construct(
        private readonly string $value,
    ) {}

    public static function generate(): self
    {
        // Uses the platform's crypto-random source via the standard UUID v4
        // algorithm. Not framework-coupled: the format contract (RFC 4122 UUID)
        // is what matters, not which library produces it.
        return new self(UuidGenerator::generate());
    }

    public static function fromString(string $value): self
    {
        if (! self::isValidUuid($value)) {
            throw new InvalidNotificationInputException(
                sprintf('"%s" is not a valid NotificationId (expected UUID v4).', $value)
            );
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    // ---------------------------------------------------------------------------
    // Private helpers — kept here to avoid a framework dependency for UUID gen.
    // Replace with ramsey/uuid if you prefer; the interface above doesn't change.
    // ---------------------------------------------------------------------------

    private static function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
