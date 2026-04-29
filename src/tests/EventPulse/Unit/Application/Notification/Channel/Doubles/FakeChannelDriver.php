<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel\Doubles;

use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Domain\Notification\Enum\Channel;

/**
 * Test double for `ChannelDriver`.
 *
 * Behaves like a minimal recording mock: each call to `dispatch()`
 * captures the request and returns a configurable outcome. Exists so
 * unit tests of `ChannelDispatcher` (and any future application service
 * that plumbs through the dispatcher) can verify routing without
 * pulling in `Mailer`, the HTTP client, or the SMS stub.
 *
 * Construction takes the channel because the dispatcher's selection
 * logic keys on `$driver->channel()` — tests covering "the right driver
 * was picked" need to instantiate one driver per channel and assert
 * which one ran.
 */
final class FakeChannelDriver implements ChannelDriver
{
    /** @var DispatchRequest[] */
    public array $receivedRequests = [];

    public function __construct(
        private readonly Channel $channel,
        private DispatchOutcome $outcomeToReturn,
    ) {}

    #[\Override]
    public function channel(): Channel
    {
        return $this->channel;
    }

    #[\Override]
    public function dispatch(DispatchRequest $request): DispatchOutcome
    {
        $this->receivedRequests[] = $request;

        return $this->outcomeToReturn;
    }

    public function willReturn(DispatchOutcome $outcome): void
    {
        $this->outcomeToReturn = $outcome;
    }
}
