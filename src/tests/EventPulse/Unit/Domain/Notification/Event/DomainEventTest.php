<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Event;

use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainEvent::class)]
final class DomainEventTest extends TestCase
{
    public function test_notification_requested_event_name_is_snake_case(): void
    {
        $n      = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        self::assertCount(1, $events);
        self::assertSame('notification_requested', $events[0]->eventName());
    }

    public function test_event_name_contains_only_lowercase_letters_and_underscores(): void
    {
        $n      = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        foreach ($events as $event) {
            self::assertMatchesRegularExpression('/^[a-z][a-z_]+[a-z]$/', $event->eventName());
        }
    }

    public function test_event_name_does_not_start_or_end_with_underscore(): void
    {
        $n      = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        foreach ($events as $event) {
            $name = $event->eventName();
            self::assertStringStartsNotWith('_', $name);
            self::assertStringEndsNotWith('_', $name);
        }
    }

    public function test_all_events_carry_occurred_at(): void
    {
        $n      = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        foreach ($events as $event) {
            self::assertNotNull($event->occurredAt());
        }
    }

    public function test_all_events_carry_correlation_id(): void
    {
        $n      = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        foreach ($events as $event) {
            self::assertNotNull($event->correlationId());
        }
    }

    public function test_dispatch_attempted_event_name(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertSame('notification_dispatch_attempted', $events[0]->eventName());
    }

    public function test_dispatched_event_name(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertSame('notification_dispatched', $events[0]->eventName());
    }

    public function test_dead_lettered_event_name(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());
        $n->pullPendingEvents();
        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            1,
            NotificationMother::now(),
            NotificationMother::later(30),
        );

        $eventNames = array_map(fn($e) => $e->eventName(), $n->pullPendingEvents());

        self::assertContains('notification_dead_lettered', $eventNames);
    }
}