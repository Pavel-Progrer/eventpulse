<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\WebhookEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: a `WebhookEndpoint` is constructible only from a non-empty
 * https URL. The driver depends on this contract so it does not need to
 * defend itself against malformed resolver output.
 */
#[CoversClass(WebhookEndpoint::class)]
final class WebhookEndpointTest extends TestCase
{
    #[Test]
    public function accepts_a_valid_https_url(): void
    {
        $endpoint = new WebhookEndpoint('https://hooks.example.com/notify');

        self::assertSame('https://hooks.example.com/notify', $endpoint->url);
    }

    #[Test]
    public function rejects_an_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new WebhookEndpoint('');
    }

    #[Test]
    public function rejects_a_non_https_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must use https://');

        new WebhookEndpoint('http://insecure.example.com/notify');
    }

    #[Test]
    public function rejects_a_relative_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookEndpoint('/relative/path');
    }
}
