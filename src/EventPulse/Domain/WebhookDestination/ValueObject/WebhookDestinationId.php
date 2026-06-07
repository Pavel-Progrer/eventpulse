<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\ValueObject;

use Ramsey\Uuid\Uuid;

/**
 * Immutable identity of a `WebhookDestination` aggregate.
 *
 * Wraps a UUID string so that destination ids are distinct types from
 * notification ids, preventing accidental transposition at call sites.
 * The domain uses `WebhookDestinationId` for references; infrastructure
 * serialises to and from the raw string for persistence.
 */
final readonly class WebhookDestinationId
{
    private function __construct(
        private string $value,
    ) {
        if (! Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf(
                'WebhookDestinationId must be a valid UUID; got "%s".',
                $value,
            ));
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public static function fromString(string $value): self
    {
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
