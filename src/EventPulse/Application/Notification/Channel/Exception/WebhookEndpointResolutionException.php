<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel\Exception;

use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Raised by `WebhookEndpointResolver` when a destination id cannot be
 * resolved to a usable endpoint.
 *
 * Why this is a domain-aware exception, not a generic `RuntimeException`:
 *  the channel driver translates resolution failures directly into a
 *  `DispatchOutcome` carrying a `FailureClassification`. Centralising the
 *  classification here means there is one place that decides "missing
 *  destination = Unrecoverable; disabled destination = Permanent" — the
 *  driver simply forwards the value on to the outcome, and a future
 *  classification change is a one-file edit.
 *
 * The classifications follow specification §6.1:
 *  - `Unrecoverable`: the destination existed at submission time but is
 *    no longer there (deleted between submission and dispatch). The
 *    notification fails immediately, no retries — there is no plausible
 *    recovery from "the target no longer exists."
 *  - `Permanent`: the destination is registered but disabled. Retrying
 *    will not help unless an operator re-enables it; we treat that as the
 *    same class of failure as a 4xx from the destination itself.
 */
final class WebhookEndpointResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly WebhookRecipient $recipient,
        public readonly FailureClassification $classification,
        string $reason,
    ) {
        parent::__construct(sprintf(
            'Cannot resolve webhook destination %s: %s',
            $recipient->destinationId(),
            $reason,
        ));
    }

    public static function notFound(WebhookRecipient $recipient): self
    {
        return new self(
            recipient:      $recipient,
            classification: FailureClassification::Unrecoverable,
            reason:         'destination does not exist (it may have been deleted).',
        );
    }

    public static function disabled(WebhookRecipient $recipient): self
    {
        return new self(
            recipient:      $recipient,
            classification: FailureClassification::Permanent,
            reason:         'destination is disabled and not accepting deliveries.',
        );
    }

    public static function notConfigured(WebhookRecipient $recipient): self
    {
        return new self(
            recipient:      $recipient,
            classification: FailureClassification::Unrecoverable,
            reason:         'webhook endpoint resolution is not configured in this build '
                          . '(see UnconfiguredWebhookEndpointResolver). Day 9 enables this.',
        );
    }
}
