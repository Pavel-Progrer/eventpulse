<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Enum;

/**
 * Dispatch priority, used to select which queue partition a notification
 * is placed on (domain.md §7).
 *
 * The integer weight is intentionally not exposed publicly — callers interact
 * with named priorities. If queue partition names ever diverge from priority
 * names, the mapping lives in the infrastructure layer, not here.
 */
enum Priority: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
}