<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

/**
 * The resolved transport target for a webhook dispatch.
 *
 * `WebhookRecipient` carries a destination *id* (a UUID). That id is opaque
 * to the channel driver — the driver needs a URL it can POST to and, once
 * Day 9 lands HMAC signing, a secret to sign with. `WebhookEndpoint` is
 * the resolved form of that information, produced by
 * `WebhookEndpointResolver` and consumed by `WebhookChannelDriver`.
 *
 * Why a value object rather than a tuple of arguments:
 *  the resolver returns a single thing, not three. Day 9 will widen this
 *  with a signing secret and a "max body size" hint without changing
 *  the resolver's signature or the driver's call site — both stay
 *  `endpoint = $resolver->resolve($recipient); $driver->...($endpoint)`.
 *
 * Why a domain-style read-only VO rather than an array shape:
 *  static analysis. `array{url: string, secret: string}` works at the
 *  Psalm/PHPStan layer but reads as a poorly-typed string-keyed bag at
 *  the call site. A class with a named accessor is the same number of
 *  lines and self-documents what each piece of data means.
 *
 * The class lives in Application, not Domain, because it is a transport
 * concern: the domain has no opinion on URLs (it works with destination
 * ids). Putting it in Domain would invert the architecture.
 */
final readonly class WebhookEndpoint
{
    public function __construct(
        public string $url,
    ) {
        // Sanity check: the resolver is responsible for returning a usable
        // URL, but the driver depends on this contract being enforced at
        // construction so it doesn't have to defend itself against `""`.
        if ($url === '') {
            throw new \InvalidArgumentException(
                'WebhookEndpoint URL must not be empty.'
            );
        }

        // Domain rule (specification §3.1): webhook destinations must be
        // HTTPS. We validate the resolved URL here, not just at
        // destination-registration time, because a misconfigured resolver
        // (or a future caching layer that returns stale state) could
        // otherwise leak a downgraded URL into the dispatcher.
        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException(sprintf(
                'WebhookEndpoint URL must use https://; got "%s".',
                $url,
            ));
        }
    }
}
