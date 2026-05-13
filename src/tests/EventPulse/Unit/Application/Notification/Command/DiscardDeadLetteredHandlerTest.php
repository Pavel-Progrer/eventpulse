<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Application\Notification\Command;

use EventPulse\Application\Notification\Command\DiscardDeadLetteredCommand;
use EventPulse\Application\Notification\Command\DiscardDeadLetteredHandler;
use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `DiscardDeadLetteredHandler` marks a dead-lettered notification
 * as discarded via the repository port. It enforces tenant isolation and
 * status preconditions before writing, and the write itself is idempotent.
 *
 * This test class only became possible once the handler was refactored to call
 * `NotificationRepository::markDiscarded()` instead of reaching directly into
 * `EloquentDeadLetterMark`. With the port in place the handler runs here
 * without a database, Laravel container, or any Infrastructure dependency.
 */
final class DiscardDeadLetteredHandlerTest extends TestCase
{
    private const string MISSING_ID = 'a0000000-0000-4000-8000-000000000001';

    private InMemoryNotificationRepository $repository;
    private DiscardDeadLetteredHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository();
        $this->handler    = new DiscardDeadLetteredHandler(
            repository: $this->repository,
            clock:      new FixedClock(NotificationMother::now()),
        );
    }

    private function command(
        string $notificationId,
        string $apiKeyId = 'api-key-uuid-0001',
    ): DiscardDeadLetteredCommand {
        return new DiscardDeadLetteredCommand(
            notificationId: $notificationId,
            apiKeyId:       $apiKeyId,
        );
    }

    // =========================================================================
    // Guards
    // =========================================================================

    #[Test]
    public function throws_not_found_for_syntactically_invalid_id(): void
    {
        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command('not-a-uuid'));
    }

    #[Test]
    public function throws_not_found_when_notification_does_not_exist(): void
    {
        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command(self::MISSING_ID));
    }

    #[Test]
    public function throws_not_found_when_wrong_tenant(): void
    {
        $notification = NotificationMother::deadLetteredNotification();
        $this->repository->save($notification);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command(
            notificationId: $notification->id()->toString(),
            apiKeyId:       'different-api-key',
        ));
    }

    #[Test]
    public function throws_not_found_when_notification_is_not_dead_lettered(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command($notification->id()->toString()));
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    #[Test]
    public function marks_the_notification_as_discarded_via_the_repository(): void
    {
        $notification = NotificationMother::deadLetteredNotification();
        $this->repository->save($notification);

        ($this->handler)($this->command($notification->id()->toString()));

        self::assertTrue(
            $this->repository->wasDiscarded($notification->id()),
            'Expected the repository to record a markDiscarded() call for this id.',
        );
    }

    #[Test]
    public function discard_timestamp_matches_the_clock(): void
    {
        $notification = NotificationMother::deadLetteredNotification();
        $this->repository->save($notification);

        ($this->handler)($this->command($notification->id()->toString()));

        self::assertEquals(
            NotificationMother::now(),
            $this->repository->discardedAt($notification->id()),
        );
    }

    #[Test]
    public function discard_is_idempotent(): void
    {
        $notification = NotificationMother::deadLetteredNotification();
        $this->repository->save($notification);

        ($this->handler)($this->command($notification->id()->toString()));
        ($this->handler)($this->command($notification->id()->toString()));

        // Two calls — still just one discard record, no exception.
        self::assertTrue($this->repository->wasDiscarded($notification->id()));
    }
}
