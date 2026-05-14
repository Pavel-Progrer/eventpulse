<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Domain\WebhookDestination\Aggregate;

use DateTimeImmutable;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationDisabled;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationRegistered;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationAlreadyDisabledException;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WebhookDestination aggregate.
 *
 * Covers:
 *  - Construction invariants (URL must be https, name length limit)
 *  - Initial state after register()
 *  - Domain event emission (registration and disabling)
 *  - disable() lifecycle: active → disabled
 *  - disable() invariant: already-disabled throws
 *  - reconstitute() does not emit events
 */
final class WebhookDestinationTest extends TestCase
{
    private const string API_KEY_ID       = 'key-aaaaaa-bbbbbb';
    private const string VALID_URL        = 'https://hooks.example.com/notify';
    private const string DESTINATION_NAME = 'My webhook';

    // ---------------------------------------------------------------------------
    // register() — construction invariants
    // ---------------------------------------------------------------------------

    #[Test]
    public function register_creates_active_destination_with_correct_fields(): void
    {
        $id  = WebhookDestinationId::generate();
        $now = new DateTimeImmutable('2026-04-28T12:00:00Z');

        $destination = $this->register(id: $id, url: self::VALID_URL, name: self::DESTINATION_NAME, now: $now);

        self::assertSame($id->toString(), $destination->id()->toString());
        self::assertSame(self::API_KEY_ID, $destination->apiKeyId());
        self::assertSame(self::VALID_URL, $destination->url());
        self::assertSame(self::DESTINATION_NAME, $destination->name());
        self::assertSame(WebhookDestinationStatus::Active, $destination->status());
        self::assertTrue($destination->isActive());
        self::assertEquals($now, $destination->createdAt());
    }

    #[Test]
    public function register_accepts_null_name(): void
    {
        $destination = $this->register(name: null);

        self::assertNull($destination->name());
    }

    #[Test]
    public function register_rejects_http_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/https:\/\//');

        $this->register(url: 'http://hooks.example.com/notify');
    }

    #[Test]
    public function register_rejects_non_url_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->register(url: 'not-a-url-at-all');
    }

    #[Test]
    public function register_rejects_name_exceeding_128_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->register(name: str_repeat('a', 129));
    }

    // ---------------------------------------------------------------------------
    // register() — domain event
    // ---------------------------------------------------------------------------

    #[Test]
    public function register_emits_webhook_destination_registered_event(): void
    {
        $id          = WebhookDestinationId::generate();
        $now         = new DateTimeImmutable('2026-04-28T12:00:00Z');
        $destination = $this->register(id: $id, url: self::VALID_URL, name: self::DESTINATION_NAME, now: $now);

        $events = $destination->pullPendingEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(WebhookDestinationRegistered::class, $events[0]);

        /** @var WebhookDestinationRegistered $event */
        $event = $events[0];
        self::assertSame($id->toString(), $event->destinationId()->toString());
        self::assertSame(self::API_KEY_ID, $event->apiKeyId());
        self::assertSame(self::VALID_URL, $event->url());
        self::assertSame(self::DESTINATION_NAME, $event->name());
        self::assertEquals($now, $event->occurredAt());
    }

    #[Test]
    public function pull_pending_events_clears_the_queue(): void
    {
        $destination = $this->register();

        $destination->pullPendingEvents(); // First pull consumes the event.
        $second = $destination->pullPendingEvents();

        self::assertEmpty($second);
    }

    // ---------------------------------------------------------------------------
    // disable()
    // ---------------------------------------------------------------------------

    #[Test]
    public function disable_transitions_status_to_disabled(): void
    {
        $destination = $this->register();
        $destination->pullPendingEvents(); // Consume registration event.

        $destination->disable(new DateTimeImmutable(), CorrelationId::fromString('corr-1'));

        self::assertSame(WebhookDestinationStatus::Disabled, $destination->status());
        self::assertFalse($destination->isActive());
    }

    #[Test]
    public function disable_emits_webhook_destination_disabled_event(): void
    {
        $id          = WebhookDestinationId::generate();
        $destination = $this->register(id: $id);
        $destination->pullPendingEvents();

        $now  = new DateTimeImmutable('2026-04-28T14:00:00Z');
        $corr = CorrelationId::fromString('corr-disable-1');
        $destination->disable($now, $corr);

        $events = $destination->pullPendingEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(WebhookDestinationDisabled::class, $events[0]);

        /** @var WebhookDestinationDisabled $event */
        $event = $events[0];
        self::assertSame($id->toString(), $event->destinationId()->toString());
        self::assertSame(self::API_KEY_ID, $event->apiKeyId());
        self::assertEquals($now, $event->occurredAt());
        self::assertSame($corr->toString(), $event->correlationId()->toString());
    }

    #[Test]
    public function disable_throws_when_already_disabled(): void
    {
        $destination = $this->register();
        $destination->disable(new DateTimeImmutable(), CorrelationId::fromString('corr-1'));
        $destination->pullPendingEvents();

        $this->expectException(WebhookDestinationAlreadyDisabledException::class);

        $destination->disable(new DateTimeImmutable(), CorrelationId::fromString('corr-2'));
    }

    // ---------------------------------------------------------------------------
    // reconstitute()
    // ---------------------------------------------------------------------------

    #[Test]
    public function reconstitute_hydrates_state_without_raising_events(): void
    {
        $id  = WebhookDestinationId::generate();
        $now = new DateTimeImmutable('2026-04-28T10:00:00Z');

        $destination = WebhookDestination::reconstitute(
            id:        $id,
            apiKeyId:  self::API_KEY_ID,
            url:       self::VALID_URL,
            name:      'Reconstituted',
            status:    WebhookDestinationStatus::Disabled,
            createdAt: $now,
        );

        self::assertSame($id->toString(), $destination->id()->toString());
        self::assertSame(WebhookDestinationStatus::Disabled, $destination->status());
        self::assertEmpty($destination->pullPendingEvents());
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function register(
        ?WebhookDestinationId $id   = null,
        string $url                  = self::VALID_URL,
        ?string $name                = self::DESTINATION_NAME,
        ?DateTimeImmutable $now      = null,
    ): WebhookDestination {
        return WebhookDestination::register(
            id:            $id   ?? WebhookDestinationId::generate(),
            apiKeyId:      self::API_KEY_ID,
            url:           $url,
            name:          $name,
            now:           $now  ?? new DateTimeImmutable(),
            correlationId: CorrelationId::fromString('test-corr-1'),
        );
    }
}
