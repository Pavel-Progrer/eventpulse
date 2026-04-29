<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Application port: resolve a `WebhookRecipient` (a destination id) into a
 * concrete `WebhookEndpoint` (URL today; URL + signing secret from Day 9).
 *
 * Why this port exists separately from `NotificationRepository`:
 *  destinations are a different aggregate (ADR-0002 §1) — they have their
 *  own identity, lifecycle, and access rules (active/disabled). Loading
 *  one is not the notification repository's concern, and bundling that
 *  responsibility there would create a cross-aggregate coupling the domain
 *  is explicitly trying to avoid.
 *
 * Why an Application port and not a Domain port:
 *  the resolver's *consumer* is `WebhookChannelDriver`, an infrastructure
 *  adapter that lives in the dispatch flow orchestrated by the
 *  application layer. A Domain port would be appropriate if a domain
 *  service needed to resolve URLs — none does. Recipients in the domain
 *  are by-id references; resolution to URL is a transport concern.
 *
 * Why an interface ahead of a real implementation:
 *  Day 5 ships the channel-strategy machinery; webhook destinations land
 *  on Day 9 with HMAC signing, encrypted secrets, and the operator
 *  endpoints to manage them. The interface is defined now so that
 *  `WebhookChannelDriver` can be written against its final shape; the
 *  Day 9 work substitutes a real `EloquentWebhookEndpointResolver` for
 *  the placeholder `UnconfiguredWebhookEndpointResolver` without the
 *  driver changing.
 *
 *  This is the same pattern ADR-0007 establishes for `SecretsProvider`:
 *  introduce the interface, ship a deliberately-limited default
 *  implementation, and replace it when the underlying capability is in
 *  scope. The cost is one extra file today; the saving is that Day 9
 *  doesn't touch the driver, the dispatcher, or any of the test scaffolding.
 *
 * Implementations MUST throw `WebhookEndpointResolutionException` when:
 *  - the destination id is unknown,
 *  - the destination has been disabled (specification §5.2.4),
 *  - the destination has been deleted between submission and dispatch
 *    (which the spec classifies as Unrecoverable — see §6.1).
 *
 * Other exceptions (database errors, etc.) propagate normally and are
 * treated as transient infrastructure failures by the worker.
 */
interface WebhookEndpointResolver
{
    /**
     * @throws WebhookEndpointResolutionException When the destination cannot be resolved.
     */
    public function resolve(WebhookRecipient $recipient): WebhookEndpoint;
}
