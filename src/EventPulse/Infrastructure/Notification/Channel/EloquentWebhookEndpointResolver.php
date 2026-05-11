<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Application\Notification\Channel\WebhookEndpoint;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;
use EventPulse\Infrastructure\WebhookDestination\Persistence\EloquentWebhookDestination;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

/**
 * Resolves a `WebhookRecipient` (destination id) to a `WebhookEndpoint`
 * (URL + decrypted signing secret) by querying the `webhook_destinations` table.
 *
 * This class replaces `UnconfiguredWebhookEndpointResolver` in the Day 9
 * container binding. All consumers — `WebhookChannelDriver`, the dispatcher,
 * tests — are unchanged; only the binding in `EventPulseServiceProvider` swaps.
 *
 * Secret decryption:
 *  The `secret_encrypted` column stores an AES-256-CBC ciphertext (encrypted
 *  by `EloquentWebhookDestinationRepository::save()`). We decrypt it here
 *  using Laravel's `Encrypter` (the same app key used to encrypt it) and
 *  pass the plaintext directly into `WebhookEndpoint`. The plaintext is
 *  never stored, logged, or retained beyond the scope of one dispatch call.
 *
 * Why this resolver queries Eloquent directly rather than using the domain
 * repository:
 *  The `WebhookDestinationRepository` interface returns domain aggregates.
 *  A domain aggregate does not carry the signing secret (invariant §5.2.3).
 *  The resolver needs the secret to build a `WebhookEndpoint`, so it must
 *  reach into the persistence layer directly. This is an intentional,
 *  documented exception to the "infrastructure uses repository interfaces"
 *  rule: the resolver is infrastructure, reads one column from one row, and
 *  does not reconstitute an aggregate. The trade-off is accepted because the
 *  alternative (putting the secret on the aggregate) would violate §5.2.3.
 *
 * Tenant check:
 *  The resolver does NOT enforce api_key_id isolation — that invariant is
 *  checked at submission time (the notification's recipient id was validated
 *  against the caller's api_key_id when the notification was created).
 *  At dispatch time the notification already exists and its recipient id was
 *  already validated. Performing a redundant tenant check here would add
 *  a join without adding safety; the destination id is trusted at this point.
 *  If the destination was deleted or disabled after submission, the error is
 *  still classified correctly via `notFound()` or `disabled()`.
 */
final class EloquentWebhookEndpointResolver implements WebhookEndpointResolver
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function resolve(WebhookRecipient $recipient): WebhookEndpoint
    {
        $destinationId = $recipient->destinationId();

        // Validate the id is a UUID before querying — avoids a query with
        // a malformed id that could never match.
        try {
            WebhookDestinationId::fromString($destinationId);
        } catch (\InvalidArgumentException) {
            throw WebhookEndpointResolutionException::notFound($recipient);
        }

        /** @var EloquentWebhookDestination|null $model */
        $model = EloquentWebhookDestination::query()
            ->where('id', $destinationId)
            ->select(['id', 'url', 'status', 'secret_encrypted'])
            ->first();

        if ($model === null) {
            throw WebhookEndpointResolutionException::notFound($recipient);
        }

        if ($model->status !== 'active') {
            throw WebhookEndpointResolutionException::disabled($recipient);
        }

        try {
            $secret = $this->encrypter->decryptString($model->secret_encrypted);
        } catch (DecryptException $e) {
            // The secret is unreadable — most likely a key rotation that
            // didn't re-encrypt this row. Log at error so the operator has
            // a trail to diagnose the failure, then surface it as notFound()
            // so the dispatch job classifies it as Unrecoverable and stops
            // retrying (retrying cannot fix a decryption failure).
            $this->logger->error('webhook.destination.secret_unreadable', [
                'destination_id' => $destinationId,
                'reason'         => $e->getMessage(),
            ]);
            throw WebhookEndpointResolutionException::notFound($recipient);
        }

        return new WebhookEndpoint(
            url:           $model->url,
            signingSecret: $secret,
        );
    }
}
