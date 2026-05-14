<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchNotificationJob;
use App\Models\ApiKey;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour: `POST /api/v1/notifications` accepts a well-formed dispatch
 * request, persists the notification, and returns a thin acceptance receipt;
 * malformed, unauthenticated, or unauthorised requests are rejected with
 * the standardised JSON error envelope and the appropriate HTTP status.
 *
 * This is Day 3's first end-to-end feature test: it boots the full Laravel
 * stack, runs migrations against a test database, and exercises the route
 * through the real middleware/controller/handler/repository chain.
 */
final class SubmitNotificationTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $writerKey;
    private ApiKey $readOnlyKey;
    private ApiKey $revokedKey;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([DispatchNotificationJob::class]);

        $this->writerKey = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_001',
            'scopes'     => ['notifications:write', 'notifications:read'],
            'status'     => 'active',
            'label'      => 'test writer',
        ]);

        $this->readOnlyKey = ApiKey::query()->create([
            'identifier' => 'ep_live_reader_001',
            'scopes'     => ['notifications:read'],
            'status'     => 'active',
            'label'      => 'test reader',
        ]);

        $this->revokedKey = ApiKey::query()->create([
            'identifier' => 'ep_live_revoked_001',
            'scopes'     => ['notifications:write'],
            'status'     => 'revoked',
            'revoked_at' => now(),
            'label'      => 'test revoked',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Happy paths — one per channel
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_accepts_an_email_notification_and_returns_202(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'email',
                'recipient' => 'user@example.com',
                'payload'   => [
                    'subject'   => 'Your order has shipped',
                    'body_text' => 'Tracking: ORD-9981',
                ],
                'priority'  => 'normal',
            ],
            $this->writerHeaders(idempotencyKey: 'idem-email-aaaa-0001'),
        );

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'id',
            'status',
            'correlation_id',
            'created_at',
            '_links' => ['self'],
        ]);
        $response->assertJson(['status' => 'queued']);

        // Self link points at the not-yet-implemented status endpoint
        // (`GET /notifications/{id}` ships in Day 4+).
        $id = $response->json('id');
        $response->assertJson([
            '_links' => ['self' => "/api/v1/notifications/{$id}"],
        ]);
    }

    #[Test]
    public function it_accepts_a_webhook_notification(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'webhook',
                'recipient' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
                'payload'   => [
                    'body' => [
                        'event'    => 'order.shipped',
                        'order_id' => 'ORD-9981',
                    ],
                ],
                'priority'  => 'high',
            ],
            $this->writerHeaders(idempotencyKey: 'idem-webhook-bbbb-0001'),
        );

        $response->assertStatus(202);
        $response->assertJson(['status' => 'queued']);
    }

    #[Test]
    public function it_accepts_an_sms_notification(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'sms',
                'recipient' => '+381641234567',
                'payload'   => ['body' => 'Your code is 1234'],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-sms-cccc-0001'),
        );

        $response->assertStatus(202);
        $response->assertJson(['status' => 'queued']);
    }

    // ---------------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------------

    #[Test]
    public function the_notification_is_persisted_with_the_expected_columns(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'email',
                'recipient' => 'persisted@example.com',
                'payload'   => [
                    'subject'   => 'Persistence smoke test',
                    'body_text' => 'hello',
                ],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-persist-0001'),
        );

        $response->assertStatus(202);
        $id = $response->json('id');

        $row = EloquentNotification::query()->find($id);

        self::assertNotNull($row, 'notification row was not persisted');
        self::assertSame('email', $row->channel);
        self::assertSame('persisted@example.com', $row->recipient);
        self::assertSame('queued', $row->status);
        self::assertSame('normal', $row->priority);
        self::assertSame('idem-persist-0001', $row->idempotency_key);
        self::assertSame($this->writerKey->id, $row->api_key_id);

        // The HTTP-layer `body_text` was mapped to the domain-level `text`
        // before persistence.
        self::assertSame('hello', $row->payload['text']);
        self::assertSame('Persistence smoke test', $row->payload['subject']);
        self::assertArrayNotHasKey('body_text', $row->payload);
    }

    // ---------------------------------------------------------------------------
    // Correlation id
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_echoes_a_caller_supplied_correlation_id(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'sms',
                'recipient' => '+381641234567',
                'payload'   => ['body' => 'hi'],
            ],
            $this->writerHeaders(
                idempotencyKey: 'idem-corr-aaaa-0001',
                correlationId:  'req_caller-supplied-12345',
            ),
        );

        $response->assertStatus(202);
        $response->assertJson(['correlation_id' => 'req_caller-supplied-12345']);
        $response->assertHeader('X-Correlation-ID', 'req_caller-supplied-12345');
    }

    #[Test]
    public function it_generates_a_correlation_id_when_caller_omits_one(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'sms',
                'recipient' => '+381641234567',
                'payload'   => ['body' => 'hi'],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-corr-bbbb-0001'),
        );

        $response->assertStatus(202);

        $generated = $response->json('correlation_id');
        self::assertIsString($generated);
        self::assertNotSame('', $generated);
        self::assertSame($generated, $response->headers->get('X-Correlation-ID'));
    }

    // ---------------------------------------------------------------------------
    // Authentication failures (401)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_returns_401_when_authorization_header_is_missing(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            ['Idempotency-Key' => 'idem-401-aaaa-0001'],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    #[Test]
    public function it_returns_401_when_authorization_scheme_is_not_bearer(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            [
                'Authorization'   => 'Basic dXNlcjpwYXNz',
                'Idempotency-Key' => 'idem-401-bbbb-0001',
            ],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    #[Test]
    public function it_returns_401_when_bearer_token_is_unknown(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            [
                'Authorization'   => 'Bearer ep_live_does_not_exist',
                'Idempotency-Key' => 'idem-401-cccc-0001',
            ],
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function it_returns_401_when_api_key_is_revoked(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            [
                'Authorization'   => "Bearer {$this->revokedKey->identifier}",
                'Idempotency-Key' => 'idem-401-dddd-0001',
            ],
        );

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------------------
    // Authorization failures (403)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_returns_403_when_api_key_lacks_notifications_write_scope(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            [
                'Authorization'   => "Bearer {$this->readOnlyKey->identifier}",
                'Idempotency-Key' => 'idem-403-aaaa-0001',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $response->assertJsonPath('error.details.required_scope', 'notifications:write');
    }

    // ---------------------------------------------------------------------------
    // Validation failures (422)
    // ---------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_when_idempotency_key_header_is_missing(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            ['Authorization' => "Bearer {$this->writerKey->identifier}"],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertHasFieldError($response, '/Idempotency-Key');
    }

    #[Test]
    public function it_returns_422_when_channel_is_missing(): void
    {
        $body = $this->validEmailBody();
        unset($body['channel']);

        $response = $this->postJson(
            '/api/v1/notifications',
            $body,
            $this->writerHeaders(idempotencyKey: 'idem-422-aaaa-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/channel');
    }

    #[Test]
    public function it_returns_422_when_channel_is_not_one_of_the_known_values(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            ['channel' => 'pigeon-post', 'recipient' => 'x', 'payload' => ['body' => 'x']],
            $this->writerHeaders(idempotencyKey: 'idem-422-bbbb-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/channel');
    }

    #[Test]
    public function it_returns_422_when_email_recipient_format_is_invalid(): void
    {
        // Passes the FormRequest's coarse `string, max:320` check. The
        // domain `EmailRecipient::fromString()` rejects it, the exception
        // handler maps that to 422.
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'email',
                'recipient' => 'definitely-not-an-email',
                'payload'   => ['subject' => 'x', 'body_text' => 'y'],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-422-cccc-0001'),
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function it_returns_422_when_email_payload_lacks_both_text_and_html_bodies(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'email',
                'recipient' => 'user@example.com',
                'payload'   => ['subject' => 'Subject only — no body'],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-422-dddd-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/payload');
    }

    #[Test]
    public function it_returns_422_when_sms_payload_is_missing_body(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'sms',
                'recipient' => '+381641234567',
                'payload'   => [],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-422-eeee-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/payload/body');
    }

    #[Test]
    public function it_returns_422_when_sms_body_exceeds_1600_characters(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            [
                'channel'   => 'sms',
                'recipient' => '+381641234567',
                'payload'   => ['body' => str_repeat('x', 1601)],
            ],
            $this->writerHeaders(idempotencyKey: 'idem-422-ffff-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/payload/body');
    }

    #[Test]
    public function it_returns_422_when_payload_field_is_missing(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            ['channel' => 'sms', 'recipient' => '+381641234567'],
            $this->writerHeaders(idempotencyKey: 'idem-422-gggg-0001'),
        );

        $response->assertStatus(422);
        $this->assertHasFieldError($response, '/payload');
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validEmailBody(): array
    {
        return [
            'channel'   => 'email',
            'recipient' => 'user@example.com',
            'payload'   => [
                'subject'   => 'subject',
                'body_text' => 'body',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function writerHeaders(string $idempotencyKey, ?string $correlationId = null): array
    {
        $headers = [
            'Authorization'   => "Bearer {$this->writerKey->identifier}",
            'Idempotency-Key' => $idempotencyKey,
        ];

        if ($correlationId !== null) {
            $headers['X-Correlation-ID'] = $correlationId;
        }

        return $headers;
    }

    /**
     * Assert that the validation-error response includes a `fields[]` entry
     * whose `path` matches the expected JSON Pointer.
     */
    private function assertHasFieldError(\Illuminate\Testing\TestResponse $response, string $expectedPath): void
    {
        $fields = $response->json('error.details.fields') ?? [];
        $paths  = array_column($fields, 'path');

        self::assertContains(
            $expectedPath,
            $paths,
            sprintf(
                'Expected validation error at "%s"; got %s.',
                $expectedPath,
                json_encode($paths, JSON_THROW_ON_ERROR),
            ),
        );
    }
}
