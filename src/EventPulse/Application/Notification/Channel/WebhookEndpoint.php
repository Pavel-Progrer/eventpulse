<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

/**
 * The resolved transport target for a webhook dispatch.
 *
 * `WebhookRecipient` carries a destination *id* (a UUID). That id is opaque
 * to the channel driver — the driver needs a URL it can POST to and, from
 * Day 9, a signing secret to include in the `X-EventPulse-Signature` header.
 * `WebhookEndpoint` is the resolved form of that information, produced by
 * `WebhookEndpointResolver` and consumed by `WebhookChannelDriver`.
 *
 * Why a value object rather than a tuple of arguments:
 *  The resolver returns a single thing, not three. Widening to carry the
 *  signing secret here changes only the resolver and the driver — nothing
 *  in between needed updating. This is exactly the "Day 9 doesn't touch
 *  the dispatcher, the policy, or the test scaffolding" promise made in
 *  ADR-0004 §5.
 *
 * Why a domain-style read-only VO rather than an array shape:
 *  static analysis. `array{url: string, secret: ?string}` works at the
 *  Psalm/PHPStan layer but reads as a poorly-typed string-keyed bag at
 *  the call site. A class with named accessors is the same number of
 *  lines and self-documents what each piece of data means.
 *
 * Signing secret handling:
 *  The `$signingSecret` is the *decrypted* plaintext secret retrieved by
 *  `EloquentWebhookEndpointResolver`. It is held here only for the
 *  duration of one dispatch call — it is never logged, never serialised,
 *  and never stored beyond the in-memory lifetime of the DispatchRequest.
 *  The class is NOT `readonly` to avoid implicitly making the secret
 *  accessible via PHP's reflection-based var_dump/debug_backtrace paths
 *  in a way that would be surprising; explicit accessors control exposure.
 *
 * The class lives in Application, not Domain, because it is a transport
 * concern: the domain has no opinion on URLs or signing (it works with
 * destination ids).
 */
final class WebhookEndpoint
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $signingSecret = null,
    ) {
        if ($url === '') {
            throw new \InvalidArgumentException(
                'WebhookEndpoint URL must not be empty.'
            );
        }

        // Domain rule (specification §3.1 and domain.md §5.2.2): webhook
        // destinations must be HTTPS. We validate the resolved URL here —
        // not just at destination-registration time — because a misconfigured
        // resolver (or a stale caching layer) could otherwise deliver a
        // downgraded URL into the dispatcher.
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException(sprintf(
                'WebhookEndpoint URL must use https://; got "%s".',
                $url,
            ));
        }

        if ($signingSecret !== null && $signingSecret === '') {
            throw new \InvalidArgumentException(
                'WebhookEndpoint signing secret must not be an empty string; pass null to omit signing.'
            );
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * The decrypted HMAC signing secret, or null if signing is not configured.
     *
     * The driver uses this to compute `X-EventPulse-Signature`. When null,
     * the signature header is omitted and the receiver cannot verify origin.
     * This path exists only to support `InMemoryWebhookEndpointResolver` in
     * tests and the legacy `UnconfiguredWebhookEndpointResolver` stub.
     */
    public function signingSecret(): ?string
    {
        return $this->signingSecret;
    }

    public function hasSigning(): bool
    {
        return $this->signingSecret !== null;
    }
}
