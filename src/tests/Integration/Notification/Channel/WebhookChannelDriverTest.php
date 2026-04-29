<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Channel;

use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Application\Notification\Channel\WebhookEndpoint;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use EventPulse\Infrastructure\Notification\Channel\WebhookChannelDriver;
use EventPulse\Tests\Unit\Application\Notification\Channel\Doubles\InMemoryWebhookEndpointResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Behaviour: the webhook driver POSTs the payload to the resolved
 * endpoint, attaches the standard EventPulse headers, classifies HTTP
 * responses per the specification's failure-classification table, and
 * surfaces resolution failures with their pre-classified reason.
 */
#[CoversClass(WebhookChannelDriver::class)]
final class WebhookChannelDriverTest extends TestCase
{
    private const DESTINATION_ID = '11111111-2222-4333-8444-555555555555';

    private InMemoryWebhookEndpointResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new InMemoryWebhookEndpointResolver();
        $this->resolver->register(
            self::DESTINATION_ID,
            new WebhookEndpoint('https://hooks.example.com/notify'),
        );
    }

    #[Test]
    public function channel_returns_webhook(): void
    {
        $driver = $this->driver();
        self::assertSame(Channel::Webhook, $driver->channel());
    }

    #[Test]
    public function dispatch_posts_body_with_eventpulse_headers_on_success(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $request = $this->webhookRequest([
            'body'    => ['event' => 'user.signed_up', 'user_id' => 42],
            'headers' => ['X-Custom-Header' => 'custom-value'],
        ]);

        $outcome = $this->driver()->dispatch($request);

        self::assertTrue($outcome->succeeded);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://hooks.example.com/notify'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-EventPulse-Notification-ID') !== []
                && $request->header('X-EventPulse-Attempt')[0] === '1'
                && $request->header('X-Correlation-ID') !== []
                && $request->header('X-Custom-Header')[0] === 'custom-value'
                && $request->data() === ['event' => 'user.signed_up', 'user_id' => 42];
        });
    }

    #[Test]
    public function dispatch_treats_payload_as_body_when_no_envelope(): void
    {
        // Permissive fallback: a notification submitted from a non-HTTP
        // path that doesn't follow the OpenAPI envelope still works.
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $request = $this->webhookRequest([
            'event'   => 'user.signed_up',
            'user_id' => 42,
        ]);

        $outcome = $this->driver()->dispatch($request);

        self::assertTrue($outcome->succeeded);
        Http::assertSent(function (Request $request): bool {
            return $request->data() === ['event' => 'user.signed_up', 'user_id' => 42];
        });
    }

    #[Test]
    public function dispatch_filters_reserved_headers_supplied_by_caller(): void
    {
        // A caller cannot override Content-Type or our delivery
        // metadata. The header is silently dropped (rather than
        // rejected) so a misconfigured caller still gets their
        // notification delivered with the correct headers.
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $request = $this->webhookRequest([
            'body'    => ['ok' => true],
            'headers' => [
                'Content-Type'                  => 'text/plain',
                'X-EventPulse-Notification-ID' => 'spoofed',
                'X-EventPulse-Attempt'         => '99',
                'X-Correlation-ID'             => 'spoofed',
                'X-Custom-Header'              => 'kept',
            ],
        ]);

        $this->driver()->dispatch($request);

        Http::assertSent(function (Request $request): bool {
            // Reserved headers retained their EventPulse-controlled values.
            return $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-EventPulse-Attempt')[0] === '1'
                && $request->header('X-Custom-Header')[0] === 'kept';
        });
    }

    /**
     * @return iterable<string, array{0: int, 1: FailureClassification}>
     */
    public static function httpStatusClassifications(): iterable
    {
        yield '410 Gone is permanent'              => [410, FailureClassification::Permanent];
        yield '400 Bad Request is permanent'       => [400, FailureClassification::Permanent];
        yield '401 Unauthorized is permanent'      => [401, FailureClassification::Permanent];
        yield '404 Not Found is permanent'         => [404, FailureClassification::Permanent];
        yield '408 Request Timeout is transient'   => [408, FailureClassification::Transient];
        yield '429 Too Many Requests is transient' => [429, FailureClassification::Transient];
        yield '500 is transient'                   => [500, FailureClassification::Transient];
        yield '502 Bad Gateway is transient'       => [502, FailureClassification::Transient];
        yield '503 Service Unavailable is transient' => [503, FailureClassification::Transient];
        yield '504 Gateway Timeout is transient'   => [504, FailureClassification::Transient];
    }

    #[Test]
    #[DataProvider('httpStatusClassifications')]
    public function dispatch_classifies_http_status_codes(int $status, FailureClassification $expected): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response(['error' => 'no'], $status),
        ]);

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame($expected, $outcome->classification);
        self::assertStringContainsString((string) $status, (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_truncates_response_body_to_one_kilobyte(): void
    {
        $largeBody = str_repeat('x', 5000);
        Http::fake([
            'hooks.example.com/*' => Http::response($largeBody, 500),
        ]);

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertNotNull($outcome->reason);
        // The reason carries the truncated snippet inline plus the
        // status-code prefix; the snippet itself must not exceed 1024
        // chars even when the full body is much longer.
        $marker      = 'HTTP 500: ';
        $position    = strpos($outcome->reason, $marker);
        self::assertNotFalse($position);
        $bodySnippet = substr($outcome->reason, $position + strlen($marker));
        self::assertLessThanOrEqual(1024, mb_strlen($bodySnippet));
    }

    #[Test]
    public function dispatch_classifies_connection_exception_as_transient(): void
    {
        // Http::fake doesn't natively trigger ConnectionException via a
        // status; a closure callback that throws gives us the same path
        // the real client takes when DNS/TCP fail.
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to hooks.example.com');
        });

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Transient, $outcome->classification);
        self::assertStringContainsString('connection failure', (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_returns_unrecoverable_when_destination_does_not_exist(): void
    {
        // No registration on the resolver = "not found" = Unrecoverable.
        $this->resolver = new InMemoryWebhookEndpointResolver();

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Unrecoverable, $outcome->classification);
        self::assertStringContainsString('does not exist', (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_returns_permanent_when_destination_is_disabled(): void
    {
        $this->resolver = new InMemoryWebhookEndpointResolver();
        $this->resolver->markDisabled(self::DESTINATION_ID);

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Permanent, $outcome->classification);
        self::assertStringContainsString('disabled', (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_throws_logic_exception_on_recipient_channel_mismatch(): void
    {
        $request = new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel:        Channel::Webhook,
            recipient:      EmailRecipient::fromString('user@example.com'),
            payload:        NotificationPayload::forChannel(['x' => 'y'], Channel::Webhook),
            correlationId:  CorrelationId::generate(),
            attemptNumber:  AttemptNumber::first(),
        );

        $this->expectException(LogicException::class);

        $this->driver()->dispatch($request);
    }

    private function driver(): WebhookChannelDriver
    {
        return new WebhookChannelDriver(
            http:             $this->app->make(HttpFactory::class),
            endpointResolver: $this->resolver,
            logger:           new NullLogger(),
            timeoutSeconds:   30,
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function webhookRequest(?array $payload = null): DispatchRequest
    {
        $payload ??= ['body' => ['event' => 'test']];

        return new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel:        Channel::Webhook,
            recipient:      WebhookRecipient::fromDestinationId(self::DESTINATION_ID),
            payload:        NotificationPayload::forChannel($payload, Channel::Webhook),
            correlationId:  CorrelationId::generate(),
            attemptNumber:  AttemptNumber::first(),
        );
    }
}
