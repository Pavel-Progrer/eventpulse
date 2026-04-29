<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `DispatchRequest::from()` projects an aggregate + attempt
 * into a focused, read-only DTO that drivers consume. The DTO carries
 * exactly the fields a driver needs and nothing more.
 */
#[CoversClass(DispatchRequest::class)]
final class DispatchRequestTest extends TestCase
{
    #[Test]
    public function from_aggregate_carries_identity_and_routing_data(): void
    {
        $notification = NotificationMother::emailNotification();
        $attempt      = $notification->beginAttempt(NotificationMother::now());

        $request = DispatchRequest::from($notification, $attempt);

        self::assertTrue($request->notificationId->equals($notification->id()));
        self::assertSame(Channel::Email, $request->channel);
        self::assertTrue($request->recipient->equals($notification->recipient()));
        self::assertTrue($request->payload->equals($notification->payload()));
        self::assertTrue($request->correlationId->equals($notification->correlationId()));
        self::assertSame(1, $request->attemptNumber->toInt());
    }

    #[Test]
    public function from_uses_the_attempt_number_supplied(): void
    {
        // Why a separate test for this: the driver needs the *current*
        // attempt's number on the wire (e.g. the X-EventPulse-Attempt
        // header), not the aggregate's count or any other approximation.
        //
        // NotificationMother::later($n) substitutes $n into the *minutes*
        // slot of "2026-04-21 10:%02d:00", so values must stay < 60 to
        // produce a valid DateTimeImmutable.
        $notification = NotificationMother::emailNotification();

        // First attempt: fail it so the second can begin.
        $first = $notification->beginAttempt(NotificationMother::now());
        $notification->recordFailure(
            classification: FailureClassification::Transient,
            reason:         'simulated transient failure',
            maxAttempts:    5,
            now:            NotificationMother::later(10),
            retryAfter:     NotificationMother::later(30),
        );

        $second = $notification->beginAttempt(NotificationMother::later(40));

        $firstRequest  = DispatchRequest::from($notification, $first);
        $secondRequest = DispatchRequest::from($notification, $second);

        self::assertSame(1, $firstRequest->attemptNumber->toInt());
        self::assertSame(2, $secondRequest->attemptNumber->toInt());
    }
}