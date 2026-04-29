<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Application\Notification\Channel\WebhookEndpoint;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Placeholder `WebhookEndpointResolver` that always fails resolution.
 *
 * Day 5 ships the channel-strategy machinery but webhook destinations as
 * an aggregate (with persistence, encrypted secrets, signing) land on
 * Day 9. Until then, webhook notifications cannot be dispatched in
 * production. This resolver makes that fact loud:
 *
 *  - it satisfies the interface so `WebhookChannelDriver` can be wired,
 *  - every dispatch attempt fails fast with `Unrecoverable` classification
 *    and a reason that names the resolver and points at Day 9,
 *  - the failure flows through the standard outcome → DLQ path with no
 *    special-casing required at the worker level.
 *
 * Day 9 substitutes `EloquentWebhookEndpointResolver` (or equivalent) at
 * the container binding; no consumer of the interface changes.
 *
 * This is the same shape as `NullDomainEventDispatcher`: a deliberately
 * limited default with a documented replacement plan, preferred over
 * conditional dispatching at the call site.
 */
final class UnconfiguredWebhookEndpointResolver implements WebhookEndpointResolver
{
    #[\Override]
    public function resolve(WebhookRecipient $recipient): WebhookEndpoint
    {
        throw WebhookEndpointResolutionException::notConfigured($recipient);
    }
}
