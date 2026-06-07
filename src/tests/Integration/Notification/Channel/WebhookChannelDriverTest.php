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
 * Behaviour: WebhookChannelDriver dispatches payloads to registered endpoints,
 * adds EventPulse metadata headers, computes HMAC-SHA256 signatures when a
 * signing secret is configured, classifies HTTP responses, and handles
 * network failures and endpoint-resolution failures correctly.
 *
 * Day 9 additions:
 *  - signing headers (X-EventPulse-Signature, X-EventPulse-Timestamp) are
 *    present when the resolved endpoint carries a secret.
 *  - signing headers are absent when the endpoint has no secret (unsigned
 *    in-memory endpoint — backwards-compatible path).
 *  - the signature is verifiable: the test re-computes it from the request
 *    headers and body and confirms the values match.
 */
#[CoversClass(WebhookChannelDriver::class)]
final class WebhookChannelDriverTest extends TestCase
{
    private const string DESTINATION_ID = '11111111-2222-4333-8444-555555555555';

    private const string SIGNING_SECRET = 'test-signing-secret-at-least-16c';

    private InMemoryWebhookEndpointResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new InMemoryWebhookEndpointResolver;
        $this->resolver->register(
            self::DESTINATION_ID,
            new WebhookEndpoint(
                url: 'https://hooks.example.com/notify',
                signingSecret: self::SIGNING_SECRET,
            ),
        );
    }

    // =========================================================================
    // Channel identity
    // =========================================================================

    #[Test]
    public function channel_returns_webhook(): void
    {
        self::assertSame(Channel::Webhook, $this->driver()->channel());
    }

    // =========================================================================
    // Happy path — headers and body
    // =========================================================================

    #[Test]
    public function dispatch_posts_body_with_eventpulse_headers_on_success(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $request = $this->webhookRequest([
            'body' => ['event' => 'user.signed_up', 'user_id' => 42],
            'headers' => ['X-Custom-Header' => 'custom-value'],
        ]);

        $outcome = $this->driver()->dispatch($request);

        self::assertTrue($outcome->succeeded);

        Http::assertSent(function (Request $req): bool {
            return $req->method() === 'POST'
                && $req->url() === 'https://hooks.example.com/notify'
                && $req->header('Content-Type')[0] === 'application/json'
                && $req->header('X-EventPulse-Notification-ID') !== []
                && $req->header('X-EventPulse-Attempt')[0] === '1'
                && $req->header('X-Correlation-ID') !== []
                && $req->header('X-Custom-Header')[0] === 'custom-value'
                && $req->data() === ['event' => 'user.signed_up', 'user_id' => 42];
        });
    }

    // =========================================================================
    // HMAC signing (Day 9)
    // =========================================================================

    #[Test]
    public function dispatch_adds_signature_and_timestamp_headers_when_secret_is_configured(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $this->driver()->dispatch($this->webhookRequest(['body' => ['event' => 'test']]));

        Http::assertSent(function (Request $req): bool {
            return $req->header('X-EventPulse-Timestamp') !== []
                && $req->header('X-EventPulse-Signature') !== [];
        });
    }

    #[Test]
    public function dispatch_signature_header_uses_sha256_prefix(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $this->driver()->dispatch($this->webhookRequest(['body' => ['event' => 'test']]));

        Http::assertSent(function (Request $req): bool {
            $sig = $req->header('X-EventPulse-Signature')[0] ?? '';

            return str_starts_with($sig, 'sha256=');
        });
    }

    #[Test]
    public function dispatch_signature_is_verifiable_with_shared_secret(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $body = ['event' => 'order.created', 'order_id' => 99];

        $this->driver()->dispatch($this->webhookRequest(['body' => $body]));

        Http::assertSent(function (Request $req) use ($body): bool {
            $timestamp = $req->header('X-EventPulse-Timestamp')[0] ?? '';
            $signature = $req->header('X-EventPulse-Signature')[0] ?? '';

            // Re-compute the signature using the same algorithm as the driver
            // (ADR-0005 §Decision): HMAC-SHA256 over "{timestamp}.{body_json}".
            $bodyJson = json_encode($body, JSON_THROW_ON_ERROR);
            $signedPayload = $timestamp.'.'.$bodyJson;
            $expected = 'sha256='.hash_hmac('sha256', $signedPayload, self::SIGNING_SECRET);

            return hash_equals($expected, $signature);
        });
    }

    #[Test]
    public function dispatch_omits_signature_headers_when_endpoint_has_no_secret(): void
    {
        // Register an unsigned endpoint (no secret) — the legacy / test path.
        $this->resolver = new InMemoryWebhookEndpointResolver;
        $this->resolver->register(
            self::DESTINATION_ID,
            new WebhookEndpoint(url: 'https://hooks.example.com/notify'),
        );

        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $this->driver()->dispatch($this->webhookRequest(['body' => ['event' => 'test']]));

        Http::assertSent(function (Request $req): bool {
            // Both signing headers must be absent when hasSigning() is false.
            return $req->header('X-EventPulse-Signature') === []
                && $req->header('X-EventPulse-Timestamp') === [];
        });
    }

    #[Test]
    public function dispatch_treats_payload_as_body_when_no_envelope(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $request = $this->webhookRequest([
            'event' => 'user.signed_up',
            'user_id' => 42,
        ]);

        $outcome = $this->driver()->dispatch($request);

        self::assertTrue($outcome->succeeded);
        Http::assertSent(function (Request $req): bool {
            return $req->data() === ['event' => 'user.signed_up', 'user_id' => 42];
        });
    }

    #[Test]
    public function dispatch_filters_reserved_headers_supplied_by_caller(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        $request = $this->webhookRequest([
            'body' => ['x' => 1],
            'headers' => [
                'X-EventPulse-Signature' => 'forged-sig',
                'X-EventPulse-Timestamp' => '0',
                'X-EventPulse-Notification-ID' => 'forged-id',
                'Content-Type' => 'text/plain',
                'X-Allowed' => 'allowed-value',
            ],
        ]);

        $this->driver()->dispatch($request);

        Http::assertSent(function (Request $req): bool {
            $sig = $req->header('X-EventPulse-Signature')[0] ?? '';
            $ts = $req->header('X-EventPulse-Timestamp')[0] ?? '';

            // The caller-supplied forged-sig and timestamp of '0' must be
            // overwritten by the driver's own HMAC computation.
            return $sig !== 'forged-sig'
                && $ts !== '0'
                && $req->header('X-Allowed')[0] === 'allowed-value';
        });
    }

    // =========================================================================
    // HTTP response classification
    // =========================================================================

    /**
     * @return iterable<string, array{int, bool, FailureClassification|null}>
     */
    public static function httpStatusProvider(): iterable
    {
        yield '200 OK' => [200, true,  null];
        yield '201 Created' => [201, true,  null];
        yield '204 No Content' => [204, true,  null];
        yield '408 Request Timeout' => [408, false, FailureClassification::Transient];
        yield '429 Too Many Requests' => [429, false, FailureClassification::Transient];
        yield '410 Gone' => [410, false, FailureClassification::Permanent];
        yield '400 Bad Request' => [400, false, FailureClassification::Permanent];
        yield '401 Unauthorized' => [401, false, FailureClassification::Permanent];
        yield '404 Not Found' => [404, false, FailureClassification::Permanent];
        yield '500 Server Error' => [500, false, FailureClassification::Transient];
        yield '503 Service Unavailable' => [503, false, FailureClassification::Transient];
    }

    #[Test]
    #[DataProvider('httpStatusProvider')]
    public function dispatch_classifies_http_responses_correctly(
        int $status,
        bool $expectedSuccess,
        ?FailureClassification $expectedClassification,
    ): void {
        Http::fake([
            'hooks.example.com/*' => Http::response('', $status),
        ]);

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertSame($expectedSuccess, $outcome->succeeded);

        if ($expectedClassification !== null) {
            self::assertSame($expectedClassification, $outcome->classification);
        }
    }

    // =========================================================================
    // Network failures
    // =========================================================================

    #[Test]
    public function dispatch_classifies_connection_failure_as_transient(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 7: Failed to connect to hooks.example.com');
        });

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Transient, $outcome->classification);
        self::assertStringContainsString('connection failure', (string) $outcome->reason);
    }

    // =========================================================================
    // Resolution failures
    // =========================================================================

    #[Test]
    public function dispatch_returns_unrecoverable_when_destination_does_not_exist(): void
    {
        $this->resolver = new InMemoryWebhookEndpointResolver;

        $outcome = $this->driver()->dispatch($this->webhookRequest());

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Unrecoverable, $outcome->classification);
        self::assertStringContainsString('does not exist', (string) $outcome->reason);
    }

    #[Test]
    public function dispatch_returns_permanent_when_destination_is_disabled(): void
    {
        $this->resolver = new InMemoryWebhookEndpointResolver;
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
            channel: Channel::Webhook,
            recipient: EmailRecipient::fromString('user@example.com'),
            payload: NotificationPayload::forChannel(['x' => 'y'], Channel::Webhook),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );

        $this->expectException(LogicException::class);

        $this->driver()->dispatch($request);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function driver(): WebhookChannelDriver
    {
        return new WebhookChannelDriver(
            http: $this->app->make(HttpFactory::class),
            endpointResolver: $this->resolver,
            logger: new NullLogger,
            timeoutSeconds: 30,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function webhookRequest(?array $payload = null): DispatchRequest
    {
        $payload ??= ['body' => ['event' => 'test']];

        return new DispatchRequest(
            notificationId: NotificationId::generate(),
            channel: Channel::Webhook,
            recipient: WebhookRecipient::fromDestinationId(self::DESTINATION_ID),
            payload: NotificationPayload::forChannel($payload, Channel::Webhook),
            correlationId: CorrelationId::generate(),
            attemptNumber: AttemptNumber::first(),
        );
    }
}
