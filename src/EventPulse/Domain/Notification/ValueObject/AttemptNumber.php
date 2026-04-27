<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;

/**
 * An integer ≥ 1 representing the ordinal of a dispatch attempt
 * (domain.md §7, invariant 5.1.2).
 *
 * Attempt numbers are contiguous and start at 1. The aggregate is the
 * authority for deriving the next attempt number; this class only ensures
 * that whatever is stored is a valid attempt number.
 */
final class AttemptNumber
{
    private function __construct(
        private readonly int $value,
    ) {}

    public static function first(): self
    {
        return new self(1);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidNotificationInputException(
                sprintf('AttemptNumber must be ≥ 1; got %d.', $value)
            );
        }

        return new self($value);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}