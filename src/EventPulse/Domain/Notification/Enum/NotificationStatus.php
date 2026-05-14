<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Enum;

/**
 * The six states a Notification can occupy (domain.md §4).
 *
 * Terminal states are `dispatched` and `failed`. `dead_lettered` is NOT
 * terminal for the aggregate — the record persists and is queryable — but
 * no normal transitions leave it. Replay creates a new aggregate; it does
 * not transition this one.
 *
 * The `scheduled` sub-state is intentionally absent. domain.md §4 explains
 * why: an external observer has no meaningful use for the distinction between
 * "queued and will process immediately" vs "queued but not yet". Both are
 * represented as `queued` with an optional `scheduled_for` timestamp.
 */
enum NotificationStatus: string
{
    case Queued       = 'queued';
    case Processing   = 'processing';
    case Dispatched   = 'dispatched';
    case DeadLettered = 'dead_lettered';
    case Failed       = 'failed';

    /**
     * Terminal states will never transition to anything else (invariant 5.1.6).
     * The aggregate calls this before accepting any transition request.
     */
    public function isTerminal(): bool
    {
        return match($this) {
            self::Dispatched, self::Failed => true,
            default                        => false,
        };
    }

    /**
     * Whether the given transition-to status is a legal successor.
     * Encodes the state machine from domain.md §4 in one place so that
     * the aggregate does not scatter transition logic across methods.
     *
     * dead_lettered is excluded from the `to` side — dead-lettering is
     * performed through a dedicated method that also enforces "at least
     * one failed attempt" (invariant 5.1.5).
     */
    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Queued     => $next === self::Processing,
            self::Processing => in_array($next, [self::Dispatched, self::Queued, self::Failed], true),
            // Terminal states and dead_lettered allow no further transitions.
            default          => false,
        };
    }
}