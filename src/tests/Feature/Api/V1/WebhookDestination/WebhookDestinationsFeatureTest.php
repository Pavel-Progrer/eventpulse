<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\WebhookDestination;

use App\Models\ApiKey;
use EventPulse\Infrastructure\WebhookDestination\Persistence\EloquentWebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Behaviour: the three webhook-destination endpoints (POST, GET list, DELETE)
 * respond correctly to happy paths, auth failures, scope failures, and domain
 * rule violations.
 *
 * Failure modes tested:
 *   POST:
 *     - No bearer token → 401
 *     - Wrong scope (read-only) → 403
 *     - Missing required fields → 422
 *     - HTTP URL → 422
 *     - Valid request → 201 with secret in body (only time)
 *
 *   GET:
 *     - No bearer token → 401
 *     - Wrong scope → 403
 *     - Empty list → 200 with empty data array
 *     - Returns own destinations only (tenant isolation)
 *     - Does NOT return the secret field
 *     - Pagination cursor works
 *
 *   DELETE:
 *     - No bearer token → 401
 *     - Wrong scope → 403
 *     - Unknown id → 404
 *     - Other tenant's id → 404 (no information disclosure)
 *     - Already disabled → 409
 *     - Valid → 204
 */
final class WebhookDestinationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $writer;

    private ApiKey $readOnly;

    private ApiKey $otherTenant;

    private const string VALID_URL = 'https://hooks.example.com/events';

    private const string VALID_SECRET = 'my-super-secret-minimum-16chars!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_001',
            'scopes' => ['notifications:write', 'notifications:read'],
            'status' => 'active',
            'label' => 'writer',
        ]);

        $this->readOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_reader_001',
            'scopes' => ['notifications:read'],
            'status' => 'active',
            'label' => 'read-only',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_other_tenant_001',
            'scopes' => ['notifications:write', 'notifications:read'],
            'status' => 'active',
            'label' => 'other tenant',
        ]);
    }

    // =========================================================================
    // POST /api/v1/webhook-destinations
    // =========================================================================

    #[Test]
    public function register_returns_401_without_bearer_token(): void
    {
        $this->postJson('/api/v1/webhook-destinations', $this->validBody())
            ->assertStatus(401);
    }

    #[Test]
    public function register_returns_403_when_caller_lacks_write_scope(): void
    {
        $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->readOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function register_returns_422_when_url_is_missing(): void
    {
        $body = $this->validBody();
        unset($body['url']);

        $this->postJson(
            '/api/v1/webhook-destinations',
            $body,
            $this->headersFor($this->writer),
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function register_returns_422_when_url_uses_http(): void
    {
        $this->postJson(
            '/api/v1/webhook-destinations',
            [...$this->validBody(), 'url' => 'http://insecure.example.com/hook'],
            $this->headersFor($this->writer),
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function register_returns_422_when_secret_is_too_short(): void
    {
        $this->postJson(
            '/api/v1/webhook-destinations',
            [...$this->validBody(), 'secret' => 'too-short'],
            $this->headersFor($this->writer),
        )->assertStatus(422);
    }

    #[Test]
    public function register_returns_422_when_secret_is_missing(): void
    {
        $body = $this->validBody();
        unset($body['secret']);

        $this->postJson(
            '/api/v1/webhook-destinations',
            $body,
            $this->headersFor($this->writer),
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function register_returns_201_with_correct_shape_and_secret(): void
    {
        $response = $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        $response->assertJsonStructure(['id', 'url', 'name', 'status', 'secret', 'created_at']);
        $response->assertJsonPath('url', self::VALID_URL);
        $response->assertJsonPath('name', 'Test hook');
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('secret', self::VALID_SECRET);
    }

    #[Test]
    public function register_persists_secret_encrypted_in_database(): void
    {
        $response = $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        $id = $response->json('id');
        $model = EloquentWebhookDestination::find($id);

        self::assertNotNull($model);

        // The column must not store the plaintext secret.
        self::assertNotSame(self::VALID_SECRET, $model->secret_encrypted);

        // But the ciphertext must decrypt back to the original secret.
        self::assertSame(self::VALID_SECRET, Crypt::decryptString($model->secret_encrypted));
    }

    // =========================================================================
    // GET /api/v1/webhook-destinations
    // =========================================================================

    #[Test]
    public function list_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/v1/webhook-destinations')
            ->assertStatus(401);
    }

    #[Test]
    public function list_returns_403_when_caller_lacks_read_scope(): void
    {
        $writerOnlyKey = ApiKey::query()->create([
            'identifier' => 'ep_live_write_only_001',
            'scopes' => ['notifications:write'],
            'status' => 'active',
            'label' => 'write-only',
        ]);

        $this->getJson(
            '/api/v1/webhook-destinations',
            $this->headersFor($writerOnlyKey),
        )->assertStatus(403);
    }

    #[Test]
    public function list_returns_empty_data_when_no_destinations_exist(): void
    {
        $this->getJson(
            '/api/v1/webhook-destinations',
            $this->headersFor($this->writer),
        )->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.next_cursor', null);
    }

    #[Test]
    public function list_returns_destinations_for_authenticated_key_only(): void
    {
        // Writer registers one destination.
        $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        // Other tenant registers one destination.
        $this->postJson(
            '/api/v1/webhook-destinations',
            [...$this->validBody(), 'name' => 'Other tenant hook'],
            $this->headersFor($this->otherTenant),
        )->assertStatus(201);

        // Writer sees only their own destination.
        $response = $this->getJson(
            '/api/v1/webhook-destinations',
            $this->headersFor($this->writer),
        )->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Test hook');
    }

    #[Test]
    public function list_does_not_include_secret_field(): void
    {
        $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        $response = $this->getJson(
            '/api/v1/webhook-destinations',
            $this->headersFor($this->writer),
        )->assertOk();

        $first = $response->json('data.0');
        self::assertArrayNotHasKey('secret', $first);
    }

    #[Test]
    public function list_paginates_with_cursor(): void
    {
        // Register three destinations.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson(
                '/api/v1/webhook-destinations',
                [...$this->validBody(), 'name' => "Hook {$i}"],
                $this->headersFor($this->writer),
            )->assertStatus(201);
        }

        // First page of 2.
        $page1 = $this->getJson(
            '/api/v1/webhook-destinations?limit=2',
            $this->headersFor($this->writer),
        )->assertOk();

        $page1->assertJsonCount(2, 'data');
        $cursor = $page1->json('meta.next_cursor');
        self::assertNotNull($cursor);

        // Second page using cursor.
        $page2 = $this->getJson(
            "/api/v1/webhook-destinations?limit=2&cursor={$cursor}",
            $this->headersFor($this->writer),
        )->assertOk();

        $page2->assertJsonCount(1, 'data');
        $page2->assertJsonPath('meta.next_cursor', null);
    }

    // =========================================================================
    // DELETE /api/v1/webhook-destinations/{id}
    // =========================================================================

    #[Test]
    public function disable_returns_401_without_bearer_token(): void
    {
        $this->deleteJson('/api/v1/webhook-destinations/'.Uuid::uuid4()->toString())
            ->assertStatus(401);
    }

    #[Test]
    public function disable_returns_403_when_caller_lacks_write_scope(): void
    {
        $this->deleteJson(
            '/api/v1/webhook-destinations/'.Uuid::uuid4()->toString(),
            [],
            $this->headersFor($this->readOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function disable_returns_404_for_unknown_id(): void
    {
        $this->deleteJson(
            '/api/v1/webhook-destinations/'.Uuid::uuid4()->toString(),
            [],
            $this->headersFor($this->writer),
        )->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function disable_returns_404_for_another_tenants_destination(): void
    {
        // Other tenant creates a destination.
        $response = $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->otherTenant),
        )->assertStatus(201);

        $otherId = $response->json('id');

        // Writer attempts to disable it — should get 404, not 403.
        $this->deleteJson(
            "/api/v1/webhook-destinations/{$otherId}",
            [],
            $this->headersFor($this->writer),
        )->assertStatus(404);
    }

    #[Test]
    public function disable_returns_204_and_sets_status_to_disabled(): void
    {
        $createResponse = $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        $id = $createResponse->json('id');

        $this->deleteJson(
            "/api/v1/webhook-destinations/{$id}",
            [],
            $this->headersFor($this->writer),
        )->assertStatus(204);

        // Verify status in the database.
        $model = EloquentWebhookDestination::find($id);
        self::assertSame('disabled', $model->status);
    }

    #[Test]
    public function disable_returns_409_when_already_disabled(): void
    {
        $createResponse = $this->postJson(
            '/api/v1/webhook-destinations',
            $this->validBody(),
            $this->headersFor($this->writer),
        )->assertStatus(201);

        $id = $createResponse->json('id');

        // First disable.
        $this->deleteJson(
            "/api/v1/webhook-destinations/{$id}",
            [],
            $this->headersFor($this->writer),
        )->assertStatus(204);

        // Second disable — 409.
        $this->deleteJson(
            "/api/v1/webhook-destinations/{$id}",
            [],
            $this->headersFor($this->writer),
        )->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_DISABLED');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'url' => self::VALID_URL,
            'secret' => self::VALID_SECRET,
            'name' => 'Test hook',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(ApiKey $key): array
    {
        return [
            'Authorization' => "Bearer {$key->identifier}",
            'Accept' => 'application/json',
        ];
    }
}
