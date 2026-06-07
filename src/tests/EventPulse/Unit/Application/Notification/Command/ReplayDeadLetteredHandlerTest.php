<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Application\Notification\Command;

use EventPulse\Application\Notification\Command\AlreadyReplayedException;
use EventPulse\Application\Notification\Command\ReplayDeadLetteredCommand;
use EventPulse\Application\Notification\Command\ReplayDeadLetteredHandler;
use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationDispatchQueue;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `ReplayDeadLetteredHandler` creates a new notification from a
 * dead-lettered original, marks the original as replayed, and enqueues
 * dispatch. Idempotency (same key → same replay), tenant isolation, and the
 * "already replayed with a different key" guard are all enforced before any
 * mutation occurs.
 *
 * Runs without the Laravel container — pure PHP.
 */
final class ReplayDeadLetteredHandlerTest extends TestCase
{
    // A valid UUID v4 that is guaranteed not to exist in an empty repository.
    private const string MISSING_ID = 'a0000000-0000-4000-8000-000000000001';

    private InMemoryNotificationRepository $repository;

    private InMemoryNotificationDispatchQueue $queue;

    private ReplayDeadLetteredHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository;
        $this->queue = new InMemoryNotificationDispatchQueue;
        $this->handler = new ReplayDeadLetteredHandler(
            repository: $this->repository,
            dispatchQueue: $this->queue,
            events: new NullDomainEventDispatcher,
            clock: new FixedClock(NotificationMother::now()),
        );
    }

    private function command(
        string $notificationId,
        string $apiKeyId = 'api-key-uuid-0001',
        string $idemKey = 'replay-idem-key-001',
    ): ReplayDeadLetteredCommand {
        return new ReplayDeadLetteredCommand(
            notificationId: $notificationId,
            apiKeyId: $apiKeyId,
            idempotencyKey: $idemKey,
            correlationId: null,
        );
    }

    // =========================================================================
    // Guard: not-found cases
    // =========================================================================

    #[Test]
    public function throws_not_found_when_notification_id_does_not_exist(): void
    {
        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command(self::MISSING_ID));
    }

    #[Test]
    public function throws_not_found_when_notification_id_is_syntactically_invalid(): void
    {
        // A non-UUID-v4 string is absorbed by the handler and converted to
        // DeadLetteredNotificationNotFoundException so the exception type
        // is consistent across all not-found cases.
        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command('not-a-uuid-at-all'));
    }

    #[Test]
    public function throws_not_found_when_notification_is_not_dead_lettered(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command($notification->id()->toString()));
    }

    #[Test]
    public function throws_not_found_when_wrong_tenant(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)($this->command(
            notificationId: $original->id()->toString(),
            apiKeyId: 'different-api-key',
        ));
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    #[Test]
    public function creates_new_notification_with_same_channel_and_recipient(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $replay = ($this->handler)($this->command($original->id()->toString()));

        self::assertFalse($original->id()->equals($replay->id()));
        self::assertSame($original->channel(), $replay->channel());
        self::assertSame($original->recipient()->toString(), $replay->recipient()->toString());
    }

    #[Test]
    public function replay_links_back_to_original_via_replay_of_id(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $replay = ($this->handler)($this->command($original->id()->toString()));

        self::assertNotNull($replay->replayOf());
        self::assertTrue($original->id()->equals($replay->replayOf()));
    }

    #[Test]
    public function replay_starts_in_queued_state(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $replay = ($this->handler)($this->command($original->id()->toString()));

        self::assertSame(NotificationStatus::Queued, $replay->status());
    }

    #[Test]
    public function enqueues_dispatch_for_the_new_notification(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $replay = ($this->handler)($this->command($original->id()->toString()));
        $enqueued = $this->queue->enqueued();

        self::assertCount(1, $enqueued);
        self::assertTrue($replay->id()->equals($enqueued[0]->notificationId));
    }

    #[Test]
    public function stamps_the_original_dead_letter_mark_with_replay_id(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $replay = ($this->handler)($this->command($original->id()->toString()));
        $savedOriginal = $this->repository->findById($original->id());

        self::assertNotNull($savedOriginal);
        self::assertNotNull($savedOriginal->deadLetterMark());
        self::assertTrue($savedOriginal->deadLetterMark()->wasReplayed());
        self::assertTrue($replay->id()->equals($savedOriginal->deadLetterMark()->replayNotificationId()));
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    #[Test]
    public function idempotent_with_same_key_returns_same_notification(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        $first = ($this->handler)($this->command($original->id()->toString(), idemKey: 'fixed-key'));
        $second = ($this->handler)($this->command($original->id()->toString(), idemKey: 'fixed-key'));

        // Both calls return the same replay notification — no second aggregate created.
        self::assertTrue($first->id()->equals($second->id()));
    }

    #[Test]
    public function idempotent_replay_does_not_enqueue_a_second_dispatch(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        ($this->handler)($this->command($original->id()->toString(), idemKey: 'fixed-key'));
        ($this->handler)($this->command($original->id()->toString(), idemKey: 'fixed-key'));

        // Dispatch is enqueued exactly once, not twice.
        self::assertCount(1, $this->queue->enqueued());
    }

    #[Test]
    public function throws_already_replayed_when_different_key_used_after_first_replay(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $this->repository->save($original);

        ($this->handler)($this->command($original->id()->toString(), idemKey: 'first-key'));

        $this->expectException(AlreadyReplayedException::class);

        ($this->handler)($this->command($original->id()->toString(), idemKey: 'second-key'));
    }
}
