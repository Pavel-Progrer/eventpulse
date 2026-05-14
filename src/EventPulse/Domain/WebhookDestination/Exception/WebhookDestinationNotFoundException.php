<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Exception;

use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Raised when a webhook destination cannot be found by id (or by
 * id + api_key_id when the tenant check fails).
 *
 * The HTTP layer renders this as 404 regardless of *why* the lookup
 * failed — unknown id and wrong-tenant are indistinguishable to the
 * caller to avoid information disclosure.
 */
final class WebhookDestinationNotFoundException extends \DomainException
{
    public static function forId(WebhookDestinationId $id): self
    {
        return new self(sprintf(
            'WebhookDestination %s not found.',
            $id->toString(),
        ));
    }
}
