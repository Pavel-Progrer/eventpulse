<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;

/**
 * A caller-supplied string that de-duplicates repeated submissions of the
 * same logical request (domain.md §2, invariant 5.1.8).
 *
 * Constraints:
 *  - 1–255 characters (empty string is meaningless; very long strings are
 *    infrastructure-hostile without being semantically richer).
 *  - Printable ASCII only. Unicode idempotency keys introduce normalisation
 *    ambiguities (NFC vs NFD) and are rejected to keep the equality contract
 *    unambiguous.
 *
 * The window and api_key_id scoping are enforced at the application layer;
 * this class only validates the key's form, not its uniqueness.
 */
final class IdempotencyKey
{
    private const MIN_LENGTH = 1;
    private const MAX_LENGTH = 255;

    private function __construct(
        private readonly string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidNotificationInputException(
                sprintf(
                    'IdempotencyKey must be between %d and %d characters; got %d.',
                    self::MIN_LENGTH,
                    self::MAX_LENGTH,
                    $length,
                )
            );
        }

        // Reject non-printable characters and non-ASCII bytes.
        if (!preg_match('/^[\x21-\x7E]+$/', $value)) {
            throw new InvalidNotificationInputException(
                'IdempotencyKey must contain only printable ASCII characters (0x21–0x7E).'
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
}