<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Support\Factories\UsesNotificationFactory;
use Tests\TestCase;

/**
 * Behaviour: `POST /api/v1/dlq/{id}/replay` creates a new queued notification
 * from a dead-lettered one and marks the original as replayed;
 * `DELETE /api/v1/dlq/{id}` acknowledges and hides the entry from the
 * default DLQ list view.
 *
 * Failure modes tested for replay:
 *  - No bearer token → 401
 *  - Missing `dlq:replay` scope → 403
 *  - Unknown id → 404
 *  - Other tenant's id → 404 (no information disclosure)
 *  - Already replayed with a different key → 409 ALREADY_REPLAYED
 *  - Idempotent retry with the same key → 202 (existing notification)
 *  - Happy path → 202, new notification enqueued, original marked replayed
 *
 * Failure modes tested for discard:
 *  - No bearer token → 401
 *  - Missing `dlq:replay` scope → 403
 *  - Unknown id → 404
 *  - Other tenant's id → 404
 *  - Idempotent: discarding an already-discarded entry → 204 again
 *  - Happy path → 204 and entry hidden from GET /dlq
 */
final class DlqReplayDiscardTest extends TestCase
{
    use RefreshDatabase;
    use UsesNotificationFactory;

    private ApiKey $replayOps;   // dlq:read + dlq:replay
    private ApiKey $readOnly;    // dlq:read only — no replay / discard access
    private ApiKey $otherTenant; // dlq:replay but a different tenant

