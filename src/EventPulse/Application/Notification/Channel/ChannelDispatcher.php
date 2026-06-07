<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\Exception\NoDriverForChannelException;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Entity\Attempt;
use EventPulse\Domain\Notification\Enum\Channel;

/**
 * Selects the right `ChannelDriver` for a notification's channel and runs it.
 *
 * The dispatcher is the single seam between the application layer (which
 * orchestrates persistence, attempts, and event release) and the
 * infrastructure layer (which talks to external systems). It contains zero
 * channel-specific logic — the only branching is "look up driver by channel,
 * delegate to it." Adding a new channel becomes:
 *
 *   1. Add a case to `Channel` (Domain).
 *   2. Implement `ChannelDriver` for it (Infrastructure).
 *   3. Register it in the service provider's tagged-iterable wiring.
 *
 * No file in the application layer changes; no central registry or switch
 * statement grows. That property is exactly what the strategy pattern is
 * here to preserve.
 *
 * Why an array indexed by `Channel->value` rather than `instanceof` checks:
 *  the enum's `value` is the natural primary key for a driver, and the
 *  indexed lookup is O(1). It also makes the boot-time invariant — "every
 *  channel has exactly one driver" — a single linear scan over `cases()`,
 *  which is what we do in the constructor.
 *
 * Why a single concrete class rather than `interface ChannelDispatcher`
 * with a Laravel-flavoured implementation:
 *  the dispatcher has no I/O of its own. There is no second implementation
 *  worth having; the only thing that varies between deployments is the
 *  *set of drivers*, which is exactly what the constructor parameter
 *  controls. Introducing an interface for the dispatcher itself would be
 *  defensive abstraction without a beneficiary.
 */
final class ChannelDispatcher
{
    /** @var array<string, ChannelDriver> indexed by `Channel->value`. */
    private readonly array $driversByChannel;

    /**
     * @param  iterable<ChannelDriver>  $drivers
     *
     * The constructor accepts an iterable so the service provider can pass
     * a tagged-resolved set without forcing a specific collection type.
     * We resolve to an indexed map once, eagerly, and validate
     * completeness here — not lazily inside `dispatch()` — so
     * misconfiguration surfaces at boot rather than at the first dispatch
     * of a particular channel.
     */
    public function __construct(iterable $drivers)
    {
        $byChannel = [];

        foreach ($drivers as $driver) {
            $key = $driver->channel()->value;

            if (isset($byChannel[$key])) {
                throw new \LogicException(sprintf(
                    'Two ChannelDrivers registered for channel "%s": %s and %s. '
                    .'Each channel must have exactly one driver.',
                    $key,
                    $byChannel[$key]::class,
                    $driver::class,
                ));
            }

            $byChannel[$key] = $driver;
        }

        // Exhaustiveness: every `Channel` case must have a driver. We check
        // this at construction so a missing driver is a startup failure,
        // not a 500 at the moment a customer first uses that channel —
        // the latter is the kind of bug nobody sees until production.
        foreach (Channel::cases() as $channel) {
            if (! isset($byChannel[$channel->value])) {
                throw new \LogicException(sprintf(
                    'No ChannelDriver registered for channel "%s". '
                    .'Register an implementation in EventPulseServiceProvider.',
                    $channel->value,
                ));
            }
        }

        $this->driversByChannel = $byChannel;
    }

    /**
     * Dispatch the notification's in-progress attempt through the
     * appropriate channel driver.
     *
     * The caller (`DispatchNotificationJob`) has already begun an attempt
     * on the aggregate (`Notification::beginAttempt()`) before calling
     * this — the dispatcher does not own the lifecycle, only the I/O step.
     */
    public function dispatch(Notification $notification, Attempt $attempt): DispatchOutcome
    {
        $driver = $this->driverFor($notification->channel());
        $request = DispatchRequest::from($notification, $attempt);

        return $driver->dispatch($request);
    }

    /**
     * Public so tests can assert "channel X resolves to driver class Y"
     * directly. Production callers should always go through `dispatch()`.
     *
     * The fallback `?? throw` is unreachable in normal flow (the
     * constructor validates exhaustiveness); it exists as belt-and-braces
     * for the test-time partial-set case and gives a clear error if a
     * future code path bypasses the constructor invariant.
     */
    public function driverFor(Channel $channel): ChannelDriver
    {
        return $this->driversByChannel[$channel->value]
            ?? throw new NoDriverForChannelException($channel);
    }
}
