<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Command;

use EventPulse\Application\Notification\Command\SubmitNotificationCommand;
use EventPulse\Application\Notification\Command\SubmitNotificationHandler;
use EventPulse\Application\Notification\Command\SubmitNotificationResult;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `SubmitNotificationHandler` accepts a command DTO, constructs a
 * Notification aggregate via the domain factory, persists it through the
 * repository abstraction, and returns a thin acceptance receipt to the caller.
 *
 * Tests live in the pure-PHPUnit layer (`tests/EventPulse/Unit/...`) — no
 * Laravel bootstrap, no database. The handler is exercised against
 * `InMemoryNotificationRepository` and `FixedClock`, both real implementations
 * of the relevant interfaces (no mocking framework is involved; mocking
 * domain ports buys nothing here and obscures intent).
 */
final class SubmitNotificationHandlerTest extends TestCase
{
    private const string API_KEY_ID = 'api-key-uuid-0001';
    private const string CLOCK_NOW  = '2026-04-22T10:15:30+00:00';

    private InMemoryNotificationRepository $repository;
    private FixedClock $clock;
    private SubmitNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository();
        $this->clock      = FixedClock::at(self::CLOCK_NOW);
        $this->handler    = new SubmitNotificationHandler($this->repository, $this->clock);
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
        $command = $this->webhookCommand();

        $result = ($this->handler)($command);

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
        $command = $this->smsCommand();

        $result = ($this->handler)($command);

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
        $first  = ($this->handler)($this->emailCommand(idempotencyKey: 'key-1'));
        $second = ($this->handler)($this->emailCommand(idempotencyKey: 'key-2'));

        self::assertFalse($first->id->equals($second->id));
    }

    // ---------------------------------------------------------------------------
    // Clock injection
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_uses_the_injected_clock_for_created_at(): void
    {
        $clock      = FixedClock::at('2030-01-01T00:00:00+00:00');
        $repository = new InMemoryNotificationRepository();
        $handler    = new SubmitNotificationHandler($repository, $clock);

        $result = $handler($this->emailCommand());

        self::assertSame('2030-01-01T00:00:00+00:00', $result->createdAt->format('c'));
    }

    // ---------------------------------------------------------------------------
    // Correlation id handling
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_preserves_a_caller_supplied_correlation_id(): void
    {
        $command = $this->emailCommand(correlationId: 'req_abcdef0123456789');

        $result = ($this->handler)($command);

        self::assertSame('req_abcdef0123456789', $result->correlationId->toString());
    }

    #[Test]
    public function it_generates_a_correlation_id_when_caller_omits_it(): void
    {
        $command = $this->emailCommand(correlationId: null);

        $result = ($this->handler)($command);

        // UUID v4 is the format generated by `CorrelationId::generate()`.
        // We do not assert the exact value (it is non-deterministic), only
        // that something usable was produced.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result->correlationId->toString(),
        );
    }

    // ---------------------------------------------------------------------------
    // Domain validation propagation
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_propagates_an_invalid_email_recipient_as_invalid_argument(): void
    {
        $command = new SubmitNotificationCommand(
            channel:        Channel::Email,
            recipient:      'not-an-email',
            payload:        ['subject' => 'Hi', 'text' => 'Body'],
            priority:       Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/email/i');

        ($this->handler)($command);
    }

    #[Test]
    public function it_propagates_an_invalid_webhook_destination_id(): void
    {
        $command = new SubmitNotificationCommand(
            channel:        Channel::Webhook,
            recipient:      'not-a-uuid',
            payload:        ['event' => 'foo'],
            priority:       Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
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
            channel:        Channel::Sms,
            recipient:      '+381641234567',
            payload:        [],
            priority:       Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/body/i');

        ($this->handler)($command);
    }

    #[Test]
    public function nothing_is_persisted_when_the_domain_rejects_the_command(): void
    {
        $command = new SubmitNotificationCommand(
            channel:        Channel::Email,
            recipient:      'not-an-email',
            payload:        ['subject' => 'x', 'text' => 'y'],
            priority:       Priority::Normal,
            idempotencyKey: 'idem-key-12345678',
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
        );

        try {
            ($this->handler)($command);
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, $this->repository->count());
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
        // aggregate — they were either dispatched or drained. (Day 4 wires
        // them into structured logging; Day 3 just defensively drains.)
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
            channel:        Channel::Email,
            recipient:      'user@example.com',
            payload:        ['subject' => 'Hello', 'text' => 'World'],
            priority:       Priority::Normal,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       self::API_KEY_ID,
            correlationId:  $correlationId,
        );
    }

    private function webhookCommand(
        string $idempotencyKey = 'idem-key-12345678',
    ): SubmitNotificationCommand {
        return new SubmitNotificationCommand(
            channel:        Channel::Webhook,
            recipient:      'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            payload:        ['event' => 'order.created', 'order_id' => 42],
            priority:       Priority::High,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
        );
    }

    private function smsCommand(
        string $idempotencyKey = 'idem-key-12345678',
    ): SubmitNotificationCommand {
        return new SubmitNotificationCommand(
            channel:        Channel::Sms,
            recipient:      '+381641234567',
            payload:        ['body' => 'Your code is 1234'],
            priority:       Priority::Normal,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       self::API_KEY_ID,
            correlationId:  null,
        );
    }
}