    protected function setUp(): void
    {
        parent::setUp();

        $this->replayOps = ApiKey::query()->create([
            'identifier' => 'ep_live_replay_ops_001',
            'scopes'     => ['dlq:read', 'dlq:replay'],
            'status'     => 'active',
            'label'      => 'replay ops',
        ]);

        $this->readOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_ro_001',
            'scopes'     => ['dlq:read'],
            'status'     => 'active',
            'label'      => 'read-only',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_replay_ops_002',
            'scopes'     => ['dlq:read', 'dlq:replay'],
            'status'     => 'active',
            'label'      => 'other tenant',
        ]);

        // Prevent real queue dispatch during feature tests.
        Bus::fake();
    }

    // =========================================================================
    // POST /api/v1/dlq/{id}/replay
    // =========================================================================

    #[Test]
    public function replay_returns_401_without_bearer_token(): void
    {
        $this->postJson('/api/v1/dlq/' . Uuid::uuid4()->toString() . '/replay')
            ->assertStatus(401);
    }

    #[Test]
    public function replay_returns_403_when_caller_lacks_dlq_replay_scope(): void
    {
        $this->postJson(
            '/api/v1/dlq/' . Uuid::uuid4()->toString() . '/replay',
            [],
            $this->headersFor($this->readOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function replay_returns_404_for_unknown_id(): void
    {
        $this->postJson(
            '/api/v1/dlq/' . Uuid::uuid4()->toString() . '/replay',
            [],
            $this->headersWithIdempotencyKey($this->replayOps),
        )->assertStatus(404)
         ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function replay_returns_404_for_another_tenants_notification(): void
    {
        $notification = $this->factory()->dlqEntry($this->otherTenant)->save();

        $this->postJson(
            '/api/v1/dlq/' . $notification->id()->toString() . '/replay',
            [],
            $this->headersWithIdempotencyKey($this->replayOps),
        )->assertStatus(404);
    }

    #[Test]
    public function replay_returns_202_with_new_notification_shape(): void
    {
        $original = $this->factory()->dlqEntry($this->replayOps)->save();

        $response = $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay',
            [],
            $this->headersWithIdempotencyKey($this->replayOps),
        )->assertStatus(202);

        $response->assertJsonStructure([
            'id', 'channel', 'recipient', 'status', 'created_at', 'attempts',
        ]);

        // New notification has a different id to the original.
        self::assertNotEquals($original->id()->toString(), $response->json('id'));

        // Starts in queued state (not yet dispatched).
        $response->assertJsonPath('status', 'queued');

        // Links back to the original via replay_of_id.
        $response->assertJsonPath('replay_of_id', $original->id()->toString());
    }

    #[Test]
    public function replay_echoes_correlation_id_in_response_header(): void
    {
        $original = $this->factory()->dlqEntry($this->replayOps)->save();

        $response = $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay',
            [],
            array_merge(
                $this->headersWithIdempotencyKey($this->replayOps),
                ['X-Correlation-ID' => 'corr-replay-test-001'],
            ),
        )->assertStatus(202);

        self::assertSame(
            'corr-replay-test-001',
            $response->headers->get('X-Correlation-ID'),
        );
    }

    #[Test]
    public function replay_is_idempotent_with_the_same_idempotency_key(): void
    {
        $original = $this->factory()->dlqEntry($this->replayOps)->save();
        $headers  = $this->headersWithIdempotencyKey($this->replayOps, 'replay-idem-key-fixed');

        $first  = $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay', [], $headers,
        )->assertStatus(202);

        $second = $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay', [], $headers,
        )->assertStatus(202);

        // Both responses carry the same new notification id — idempotent replay.
        self::assertSame($first->json('id'), $second->json('id'));
    }

    #[Test]
    public function replay_returns_409_when_already_replayed_with_different_key(): void
    {
        $original     = $this->factory()->dlqEntry($this->replayOps)->save();
        $firstHeaders = $this->headersWithIdempotencyKey($this->replayOps, 'first-replay-key');
        $otherHeaders = $this->headersWithIdempotencyKey($this->replayOps, 'second-replay-key');

        // First replay succeeds.
        $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay', [], $firstHeaders,
        )->assertStatus(202);

        // A different idempotency key on the same already-replayed entry → 409.
        $this->postJson(
            '/api/v1/dlq/' . $original->id()->toString() . '/replay', [], $otherHeaders,
        )->assertStatus(409)
         ->assertJsonPath('error.code', 'ALREADY_REPLAYED');
    }

    #[Test]
    public function replayed_notification_appears_in_original_dlq_entry_get_response(): void
    {
        $original = $this->factory()->dlqEntry($this->replayOps)->save();
        $id       = $original->id()->toString();

        $replayResponse = $this->postJson(
            "/api/v1/dlq/{$id}/replay",
            [],
            $this->headersWithIdempotencyKey($this->replayOps),
        )->assertStatus(202);

        $replayId = $replayResponse->json('id');

        // The original DLQ entry should now carry the replay_notification_id.
        $dlqEntry = $this->getJson(
            "/api/v1/dlq/{$id}",
            $this->headersFor($this->replayOps),
        )->assertStatus(200);

        $dlqEntry->assertJsonPath('replay_notification_id', $replayId);
    }

    // =========================================================================
    // DELETE /api/v1/dlq/{id}
    // =========================================================================

    #[Test]
    public function discard_returns_401_without_bearer_token(): void
    {
        $this->deleteJson('/api/v1/dlq/' . Uuid::uuid4()->toString())
            ->assertStatus(401);
    }

    #[Test]
    public function discard_returns_403_when_caller_lacks_dlq_replay_scope(): void
    {
        $this->deleteJson(
            '/api/v1/dlq/' . Uuid::uuid4()->toString(),
            [],
            $this->headersFor($this->readOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function discard_returns_404_for_unknown_id(): void
    {
        $this->deleteJson(
            '/api/v1/dlq/' . Uuid::uuid4()->toString(),
            [],
            $this->headersFor($this->replayOps),
        )->assertStatus(404)
         ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function discard_returns_404_for_another_tenants_notification(): void
    {
        $notification = $this->factory()->dlqEntry($this->otherTenant)->save();

        $this->deleteJson(
            '/api/v1/dlq/' . $notification->id()->toString(),
            [],
            $this->headersFor($this->replayOps),
        )->assertStatus(404);
    }

    #[Test]
    public function discard_returns_204_on_success(): void
    {
        $notification = $this->factory()->dlqEntry($this->replayOps)->save();

        $this->deleteJson(
            '/api/v1/dlq/' . $notification->id()->toString(),
            [],
            $this->headersFor($this->replayOps),
        )->assertStatus(204);
    }

    #[Test]
    public function discarded_entry_no_longer_appears_in_dlq_list(): void
    {
        $notification = $this->factory()->dlqEntry($this->replayOps)->save();
        $id           = $notification->id()->toString();

        // Visible before discard.
        $this->getJson('/api/v1/dlq', $this->headersFor($this->replayOps))
             ->assertStatus(200)
             ->assertJsonCount(1, 'data');

        // Discard it.
        $this->deleteJson("/api/v1/dlq/{$id}", [], $this->headersFor($this->replayOps))
             ->assertStatus(204);

        // Gone from the default DLQ list view.
        $this->getJson('/api/v1/dlq', $this->headersFor($this->replayOps))
             ->assertStatus(200)
             ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function discard_is_idempotent(): void
    {
        $notification = $this->factory()->dlqEntry($this->replayOps)->save();
        $id           = $notification->id()->toString();

        $this->deleteJson("/api/v1/dlq/{$id}", [], $this->headersFor($this->replayOps))
             ->assertStatus(204);

        // Second discard of the same entry → still 204.
        $this->deleteJson("/api/v1/dlq/{$id}", [], $this->headersFor($this->replayOps))
             ->assertStatus(204);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<string, string>
     */
    private function headersFor(ApiKey $key): array
    {
        return ['Authorization' => 'Bearer ' . $key->identifier];
    }

    /**
     * @return array<string, string>
     */
    private function headersWithIdempotencyKey(ApiKey $key, ?string $idemKey = null): array
    {
        return array_merge(
            $this->headersFor($key),
            ['Idempotency-Key' => $idemKey ?? Uuid::uuid4()->toString()],
        );
    }
}
