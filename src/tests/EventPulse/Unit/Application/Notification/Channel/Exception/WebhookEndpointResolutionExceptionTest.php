<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel\Exception;

use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: each resolution-failure factory carries the classification
 * the driver will translate into a `DispatchOutcome`. Centralising the
 * classification here is the lever ADR-0004 §7 calls out as the
 * "one-file edit" path for tweaking failure semantics.
 */
#[CoversClass(WebhookEndpointResolutionException::class)]
final class WebhookEndpointResolutionExceptionTest extends TestCase
{
    private WebhookRecipient $recipient;

    protected function setUp(): void
    {
        $this->recipient = WebhookRecipient::fromDestinationId(
            '11111111-2222-4333-8444-555555555555',
        );
    }

    #[Test]
    public function not_found_is_unrecoverable(): void
    {
        $exception = WebhookEndpointResolutionException::notFound($this->recipient);

        self::assertSame(FailureClassification::Unrecoverable, $exception->classification);
        self::assertStringContainsString('does not exist', $exception->getMessage());
        self::assertSame($this->recipient, $exception->recipient);
    }

    #[Test]
    public function disabled_is_permanent(): void
    {
        // Why permanent and not unrecoverable: the destination still
        // exists; an operator could re-enable it. Until they do,
        // retrying achieves nothing — same shape as a 4xx from the
        // destination itself.
        $exception = WebhookEndpointResolutionException::disabled($this->recipient);

        self::assertSame(FailureClassification::Permanent, $exception->classification);
        self::assertStringContainsString('disabled', $exception->getMessage());
    }

    #[Test]
    public function not_configured_is_unrecoverable(): void
    {
        // Day 5's UnconfiguredWebhookEndpointResolver raises this
        // factory. Until Day 9 swaps in a real resolver, every webhook
        // notification dead-letters with a clear, actionable reason
        // pointing at the next phase of work.
        $exception = WebhookEndpointResolutionException::notConfigured($this->recipient);

        self::assertSame(FailureClassification::Unrecoverable, $exception->classification);
        self::assertStringContainsString('not configured', $exception->getMessage());
        self::assertStringContainsString('Day 9', $exception->getMessage());
    }

    #[Test]
    public function exception_message_includes_destination_id(): void
    {
        $exception = WebhookEndpointResolutionException::notFound($this->recipient);

        self::assertStringContainsString(
            $this->recipient->destinationId(),
            $exception->getMessage(),
        );
    }
}
