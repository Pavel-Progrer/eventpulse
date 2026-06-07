<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Channel;

use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use EventPulse\Infrastructure\Notification\Channel\EmailChannelDriver;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Integration\Notification\Channel\Doubles\RecordingMailer;
use Tests\TestCase;

/**
 * Behaviour: the email driver translates a `DispatchRequest` into a
 * concrete SMTP send via Laravel's `Mailer`, populates from/to/subject
 * and the right body parts, and maps any thrown exception into a
 * domain-classified `DispatchOutcome`.
 *
 * Strategy: the test does not run against `Mail::fake()` because the
 * driver depends on the `Mailer` *contract*, not the facade. A custom
 * recording mailer gives us direct visibility into the `Message`
 * callback and lets us inject specific exceptions for the
 * classification tests.
 */
#[CoversClass(EmailChannelDriver::class)]
final class EmailChannelDriverTest extends TestCase
{
    private RecordingMailer $mailer;

    private EmailChannelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new RecordingMailer;
        $this->driver = new EmailChannelDriver(
            mailer: $this->mailer,
            logger: new NullLogger,
            fromAddress: 'noreply@eventpulse.test',
            fromName: 'EventPulse Test',
        );
    }

    #[Test]
    public function channel_returns_email(): void
    {
        self::assertSame(Channel::Email, $this->driver->channel());
    }

    #[Test]
    public function dispatch_sends_the_email_with_subject_recipient_and_html_body(): void
    {
        $request = $this->emailRequest([
            'subject' => 'Welcome to EventPulse',
            'html' => '<p>Hello!</p>',
        ]);

        $outcome = $this->driver->dispatch($request);

        self::assertTrue($outcome->succeeded);
        self::assertCount(1, $this->mailer->sentMessages);

        $sent = $this->mailer->sentMessages[0];
        self::assertSame(['noreply@eventpulse.test' => 'EventPulse Test'], $sent['from']);
        self::assertSame(['user@example.com' => null], $sent['to']);
        self::assertSame('Welcome to EventPulse', $sent['subject']);
        self::assertSame('<p>Hello!</p>', $sent['html']);
        self::assertNull($sent['text']);
    }

    #[Test]
    public function dispatch_sends_text_only_when_only_text_provided(): void
    {
        $request = $this->emailRequest([
            'subject' => 'Plain text test',
            'text' => 'Hello from EventPulse',
        ]);

        $outcome = $this->driver->dispatch($request);

        self::assertTrue($outcome->succeeded);
        $sent = $this->mailer->sentMessages[0];
        self::assertNull($sent['html']);
        self::assertSame('Hello from EventPulse', $sent['text']);
    }

    #[Test]
    public function dispatch_sends_both_parts_when_both_provided(): void
    {
        $request = $this->emailRequest([
            'subject' => 'Both parts',
            'html' => '<p>HTML version</p>',
            'text' => 'Text version',
        ]);

        $outcome = $this->driver->dispatch($request);

        self::assertTrue($outcome->succeeded);
        $sent = $this->mailer->sentMessages[0];
        self::assertSame('<p>HTML version</p>', $sent['html']);
        self::assertSame('Text version', $sent['text']);
    }

    #[Test]
    public function dispatch_classifies_smtp_550_as_permanent(): void
    {
        $this->mailer->throwOnNextSend = new RuntimeException(
            'Expected response code 250 but got code "550", with message "550 5.1.1 No such user"'
        );

        $outcome = $this->driver->dispatch($this->emailRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Permanent, $outcome->classification);
        self::assertStringContainsString('550', (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_classifies_invalid_address_as_permanent(): void
    {
        $this->mailer->throwOnNextSend = new RuntimeException(
            'Invalid address: not-an-address'
        );

        $outcome = $this->driver->dispatch($this->emailRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Permanent, $outcome->classification);
    }

    #[Test]
    public function dispatch_classifies_connection_refused_as_transient(): void
    {
        $this->mailer->throwOnNextSend = new RuntimeException(
            'Connection refused: smtp.example.com:25'
        );

        $outcome = $this->driver->dispatch($this->emailRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Transient, $outcome->classification);
    }

    #[Test]
    public function dispatch_classifies_unknown_failure_as_transient_by_default(): void
    {
        // Why: an unrecognised failure is more likely to be a transient
        // glitch than a permanent rejection. Permanent classification
        // is opt-in via signal substrings; everything else retries.
        $this->mailer->throwOnNextSend = new RuntimeException('something weird went wrong');

        $outcome = $this->driver->dispatch($this->emailRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Transient, $outcome->classification);
    }

    #[Test]
    public function dispatch_throws_logic_exception_on_recipient_channel_mismatch(): void
    {
        // The aggregate's invariant 5.1.9 makes this unreachable through
        // normal construction. The driver still defends itself so that a
        // bypass-the-aggregate test or future internal caller surfaces
        // the bug as a programmer error rather than a silent failure.
        $request = new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel: Channel::Email,
            recipient: WebhookRecipient::fromDestinationId('11111111-2222-4333-8444-555555555555'),
            payload: NotificationPayload::forChannel(['subject' => 's', 'text' => 't'], Channel::Email),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );

        $this->expectException(LogicException::class);

        $this->driver->dispatch($request);
    }

    #[Test]
    public function constructor_rejects_an_empty_from_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty fromAddress');

        new EmailChannelDriver(
            mailer: $this->mailer,
            logger: new NullLogger,
            fromAddress: '',
            fromName: 'EventPulse',
        );
    }

    #[Test]
    public function constructor_rejects_a_whitespace_only_from_name(): void
    {
        // Whitespace-only is the same shape of bug as empty: it satisfies
        // a naive `!== ''` check but produces an unusable header.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty fromName');

        new EmailChannelDriver(
            mailer: $this->mailer,
            logger: new NullLogger,
            fromAddress: 'noreply@eventpulse.test',
            fromName: '   ',
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function emailRequest(?array $payload = null): DispatchRequest
    {
        $payload ??= ['subject' => 'Default subject', 'text' => 'Default body'];

        return new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel: Channel::Email,
            recipient: EmailRecipient::fromString('user@example.com'),
            payload: NotificationPayload::forChannel($payload, Channel::Email),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );
    }
}
