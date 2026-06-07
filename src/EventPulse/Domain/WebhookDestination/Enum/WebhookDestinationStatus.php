<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Enum;

/**
 * Lifecycle states of a `WebhookDestination`.
 *
 * A destination starts `active` and may only transition to `disabled`.
 * Disabling is not deletion: the destination row and its history persist
 * so that existing notifications that reference it can be inspected.
 *
 * `disabled` is terminal — there is no "re-enable" operation in Phase 1.
 * This keeps the state machine trivial and matches the specification §5.2.4:
 * "Disabled destinations cannot be used for new notifications." Re-enabling
 * is deferred because it requires deciding whether in-flight notifications
 * against a destination that was disabled-then-re-enabled should proceed,
 * which is a product question not answered by the current spec.
 */
enum WebhookDestinationStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }

    /**
     * The only allowed transition: `active → disabled`.
     */
    public function canDisable(): bool
    {
        return $this === self::Active;
    }
}
