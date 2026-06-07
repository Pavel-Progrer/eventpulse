<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Command;

use EventPulse\Application\Notification\Command\SubmitNotificationCommand;
use EventPulse\Application\Notification\Command\SubmitNotificationHandler;
use EventPulse\Application\Notification\Command\SubmitNotificationResult;
use EventPulse\Application\Notification\Exception\IdempotencyConflictException;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationDispatchQueue;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `SubmitNotificationHandler` accepts a command DTO, deduplicates
 * by `(api_key_id, idempotency_key)`, constructs a Notification aggregate
 * via the domain factory, persists it through the repository abstraction,
 * enqueues asynchronous dispatch through the queue port, and returns a thin
 * acceptance receipt to the caller.
 *
 * Day 4 expanded the original Day 3 surface with:
 *  - Idempotent replay (same body → 200, no second persistence, no second
 *    enqueue).
 *  - Idempotency conflict (same key, different body → 409 thrown).
 *  - Dispatch enqueue after successful persistence.
 *
 * Tests live in the pure-PHPUnit layer (`tests/EventPulse/Unit/...`) — no
 * Laravel bootstrap, no database, no Redis. The handler is exercised against
 * `InMemoryNotificationRepository`, `InMemoryNotificationDispatchQueue`, and
 * `FixedClock`, all real implementations of the relevant interfaces (no
 * mocking framework is involved).
 */
final class SubmitNotificationHandlerTest extends TestCase
{
    private const string API_KEY_ID = 'api-key-uuid-0001';

    private const string OTHER_API_KEY_ID = 'api-key-uuid-0002';

    private const string CLOCK_NOW = '2026-04-23T10:15:30+00:00';

    private InMemoryNotificationRepository $repository;

    private InMemoryNotificationDispatchQueue $dispatchQueue;

    private NullDomainEventDispatcher $events;

    private FixedClock $clock;

