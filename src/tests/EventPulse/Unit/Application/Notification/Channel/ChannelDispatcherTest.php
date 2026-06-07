<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\Exception\NoDriverForChannelException;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Tests\Unit\Application\Notification\Channel\Doubles\FakeChannelDriver;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: the dispatcher selects the right driver per channel,
 * forbids ambiguous registration, and refuses to start with any channel
 * unhandled. The constructor's invariants make misconfiguration a
 * boot-time error, not a runtime surprise.
 */
#[CoversClass(ChannelDispatcher::class)]
final class ChannelDispatcherTest extends TestCase
{
    #[Test]
    public function dispatcher_routes_to_the_driver_for_the_notifications_channel(): void
    {
        $email = new FakeChannelDriver(Channel::Email, DispatchOutcome::success());
        $webhook = new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success());
        $sms = new FakeChannelDriver(Channel::Sms, DispatchOutcome::success());

        $dispatcher = new ChannelDispatcher([$email, $webhook, $sms]);

        $notification = NotificationMother::emailNotification();
        $attempt = $notification->beginAttempt(NotificationMother::now());

        $outcome = $dispatcher->dispatch($notification, $attempt);

        self::assertTrue($outcome->succeeded);
        self::assertCount(1, $email->receivedRequests);
        self::assertCount(0, $webhook->receivedRequests);
        self::assertCount(0, $sms->receivedRequests);
    }

    #[Test]
    public function driver_for_returns_the_registered_driver_for_each_channel(): void
    {
        $email = new FakeChannelDriver(Channel::Email, DispatchOutcome::success());
        $webhook = new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success());
        $sms = new FakeChannelDriver(Channel::Sms, DispatchOutcome::success());

        $dispatcher = new ChannelDispatcher([$email, $webhook, $sms]);

        self::assertSame($email, $dispatcher->driverFor(Channel::Email));
        self::assertSame($webhook, $dispatcher->driverFor(Channel::Webhook));
        self::assertSame($sms, $dispatcher->driverFor(Channel::Sms));
    }

    #[Test]
    public function constructor_rejects_a_set_missing_a_channel(): void
    {
        // Why a boot-time failure: a missing driver discovered at the
        // moment of first dispatch is the kind of bug that ships to
        // production. The constructor's exhaustiveness check turns it
        // into a deployment-time failure instead.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No ChannelDriver registered for channel "sms"');

        new ChannelDispatcher([
            new FakeChannelDriver(Channel::Email, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success()),
        ]);
    }

    #[Test]
    public function constructor_rejects_two_drivers_for_the_same_channel(): void
    {
        // Why this matters: silent last-wins behaviour would let a
        // misconfigured wiring change the channel's driver without any
        // signal in the diff. Failing fast names both classes in the
        // exception message so the operator can find the duplicate.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two ChannelDrivers registered for channel "email"');

        new ChannelDispatcher([
            new FakeChannelDriver(Channel::Email, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Email, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Sms, DispatchOutcome::success()),
        ]);
    }

    #[Test]
    public function dispatch_returns_the_outcome_produced_by_the_driver(): void
    {
        $configuredOutcome = DispatchOutcome::failure(
            classification: FailureClassification::Transient,
            reason: 'simulated transport failure',
        );

        $email = new FakeChannelDriver(Channel::Email, $configuredOutcome);

        $dispatcher = new ChannelDispatcher([
            $email,
            new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Sms, DispatchOutcome::success()),
        ]);

        $notification = NotificationMother::emailNotification();
        $attempt = $notification->beginAttempt(NotificationMother::now());

        $outcome = $dispatcher->dispatch($notification, $attempt);

        self::assertSame($configuredOutcome, $outcome);
    }

    #[Test]
    public function dispatch_passes_a_correctly_projected_request_to_the_driver(): void
    {
        $email = new FakeChannelDriver(Channel::Email, DispatchOutcome::success());

        $dispatcher = new ChannelDispatcher([
            $email,
            new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success()),
            new FakeChannelDriver(Channel::Sms, DispatchOutcome::success()),
        ]);

        $notification = NotificationMother::emailNotification();
        $attempt = $notification->beginAttempt(NotificationMother::now());

        $dispatcher->dispatch($notification, $attempt);

        self::assertCount(1, $email->receivedRequests);
        $request = $email->receivedRequests[0];

        self::assertTrue($request->notificationId->equals($notification->id()));
        self::assertSame(Channel::Email, $request->channel);
        self::assertSame(1, $request->attemptNumber->toInt());
    }

    #[Test]
    public function no_driver_for_channel_exception_carries_the_channel(): void
    {
        // Defence-in-depth path: the constructor invariant prevents this
        // in production. The behaviour is still tested because the
        // dispatcher's `driverFor` is part of the public API and the
        // contract says "throws on missing driver."
        $reflection = new \ReflectionClass(ChannelDispatcher::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('driversByChannel');
        $property->setValue($instance, []);

        try {
            $instance->driverFor(Channel::Email);
            self::fail('Expected NoDriverForChannelException');
        } catch (NoDriverForChannelException $e) {
            self::assertSame(Channel::Email, $e->channel);
        }
    }
}
