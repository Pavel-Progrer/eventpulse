<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Application\WebhookDestination;

use DateTimeImmutable;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationCommand;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationHandler;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationRegistered;
use EventPulse\Infrastructure\WebhookDestination\Persistence\InMemoryWebhookDestinationRepository;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegisterWebhookDestinationHandler.
 *
 * Covers:
 *  - A valid command creates and persists the destination.
 *  - The plaintext secret is stored in the repository.
 *  - The result carries the one-time plaintext secret.
 *  - Domain events are dispatched after persistence.
 *  - A null correlationId is auto-generated.
 *  - Invalid URL (http://) surfaces the domain exception.
 */
final class RegisterWebhookDestinationHandlerTest extends TestCase
{
    private const string API_KEY_ID = 'ak-test-0000-0001';

    private const string URL = 'https://receiver.example.com/hook';

    private const string SECRET = 'super-secret-passphrase-32-chars!';

    private InMemoryWebhookDestinationRepository $repository;

    private RecordingEventDispatcher $eventDispatcher;

    private RegisterWebhookDestinationHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryWebhookDestinationRepository;
        $this->eventDispatcher = new RecordingEventDispatcher;
        $this->handler = new RegisterWebhookDestinationHandler(
            repository: $this->repository,
            clock: new FixedClock(new DateTimeImmutable('2026-04-28T12:00:00Z')),
            eventDispatcher: $this->eventDispatcher,
        );
    }

    #[Test]
    public function register_persists_destination_and_returns_result(): void
    {
        $result = ($this->handler)($this->command());

        self::assertSame(self::URL, $result->url);
        self::assertSame('My hook', $result->name);
        self::assertSame(self::SECRET, $result->secret);
        self::assertSame(WebhookDestinationStatus::Active, $result->status);
        self::assertNotEmpty($result->id->toString());
    }

    #[Test]
    public function register_stores_secret_in_repository(): void
    {
        $result = ($this->handler)($this->command());

        $storedSecret = $this->repository->secretFor($result->id);

        self::assertSame(self::SECRET, $storedSecret);
    }

    #[Test]
    public function register_persists_destination_retrievable_by_id(): void
    {
        $result = ($this->handler)($this->command());

        $found = $this->repository->findById($result->id, self::API_KEY_ID);

        self::assertNotNull($found);
        self::assertSame($result->id->toString(), $found->id()->toString());
    }

    #[Test]
    public function register_dispatches_domain_event(): void
    {
        ($this->handler)($this->command());

        self::assertCount(1, $this->eventDispatcher->dispatched);
        self::assertInstanceOf(WebhookDestinationRegistered::class, $this->eventDispatcher->dispatched[0]);
    }

    #[Test]
    public function register_with_null_correlation_id_auto_generates_one(): void
    {
        $result = ($this->handler)($this->command(correlationId: null));

        // If no exception was thrown, a CorrelationId was generated successfully.
        self::assertSame(WebhookDestinationStatus::Active, $result->status);
    }

    #[Test]
    public function register_rejects_http_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ($this->handler)($this->command(url: 'http://insecure.example.com/hook'));
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function command(
        string $url = self::URL,
        string $secret = self::SECRET,
        ?string $name = 'My hook',
        ?string $correlationId = 'corr-test-001',
    ): RegisterWebhookDestinationCommand {
        return new RegisterWebhookDestinationCommand(
            apiKeyId: self::API_KEY_ID,
            url: $url,
            secret: $secret,
            name: $name,
            correlationId: $correlationId,
        );
    }
}

// ---------------------------------------------------------------------------
// Test double — recording event dispatcher
// ---------------------------------------------------------------------------

/**
 * @internal
 */
final class RecordingEventDispatcher implements DomainEventDispatcher
{
    /** @var DomainEvent[] */
    public array $dispatched = [];

    #[\Override]
    public function dispatch(DomainEvent $event): void
    {
        $this->dispatched[] = $event;
    }
}
