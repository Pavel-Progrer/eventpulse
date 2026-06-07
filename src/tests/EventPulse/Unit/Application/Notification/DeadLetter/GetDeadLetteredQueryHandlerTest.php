<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\DeadLetter;

use DateTimeImmutable;
use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Notification\DeadLetter\Query\GetDeadLetteredQuery;
use EventPulse\Application\Notification\DeadLetter\Query\GetDeadLetteredQueryHandler;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the get-DLQ-detail use case.
 *
 * The handler exists primarily to enforce three pre-conditions before it
 * returns the aggregate the repository found:
 *   1. The notification exists.
 *   2. It belongs to the caller's tenant.
 *   3. It is in the dead_lettered status.
 *
 * Each of these failure modes throws the same exception by design:
 * leaking "this id exists but isn't yours" (or "exists but isn't
 * dead-lettered") would let an attacker probe the system. ADR-0006 §
 * "DLQ visibility is tenant-scoped" records the reasoning.
 *
 * The happy path returns the aggregate as the repository loaded it; the
 * handler does not transform or filter further.
 */
#[CoversClass(GetDeadLetteredQueryHandler::class)]
final class GetDeadLetteredQueryHandlerTest extends TestCase
{
    private InMemoryNotificationRepository $repository;

    private GetDeadLetteredQueryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryNotificationRepository;
        $this->handler = new GetDeadLetteredQueryHandler($this->repository);
    }

    #[Test]
    public function it_returns_the_aggregate_for_a_dead_lettered_notification_in_scope(): void
    {
        $notification = $this->makeDeadLetteredNotification(apiKeyId: 'key-a');
        $this->repository->save($notification);

        $result = ($this->handler)(new GetDeadLetteredQuery(
            notificationId: $notification->id()->toString(),
            apiKeyId: 'key-a',
        ));

        self::assertSame($notification, $result);
    }

    #[Test]
    public function it_throws_not_found_when_no_such_notification_exists(): void
    {
        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)(new GetDeadLetteredQuery(
            notificationId: NotificationId::generate()->toString(),
            apiKeyId: 'key-a',
        ));
    }

    #[Test]
    public function it_throws_not_found_for_a_cross_tenant_id(): void
    {
        $notification = $this->makeDeadLetteredNotification(apiKeyId: 'key-a');
        $this->repository->save($notification);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)(new GetDeadLetteredQuery(
            notificationId: $notification->id()->toString(),
            apiKeyId: 'key-b', // tenant B asking about A's row
        ));
    }

    #[Test]
    public function it_throws_not_found_for_a_non_dead_lettered_notification(): void
    {
        // A queued notification belonging to the caller — endpoint must
        // still 404 because this is the DLQ inspection endpoint, not the
        // general status endpoint.
        $queued = $this->makeQueuedNotification(apiKeyId: 'key-a');
        $this->repository->save($queued);

        $this->expectException(DeadLetteredNotificationNotFoundException::class);

        ($this->handler)(new GetDeadLetteredQuery(
            notificationId: $queued->id()->toString(),
            apiKeyId: 'key-a',
        ));
    }

    private function makeDeadLetteredNotification(string $apiKeyId): Notification
    {
        $notification = $this->makeQueuedNotification($apiKeyId);

        $now = new DateTimeImmutable('2026-04-27T10:00:00Z');

        // Walk the aggregate to dead-lettered: one attempt, one failure
        // with maxAttempts=1 → dead-letter.
        $notification->beginAttempt($now);
        $notification->recordFailure(
            classification: FailureClassification::Permanent,
            reason: 'destination rejected',
            maxAttempts: 1,
            now: $now->modify('+30 seconds'),
            retryAfter: $now->modify('+1 minute'),
        );

        // Drain pending events — the aggregate's tests cover them.
        $notification->pullPendingEvents();

        return $notification;
    }

    private function makeQueuedNotification(string $apiKeyId): Notification
    {
        return Notification::request(
            id: NotificationId::generate(),
            channel: Channel::Email,
            // Payload uses the email shape NotificationPayload validates:
            // a non-empty `subject` and at least one of `text` or `html`.
            // (Earlier draft used `body`, which the validator rejects —
            // that was the cause of three errors in the Day-8 first run.)
            recipient: EmailRecipient::fromString('alice@example.test'),
            rawPayload: ['subject' => 'Hi', 'text' => 'Hello.'],
            priority: Priority::Normal,
            idempotencyKey: IdempotencyKey::fromString('idem-getdlq-test-001'),
            apiKeyId: $apiKeyId,
            correlationId: CorrelationId::generate(),
            now: new DateTimeImmutable('2026-04-27T09:00:00Z'),
        );
    }
}
