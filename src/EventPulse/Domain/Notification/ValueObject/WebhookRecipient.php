<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;

/**
 * A reference to a registered WebhookDestination, carried by its UUID.
 *
 * The notification holds the destination *id*, not the URL. The URL (and the
 * signing secret) are resolved from the WebhookDestination aggregate at
 * dispatch time (domain.md §3.1, §3.2). This keeps the notification
 * aggregate free of a dependency on WebhookDestination state.
 */
final class WebhookRecipient extends Recipient
{
    private function __construct(
        private readonly string $destinationId,
    ) {}

    /**
     * @param string $destinationId UUID of the target WebhookDestination.
     */
    public static function fromDestinationId(string $destinationId): self
    {
        if (!self::isValidUuid($destinationId)) {
            throw new InvalidNotificationInputException(
                sprintf('"%s" is not a valid WebhookDestination id.', $destinationId)
            );
        }

        return new self($destinationId);
    }

    public function destinationId(): string
    {
        return $this->destinationId;
    }

    public function toString(): string
    {
        return $this->destinationId;
    }

    public function equals(Recipient $other): bool
    {
        return $other instanceof self && $this->destinationId === $other->destinationId;
    }

    private static function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
