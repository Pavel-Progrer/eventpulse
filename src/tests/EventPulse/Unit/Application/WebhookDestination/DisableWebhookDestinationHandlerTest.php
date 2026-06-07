<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Application\WebhookDestination;

use DateTimeImmutable;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\WebhookDestination\Command\DisableWebhookDestinationCommand;
use EventPulse\Application\WebhookDestination\Command\DisableWebhookDestinationHandler;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationCommand;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationHandler;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationResult;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationDisabled;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationAlreadyDisabledException;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationNotFoundException;
use EventPulse\Infrastructure\WebhookDestination\Persistence\InMemoryWebhookDestinationRepository;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DisableWebhookDestinationHandler.
 *
 * Covers:
 *  - Disabling an existing active destination → status becomes disabled.
 *  - A WebhookDestinationDisabled domain event is dispatched.
 *  - Disabling an unknown id throws WebhookDestinationNotFoundException.
 *  - Disabling a destination owned by a different api_key_id throws
 *    WebhookDestinationNotFoundException (no information disclosure).
 *  - Disabling an already-disabled destination throws
 *    WebhookDestinationAlreadyDisabledException.
 */
final class DisableWebhookDestinationHandlerTest extends TestCase
{
    private const string API_KEY_ID = 'ak-test-0000-0001';

    private const string OTHER_KEY_ID = 'ak-test-0000-0002';

    private const string URL = 'https://receiver.example.com/hook';

    private const string SECRET = 'super-secret-passphrase-32-chars!';

    private InMemoryWebhookDestinationRepository $repository;

    private SpyEventDispatcher $eventDispatcher;

    private DisableWebhookDestinationHandler $handler;

    private RegisterWebhookDestinationHandler $registerHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FixedClock(new DateTimeImmutable('2026-04-28T12:00:00Z'));
        $this->repository = new InMemoryWebhookDestinationRepository;
        $this->eventDispatcher = new SpyEventDispatcher;

        $this->handler = new DisableWebhookDestinationHandler(
            repository: $this->repository,
            clock: $clock,
            eventDispatcher: $this->eventDispatcher,
        );

        $this->registerHandler = new RegisterWebhookDestinationHandler(
            repository: $this->repository,
            clock: $clock,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    #[Test]
    public function disable_sets_destination_status_to_disabled(): void
    {
        $result = $this->registerDestination();

        $this->eventDispatcher->flush();

        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: $result->id->toString(),
            apiKeyId: self::API_KEY_ID,
            correlationId: 'corr-disable-001',
        ));

        $found = $this->repository->findById($result->id, self::API_KEY_ID);

        self::assertNotNull($found);
        self::assertSame(WebhookDestinationStatus::Disabled, $found->status());
        self::assertFalse($found->isActive());
    }

    #[Test]
    public function disable_dispatches_webhook_destination_disabled_event(): void
    {
        $result = $this->registerDestination();

        $this->eventDispatcher->flush();

        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: $result->id->toString(),
            apiKeyId: self::API_KEY_ID,
            correlationId: 'corr-disable-002',
        ));

        $events = $this->eventDispatcher->all();

        self::assertCount(1, $events);
        self::assertInstanceOf(WebhookDestinationDisabled::class, $events[0]);

        /** @var WebhookDestinationDisabled $event */
        $event = $events[0];
        self::assertSame($result->id->toString(), $event->destinationId()->toString());
        self::assertSame(self::API_KEY_ID, $event->apiKeyId());
    }

    #[Test]
    public function disable_throws_not_found_for_unknown_id(): void
    {
        $this->expectException(WebhookDestinationNotFoundException::class);

        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: '00000000-0000-4000-8000-000000000000',
            apiKeyId: self::API_KEY_ID,
            correlationId: 'corr-notfound',
        ));
    }

    #[Test]
    public function disable_throws_not_found_for_wrong_tenant(): void
    {
        // Register under API_KEY_ID.
        $result = $this->registerDestination();

        // Attempt to disable using OTHER_KEY_ID — must 404, not 403.
        $this->expectException(WebhookDestinationNotFoundException::class);

        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: $result->id->toString(),
            apiKeyId: self::OTHER_KEY_ID,
            correlationId: 'corr-wrong-tenant',
        ));
    }

    #[Test]
    public function disable_throws_already_disabled_when_destination_is_disabled(): void
    {
        $result = $this->registerDestination();

        // First disable.
        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: $result->id->toString(),
            apiKeyId: self::API_KEY_ID,
            correlationId: 'corr-first-disable',
        ));

        // Second disable.
        $this->expectException(WebhookDestinationAlreadyDisabledException::class);

        ($this->handler)(new DisableWebhookDestinationCommand(
            destinationId: $result->id->toString(),
            apiKeyId: self::API_KEY_ID,
            correlationId: 'corr-second-disable',
        ));
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function registerDestination(): RegisterWebhookDestinationResult
    {
        return ($this->registerHandler)(new RegisterWebhookDestinationCommand(
            apiKeyId: self::API_KEY_ID,
            url: self::URL,
            secret: self::SECRET,
            name: 'Test destination',
            correlationId: 'corr-register-001',
        ));
    }
}

// ---------------------------------------------------------------------------
// Test double — spy event dispatcher
// ---------------------------------------------------------------------------

/**
 * @internal
 */
final class SpyEventDispatcher implements DomainEventDispatcher
{
    /** @var DomainEvent[] */
    private array $events = [];

    #[\Override]
    public function dispatch(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return DomainEvent[] */
    public function all(): array
    {
        return $this->events;
    }

    public function flush(): void
    {
        $this->events = [];
    }
}
