<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel\Exception;

use EventPulse\Domain\Notification\Enum\Channel;

/**
 * Raised when `ChannelDispatcher` is asked to dispatch through a channel
 * that has no registered driver.
 *
 * In normal operation this is unreachable: the dispatcher's constructor
 * validates at boot that every `Channel` case has exactly one driver. The
 * exception exists for defence-in-depth — and to give a clear, actionable
 * error if a future case is added but the service-provider registration
 * is bypassed (e.g. a partial driver set is constructed in a test that
 * deliberately supplies fewer than all drivers).
 */
final class NoDriverForChannelException extends \RuntimeException
{
    public function __construct(public readonly Channel $channel)
    {
        parent::__construct(sprintf(
            'No ChannelDriver registered for channel "%s". '
            .'Register an implementation in EventPulseServiceProvider::registerChannelDispatcher().',
            $channel->value,
        ));
    }
}
