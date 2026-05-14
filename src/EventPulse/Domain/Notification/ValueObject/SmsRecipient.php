<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;

/**
 * An E.164 phone number (e.g. +381641234567).
 *
 * Validated to the E.164 format: leading +, 1–3 digit country code, up to
 * 15 digits total. Full libphonenumber validation is infrastructure-level
 * and too heavy for the domain layer; E.164 format is the minimum contract.
 */
final class SmsRecipient extends Recipient
{
    private function __construct(
        private readonly string $phoneNumber,
    ) {}

    public static function fromE164(string $phoneNumber): self
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber)) {
            throw new InvalidNotificationInputException(
                sprintf('"%s" is not a valid E.164 phone number.', $phoneNumber)
            );
        }

        return new self($phoneNumber);
    }

    public function toString(): string
    {
        return $this->phoneNumber;
    }

    public function equals(Recipient $other): bool
    {
        return $other instanceof self && $this->phoneNumber === $other->phoneNumber;
    }
}
