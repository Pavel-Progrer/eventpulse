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
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Infrastructure\Notification\Channel\SmsChannelDriver;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Tests\Integration\Notification\Channel\Doubles\RecordingLogger;

/**
 * Behaviour: the SMS driver is an honest stub. Every dispatch returns
 * `Permanent` failure with a reason that names the class and points
 * the operator at the replacement steps. This is the production-correct
 * behaviour until an SMS provider is integrated.
 *
 * If you are reading this test because the SMS driver has been wired
 * to a real provider: this whole test class becomes obsolete and is
 * replaced by a provider-specific integration test. The shape of the
 * outcome (classification, reason, log-line shape) is what changes.
 */
#[CoversClass(SmsChannelDriver::class)]
final class SmsChannelDriverTest extends TestCase
{
    private RecordingLogger $logger;

    private SmsChannelDriver $driver;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger;
        $this->driver = new SmsChannelDriver(logger: $this->logger);
    }

    #[Test]
    public function channel_returns_sms(): void
    {
        self::assertSame(Channel::Sms, $this->driver->channel());
    }

    #[Test]
    public function dispatch_always_returns_permanent_failure(): void
    {
        $outcome = $this->driver->dispatch($this->smsRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Permanent, $outcome->classification);
    }

    #[Test]
    public function failure_reason_names_the_class_for_operator_actionability(): void
    {
        // The reason is the operator's only signal in the DLQ. It must
        // identify the file to replace; reading the reason should be
        // sufficient to act.
        $outcome = $this->driver->dispatch($this->smsRequest());

        self::assertNotNull($outcome->reason);
        self::assertStringContainsString('SmsChannelDriver', $outcome->reason);
        self::assertStringContainsString('not configured', $outcome->reason);
    }

    #[Test]
    public function dispatch_logs_a_warning_with_the_failure_classification(): void
    {
        $this->driver->dispatch($this->smsRequest());

        self::assertTrue(
            $this->logger->hasRecord(LogLevel::WARNING, 'notification.sms.driver_unconfigured'),
            'Expected SMS driver to log a warning for the unconfigured dispatch.',
        );
    }

    #[Test]
    public function dispatch_throws_logic_exception_on_recipient_channel_mismatch(): void
    {
        $request = new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel: Channel::Sms,
            recipient: EmailRecipient::fromString('user@example.com'),
            payload: NotificationPayload::forChannel(['body' => 'hi'], Channel::Sms),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );

        $this->expectException(LogicException::class);

        $this->driver->dispatch($request);
    }

    private function smsRequest(): DispatchRequest
    {
        return new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel: Channel::Sms,
            recipient: SmsRecipient::fromE164('+15551234567'),
            payload: NotificationPayload::forChannel(['body' => 'Test SMS body'], Channel::Sms),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );
    }
}
