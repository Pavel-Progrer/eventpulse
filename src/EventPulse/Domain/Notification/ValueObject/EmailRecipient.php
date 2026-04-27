<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;


/**
 * An email address.
 *
 * Validated with filter_var FILTER_VALIDATE_EMAIL — intentionally simple.
 * RFC 5321 is too permissive for practical use; this validator rejects the
 * edge cases that real SMTP infrastructure also rejects.
 */
final class EmailRecipient extends Recipient
{
    private function __construct(
        private readonly string $address,
    ) {}

    public static function fromString(string $address): self
    {
        $normalised = strtolower(trim($address));

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid email address.', $address)
            );
        }

        return new self($normalised);
    }

    public function toString(): string
    {
        return $this->address;
    }

    public function equals(Recipient $other): bool
    {
        return $other instanceof self && $this->address === $other->address;
    }
}
