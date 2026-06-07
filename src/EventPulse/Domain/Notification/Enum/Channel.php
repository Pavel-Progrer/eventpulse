<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Enum;

/**
 * The delivery mechanism through which a notification is dispatched.
 *
 * Cases are derived from the domain ubiquitous language (domain.md §2, §4).
 * String backing values are lowercase and match the externally visible API
 * representation — this is intentional: the enum is used both as a domain
 * concept and as the canonical serialisation form, avoiding a separate
 * mapping layer for something that will never diverge.
 *
 * If a new channel is added it must be listed here first; the application
 * and infrastructure layers follow this enum, not the other way around.
 */
enum Channel: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Sms = 'sms';

    /**
     * Human-readable label, kept close to the enum so display concerns
     * don't leak into callers.
     */
    public function label(): string
    {
        return match ($this) {
            Channel::Email => 'Email',
            Channel::Webhook => 'Webhook',
            Channel::Sms => 'SMS',
        };
    }
}
