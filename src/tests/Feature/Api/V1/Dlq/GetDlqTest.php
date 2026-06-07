<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Factories\UsesNotificationFactory;
use Tests\TestCase;

/**
 * Behaviour: `GET /api/v1/dlq/{id}` returns the full notification with
 * its attempt history and dead-letter metadata, in the OpenAPI
 * `DlqEntryDetailed` shape.
 *
 * Failure modes and their response codes:
 *   - id format invalid     → 422 (caught at NotificationId factory).
 *   - id not present        → 404.
 *   - id belongs to another tenant → 404 (no info disclosure).
 *   - id present but not dead-lettered → 404 (this isn't the status endpoint).
 *
 * The same 404 for the last three cases is the deliberate
 * information-disclosure choice recorded in ADR-0006.
 *
 * Fixtures are built through `NotificationFactory` for the dead-lettered
 * cases. The "not dead-lettered" case still uses a direct insert because
 * the factory deliberately doesn't build queued notifications — that's
 * future work when the status endpoint ships.
 */
final class GetDlqTest extends TestCase
{
    use RefreshDatabase;
    use UsesNotificationFactory;

    private ApiKey $reader;

    private ApiKey $otherTenant;

    private ApiKey $writeOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_001',
            'scopes' => ['dlq:read'],
            'status' => 'active',
            'label' => 'reader A',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_002',
            'scopes' => ['dlq:read'],
            'status' => 'active',
            'label' => 'reader B',
        ]);

        $this->writeOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_only_001',
            'scopes' => ['notifications:write'],
            'status' => 'active',
            'label' => 'writer with no DLQ access',
        ]);
    }

    // -----------------------------------------------------------------------
    // Auth and authorisation
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_401_without_a_bearer_token(): void
    {
        $this->getJson('/api/v1/dlq/'.Str::uuid()->toString())
            ->assertStatus(401);
    }

    #[Test]
    public function returns_403_when_the_api_key_lacks_dlq_read_scope(): void
    {
        $this->getJson(
            '/api/v1/dlq/'.Str::uuid()->toString(),
            $this->headersFor($this->writeOnly),
        )->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_the_full_dead_lettered_notification(): void
    {
        // Two attempts — one preceding retry, one final dead-lettering
        // failure. Both transient (the factory's transient-retry path
        // for `max_retries_exceeded`); the test asserts on attempt
        // count and the rendering shape, not on a specific classification
        // mix.
        $notification = $this->factory()
            ->dlqEntry($this->reader)
            ->withReason('max_retries_exceeded')
            ->withPrecedingRetries(1)
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $id = $notification->id()->toString();

        $response = $this->getJson(
            "/api/v1/dlq/{$id}",
            $this->headersFor($this->reader),
        )->assertOk();

        // Outer DlqEntry shape.
        $response->assertJsonPath('id', $id);
        $response->assertJsonPath('notification_id', $id);
        $response->assertJsonPath('reason', 'max_retries_exceeded');
        $response->assertJsonPath('channel', 'email');
        $response->assertJsonPath('replay_notification_id', null);
        $response->assertJsonPath('replayed_at', null);

        // Embedded notification.
        $response->assertJsonPath('notification.id', $id);
        $response->assertJsonPath('notification.status', 'dead_lettered');
        $response->assertJsonPath('notification.channel', 'email');

        // Attempts present in order — two of them, the second one
        // failing at the deadLetteredAt instant.
        $response->assertJsonCount(2, 'notification.attempts');
        $response->assertJsonPath('notification.attempts.0.number', 1);
        $response->assertJsonPath('notification.attempts.1.number', 2);

        // Both attempts are failed (succeeded=false) — the final one is
        // what dead-lettered the notification, the preceding one was a
        // transient failure that scheduled a retry.
        $response->assertJsonPath('notification.attempts.0.succeeded', false);
        $response->assertJsonPath('notification.attempts.1.succeeded', false);
        $response->assertJsonPath('notification.attempts.0.classification', 'transient');
        $response->assertJsonPath('notification.attempts.1.classification', 'transient');

        // final_attempt_at is the latest attempt's completed_at —
        // matches the dead-lettered-at instant the factory was given.
        $response->assertJsonPath('final_attempt_at', '2026-04-27T10:00:00+00:00');
    }

    // -----------------------------------------------------------------------
    // 404 — same response for three failure modes
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_404_for_a_nonexistent_id(): void
    {
        $this->getJson(
            '/api/v1/dlq/'.Str::uuid()->toString(),
            $this->headersFor($this->reader),
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function returns_404_for_a_cross_tenant_id(): void
    {
        $crossTenant = $this->factory()
            ->dlqEntry($this->otherTenant)
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $this->getJson(
            "/api/v1/dlq/{$crossTenant->id()->toString()}",
            $this->headersFor($this->reader),
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function returns_404_for_a_notification_that_is_not_dead_lettered(): void
    {
        // The factory deliberately doesn't build queued notifications —
        // that's the status endpoint's territory, future work. For this
        // one case we insert directly: a queued notification belonging to
        // the caller, no dead-letter mark. The handler must refuse it.
        $id = Str::uuid()->toString();
        DB::table('notifications')->insert([
            'id' => $id,
            'api_key_id' => $this->reader->id,
            'channel' => 'email',
            'recipient' => 'someone@example.test',
            'priority' => 'normal',
            'payload' => json_encode(['subject' => 'Hi', 'text' => 'Body.']),
            'status' => 'queued',
            'correlation_id' => 'corr-'.$id,
            'idempotency_key' => 'idem-'.$id,
            'replay_of_id' => null,
            'created_at' => '2026-04-27T10:00:00Z',
            'updated_at' => '2026-04-27T10:00:00Z',
        ]);

        $this->getJson("/api/v1/dlq/{$id}", $this->headersFor($this->reader))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // -----------------------------------------------------------------------
    // 422 — id format violations
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_422_for_a_non_uuid_id(): void
    {
        $this->getJson('/api/v1/dlq/not-a-uuid', $this->headersFor($this->reader))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
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
