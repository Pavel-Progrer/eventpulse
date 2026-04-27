<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\Priority;

/**
 * The input contract for `SubmitNotificationHandler`.
 *
 * A command DTO sits between the HTTP layer (Laravel `FormRequest`, fully
 * validated) and the application layer (which knows nothing of HTTP). It
 * exists for three concrete reasons (see ADR-0003):
 *
 *  1. The handler can be invoked from non-HTTP contexts (Artisan command,
 *     queue replay, integration test) without depending on Laravel.
 *  2. The handler's signature describes the use case in business terms
 *     (channel, recipient, payload, idempotency key) rather than HTTP terms
 *     (`$request->validated()`).
 *  3. The mapping from external API names (`body_text`, `body_html`) to
 *     domain names (`text`, `html`) happens once, at the boundary, and is
 *     visible in the command construction site.
 *
 * Validity expectations:
 *  - `channel` and `priority` are already domain enums (caller resolved them).
 *  - `recipient` is a raw string; conversion to `EmailRecipient | SmsRecipient
 *    | WebhookRecipient` happens in the handler so that a recipient/channel
 *    mismatch produces a domain exception rather than a controller-level one.
 *  - `payload` is the *domain-shaped* payload array (already mapped from
 *    `body_text` → `text` etc.); the handler hands it to
 *    `NotificationPayload::forChannel()` for the channel-aware shape check.
 *  - `idempotencyKey` is a non-empty string (form-validated upstream).
 *  - `correlationId` is null when the caller did not supply one; the handler
 *    generates a fresh `CorrelationId` in that case.
 *
 * Read-only by construction; safe to pass through layers without defensive
 * copies.
 */
final readonly class SubmitNotificationCommand
{
    /**
     * @param array<string, mixed> $payload Domain-shaped payload (mapped at the boundary).
     */
    public function __construct(
        public Channel $channel,
        public string $recipient,
        public array $payload,
        public Priority $priority,
        public string $idempotencyKey,
        public string $apiKeyId,
        public ?string $correlationId,
    ) {}
}