    private SubmitNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository;
        $this->dispatchQueue = new InMemoryNotificationDispatchQueue;
        $this->events = new NullDomainEventDispatcher;
        $this->clock = FixedClock::at(self::CLOCK_NOW);
        $this->handler = new SubmitNotificationHandler(
            $this->repository,
            $this->dispatchQueue,
            $this->events,
            $this->clock,
        );
    }

    // ---------------------------------------------------------------------------
    // Happy paths — one per channel
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_accepts_an_email_notification_and_returns_a_queued_receipt(): void
    {
        $command = $this->emailCommand();

        $result = ($this->handler)($command);

        self::assertInstanceOf(SubmitNotificationResult::class, $result);
        self::assertSame(NotificationStatus::Queued, $result->status);
        self::assertFalse($result->wasIdempotentReplay);
        self::assertSame(self::CLOCK_NOW, $result->createdAt->format('c'));
    }

    #[Test]
    public function it_accepts_a_webhook_notification(): void
    {
        $result = ($this->handler)($this->webhookCommand());

        self::assertSame(NotificationStatus::Queued, $result->status);
        self::assertSame(1, $this->repository->count());

        $persisted = $this->repository->all()[0];
        self::assertSame(Channel::Webhook, $persisted->channel());
        self::assertSame(
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            $persisted->recipient()->toString(),
        );
    }

    #[Test]
    public function it_accepts_an_sms_notification(): void
    {
        $result = ($this->handler)($this->smsCommand());

        self::assertSame(NotificationStatus::Queued, $result->status);

        $persisted = $this->repository->all()[0];
        self::assertSame(Channel::Sms, $persisted->channel());
        self::assertSame('+381641234567', $persisted->recipient()->toString());
    }

    // ---------------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_persists_the_aggregate_through_the_repository(): void
    {
        ($this->handler)($this->emailCommand());

        self::assertSame(1, $this->repository->count());
    }

    #[Test]
    public function it_returns_the_id_of_the_persisted_aggregate(): void
    {
        $result = ($this->handler)($this->emailCommand());

        $persisted = $this->repository->findById($result->id);

        self::assertNotNull($persisted);
        self::assertTrue($persisted->id()->equals($result->id));
    }

    #[Test]
    public function each_invocation_produces_a_unique_notification_id(): void
    {
        $first = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-key-aaaaa-001'));
        $second = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-key-bbbbb-002'));

        self::assertFalse($first->id->equals($second->id));
    }

    // ---------------------------------------------------------------------------
    // Dispatch enqueue
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_enqueues_a_dispatch_job_after_persistence(): void
    {
        ($this->handler)($this->emailCommand());

        self::assertSame(1, $this->dispatchQueue->count());
    }

    #[Test]
    public function the_enqueued_job_carries_the_persisted_notification_id(): void
    {
        $result = ($this->handler)($this->emailCommand());

        $enqueued = $this->dispatchQueue->lastEnqueued();
        self::assertNotNull($enqueued);
        self::assertTrue($enqueued->notificationId->equals($result->id));
    }

    #[Test]
    public function the_enqueued_job_carries_the_resulting_correlation_id(): void
    {
        $result = ($this->handler)($this->emailCommand(correlationId: 'req_abcdef0123456789'));

        $enqueued = $this->dispatchQueue->lastEnqueued();
        self::assertNotNull($enqueued);
        self::assertSame('req_abcdef0123456789', $enqueued->correlationId->toString());
    }

    #[Test]
    public function the_enqueued_job_carries_the_notifications_priority(): void
    {
        ($this->handler)($this->webhookCommand());

        $enqueued = $this->dispatchQueue->lastEnqueued();
        self::assertNotNull($enqueued);
        self::assertSame(Priority::High, $enqueued->priority);
    }

    // ---------------------------------------------------------------------------
    // Clock injection
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_uses_the_injected_clock_for_created_at(): void
    {
        $clock = FixedClock::at('2030-01-01T00:00:00+00:00');
        $repository = new InMemoryNotificationRepository;
        $dispatchQueue = new InMemoryNotificationDispatchQueue;
        $events = new NullDomainEventDispatcher;
        $handler = new SubmitNotificationHandler($repository, $dispatchQueue, $events, $clock);

        $result = $handler($this->emailCommand());

        self::assertSame('2030-01-01T00:00:00+00:00', $result->createdAt->format('c'));
    }

    // ---------------------------------------------------------------------------
    // Correlation id handling
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_preserves_a_caller_supplied_correlation_id(): void
    {
        $result = ($this->handler)($this->emailCommand(correlationId: 'req_abcdef0123456789'));

        self::assertSame('req_abcdef0123456789', $result->correlationId->toString());
    }

    #[Test]
    public function it_generates_a_correlation_id_when_caller_omits_it(): void
    {
        $result = ($this->handler)($this->emailCommand(correlationId: null));

        // UUID v4 is the format generated by `CorrelationId::generate()`. We
        // do not assert the exact value (it is non-deterministic), only that
        // something well-formed was produced.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result->correlationId->toString(),
        );
    }

    // ---------------------------------------------------------------------------
    // Idempotency — replay (same key, same body)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_returns_the_same_notification_id_on_idempotent_replay(): void
    {
        $first = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-001'));
        $second = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-001'));

        self::assertTrue($first->id->equals($second->id));
    }

    #[Test]
    public function the_replay_result_flags_was_idempotent_replay(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-002'));
        $second = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-002'));

        self::assertTrue($second->wasIdempotentReplay);
    }

    #[Test]
    public function the_first_submission_is_not_flagged_as_replay(): void
    {
        $first = ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-003'));

        self::assertFalse($first->wasIdempotentReplay);
    }

    #[Test]
    public function it_does_not_persist_a_second_aggregate_on_replay(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-004'));
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-004'));

        self::assertSame(
            1,
            $this->repository->count(),
            'A replay must not produce a second persisted notification.',
        );
    }

    #[Test]
    public function it_does_not_enqueue_a_second_dispatch_on_replay(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-005'));
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-replay-005'));

        self::assertSame(
            1,
            $this->dispatchQueue->count(),
            'A replay must not enqueue a second dispatch job.',
        );
    }

    #[Test]
    public function the_replay_returns_the_original_correlation_id_not_a_new_one(): void
    {
        $first = ($this->handler)($this->emailCommand(
            idempotencyKey: 'idem-replay-006',
            correlationId: 'req_first_request_111',
        ));

        // Caller submits a *different* correlation id on the replay. The
        // aggregate's stored correlation id must be returned, not the new
        // one — the tracing identity belongs to the original submission.
        $second = ($this->handler)($this->emailCommand(
            idempotencyKey: 'idem-replay-006',
            correlationId: 'req_second_request_222',
        ));

        self::assertTrue($first->correlationId->equals($second->correlationId));
        self::assertSame('req_first_request_111', $second->correlationId->toString());
    }

    // ---------------------------------------------------------------------------
    // Idempotency — conflict (same key, different body)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_throws_idempotency_conflict_when_payload_differs(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-001'));

        $different = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Different subject', 'text' => 'Different body'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-conflict-001',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(IdempotencyConflictException::class);

        ($this->handler)($different);
    }

    #[Test]
    public function it_throws_idempotency_conflict_when_recipient_differs(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-002'));

        $different = $this->emailCommand(idempotencyKey: 'idem-conflict-002');
        $different = new SubmitNotificationCommand(
            channel: $different->channel,
            recipient: 'someone-else@example.com',
            payload: $different->payload,
            priority: $different->priority,
            idempotencyKey: $different->idempotencyKey,
            apiKeyId: $different->apiKeyId,
            correlationId: $different->correlationId,
        );

        $this->expectException(IdempotencyConflictException::class);

        ($this->handler)($different);
    }

    #[Test]
    public function it_throws_idempotency_conflict_when_channel_differs(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-003'));

        $sms = new SubmitNotificationCommand(
            channel: Channel::Sms,
            recipient: '+381641234567',
            payload: ['body' => 'A text'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-conflict-003',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(IdempotencyConflictException::class);

        ($this->handler)($sms);
    }

    #[Test]
    public function it_throws_idempotency_conflict_when_priority_differs(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-004'));

        $highPriority = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Hello', 'text' => 'World'],
            priority: Priority::High,
            idempotencyKey: 'idem-conflict-004',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(IdempotencyConflictException::class);

        ($this->handler)($highPriority);
    }

    #[Test]
    public function a_conflict_does_not_persist_or_enqueue_anything_extra(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-005'));

        $different = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Different', 'text' => 'Body'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-conflict-005',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        try {
            ($this->handler)($different);
            self::fail('Expected IdempotencyConflictException was not thrown.');
        } catch (IdempotencyConflictException) {
            // expected
        }

        self::assertSame(1, $this->repository->count());
        self::assertSame(1, $this->dispatchQueue->count());
    }

    #[Test]
    public function the_conflict_exception_carries_the_offending_idempotency_key(): void
    {
        ($this->handler)($this->emailCommand(idempotencyKey: 'idem-conflict-006'));

        $different = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Different', 'text' => 'Body'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-conflict-006',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        try {
            ($this->handler)($different);
            self::fail('Expected IdempotencyConflictException was not thrown.');
        } catch (IdempotencyConflictException $e) {
            self::assertSame('idem-conflict-006', $e->idempotencyKey()->toString());
            self::assertSame(self::API_KEY_ID, $e->apiKeyId());
        }
    }

    // ---------------------------------------------------------------------------
    // Idempotency — scoping (key+api_key_id is the dedup tuple)
    // ---------------------------------------------------------------------------

    #[Test]
    public function the_same_key_under_different_api_keys_does_not_collide(): void
    {
        $firstApiKey = ($this->handler)(new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Hello', 'text' => 'World'],
            priority: Priority::Normal,
            idempotencyKey: 'shared-key-across-tenants',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        ));

        $secondApiKey = ($this->handler)(new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Hello', 'text' => 'World'],
            priority: Priority::Normal,
            idempotencyKey: 'shared-key-across-tenants',
            apiKeyId: self::OTHER_API_KEY_ID,
            correlationId: null,
        ));

        self::assertFalse(
            $firstApiKey->id->equals($secondApiKey->id),
            'Two different api_key_ids using the same idempotency key must produce two notifications.',
        );
        self::assertFalse($firstApiKey->wasIdempotentReplay);
        self::assertFalse($secondApiKey->wasIdempotentReplay);
        self::assertSame(2, $this->repository->count());
        self::assertSame(2, $this->dispatchQueue->count());
    }

    // ---------------------------------------------------------------------------
    // Domain validation propagation (Day 3 behaviour, retained)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_propagates_an_invalid_email_recipient_as_invalid_argument(): void
    {
        $command = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'not-an-email',
            payload: ['subject' => 'Hi', 'text' => 'Body'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/email/i');

        ($this->handler)($command);
    }

    #[Test]
    public function it_propagates_an_invalid_webhook_destination_id(): void
    {
        $command = new SubmitNotificationCommand(
            channel: Channel::Webhook,
            recipient: 'not-a-uuid',
            payload: ['event' => 'foo'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(InvalidArgumentException::class);

        ($this->handler)($command);
    }

    #[Test]
    public function it_propagates_an_invalid_payload_shape_from_the_domain(): void
    {
        // SMS payload missing `body` — the FormRequest would normally catch
        // this, but the handler must not silently accept it if a non-HTTP
        // caller (Artisan, queue replay) bypasses that check.
        $command = new SubmitNotificationCommand(
            channel: Channel::Sms,
            recipient: '+381641234567',
            payload: [],
            priority: Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/body/i');

        ($this->handler)($command);
    }

    #[Test]
    public function nothing_is_persisted_or_enqueued_when_the_domain_rejects_the_command(): void
    {
        $command = new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'not-an-email',
            payload: ['subject' => 'x', 'text' => 'y'],
            priority: Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );

        try {
            ($this->handler)($command);
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, $this->repository->count());
        self::assertSame(0, $this->dispatchQueue->count());
    }

    // ---------------------------------------------------------------------------
    // Domain event lifecycle
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_drains_pending_domain_events_after_persistence(): void
    {
        ($this->handler)($this->emailCommand());

        $persisted = $this->repository->all()[0];

        // After the handler returns, no domain events should remain on the
        // aggregate — they were drained. Day 8 wires draining into the
        // structured-logger / event-bus bridge; today the call exists so the
        // aggregate does not retain events past the handler boundary.
        self::assertSame([], $persisted->pullPendingEvents());
    }

    // ---------------------------------------------------------------------------
    // Test factories
    // ---------------------------------------------------------------------------

    private function emailCommand(
        string $idempotencyKey = 'idem-key-12345678',
        ?string $correlationId = null,
    ): SubmitNotificationCommand {
        return new SubmitNotificationCommand(
            channel: Channel::Email,
            recipient: 'user@example.com',
            payload: ['subject' => 'Hello', 'text' => 'World'],
            priority: Priority::Normal,
            idempotencyKey: $idempotencyKey,
            apiKeyId: self::API_KEY_ID,
            correlationId: $correlationId,
        );
    }

    private function webhookCommand(
        string $idempotencyKey = 'idem-key-12345678',
    ): SubmitNotificationCommand {
        return new SubmitNotificationCommand(
            channel: Channel::Webhook,
            recipient: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            payload: ['event' => 'order.created', 'order_id' => 42],
            priority: Priority::High,
            idempotencyKey: $idempotencyKey,
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );
    }

    private function smsCommand(
        string $idempotencyKey = 'idem-key-12345678',
    ): SubmitNotificationCommand {
        return new SubmitNotificationCommand(
            channel: Channel::Sms,
            recipient: '+381641234567',
            payload: ['body' => 'Your code is 1234'],
            priority: Priority::Normal,
            idempotencyKey: $idempotencyKey,
            apiKeyId: self::API_KEY_ID,
            correlationId: null,
        );
    }
}
