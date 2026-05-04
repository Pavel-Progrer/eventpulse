<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
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
 * Seeding note: the `payload` column carries the *domain* shape
 * (`text`/`html`), not the wire shape (`body_text`/`body_html`) the
 * OpenAPI spec uses. The wire-to-domain mapping happens in
 * `SubmitNotificationController::mapPayloadForDomain`. Seeding directly
 * into the table means using the domain shape, otherwise reconstitute
 * fails on `NotificationPayload::validateEmail` and the endpoint 500s
 * before the handler's tenant/status checks ever run.
 */
final class GetDlqTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $reader;
    private ApiKey $otherTenant;
    private ApiKey $writeOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_001',
            'scopes'     => ['dlq:read'],
            'status'     => 'active',
            'label'      => 'reader A',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_002',
            'scopes'     => ['dlq:read'],
            'status'     => 'active',
            'label'      => 'reader B',
        ]);

        $this->writeOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_only_001',
            'scopes'     => ['notifications:write'],
            'status'     => 'active',
            'label'      => 'writer with no DLQ access',
        ]);
    }

    // -----------------------------------------------------------------------
    // Auth and authorisation
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_401_without_a_bearer_token(): void
    {
        $this->getJson('/api/v1/dlq/' . Str::uuid()->toString())
            ->assertStatus(401);
    }

    #[Test]
    public function returns_403_when_the_api_key_lacks_dlq_read_scope(): void
    {
        $this->getJson(
            '/api/v1/dlq/' . Str::uuid()->toString(),
            $this->headersFor($this->writeOnly),
        )->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_the_full_dead_lettered_notification(): void
    {
        $id = Str::uuid()->toString();
        $this->seedDeadLetteredNotificationWithAttempts(
            apiKey:         $this->reader,
            notificationId: $id,
            deadLetteredAt: '2026-04-27T10:00:00Z',
            attempts:       [
                ['number' => 1, 'started_at' => '2026-04-27T09:50:00Z', 'completed_at' => '2026-04-27T09:50:05Z',
                 'succeeded' => false, 'classification' => 'transient', 'reason' => 'connection refused'],
                ['number' => 2, 'started_at' => '2026-04-27T09:55:00Z', 'completed_at' => '2026-04-27T09:55:05Z',
                 'succeeded' => false, 'classification' => 'permanent', 'reason' => 'destination rejected'],
            ],
        );

        $response = $this->getJson(
            "/api/v1/dlq/{$id}",
            $this->headersFor($this->reader),
        )->assertOk();

        // Outer DlqEntry shape.
        $response->assertJsonPath('id',                     $id);
        $response->assertJsonPath('notification_id',        $id);
        $response->assertJsonPath('reason',                 'max_retries_exceeded');
        $response->assertJsonPath('channel',                'email');
        $response->assertJsonPath('replay_notification_id', null);
        $response->assertJsonPath('replayed_at',            null);

        // Embedded notification.
        $response->assertJsonPath('notification.id',     $id);
        $response->assertJsonPath('notification.status', 'dead_lettered');
        $response->assertJsonPath('notification.channel', 'email');

        // Attempts present in order.
        $response->assertJsonCount(2, 'notification.attempts');
        $response->assertJsonPath('notification.attempts.0.number',         1);
        $response->assertJsonPath('notification.attempts.0.classification', 'transient');
        $response->assertJsonPath('notification.attempts.1.number',         2);
        $response->assertJsonPath('notification.attempts.1.classification', 'permanent');

        // final_attempt_at is the latest attempt's completed_at.
        $response->assertJsonPath('final_attempt_at', '2026-04-27T09:55:05+00:00');
    }

    // -----------------------------------------------------------------------
    // 404 — same response for three failure modes
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_404_for_a_nonexistent_id(): void
    {
        $this->getJson(
            '/api/v1/dlq/' . Str::uuid()->toString(),
            $this->headersFor($this->reader),
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function returns_404_for_a_cross_tenant_id(): void
    {
        $id = Str::uuid()->toString();
        $this->seedDeadLetteredNotificationWithAttempts(
            apiKey:         $this->otherTenant,
            notificationId: $id,
            deadLetteredAt: '2026-04-27T10:00:00Z',
        );

        $this->getJson("/api/v1/dlq/{$id}", $this->headersFor($this->reader))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function returns_404_for_a_notification_that_is_not_dead_lettered(): void
    {
        $id = Str::uuid()->toString();
        // Insert a notifications row in `queued` status, no dead_letter_marks
        // row. The handler must refuse this id even though it exists.
        // Domain-shape payload (`text`, not `body_text`) so hydrate succeeds —
        // the handler's job is to reject the row, not the persistence layer's.
        DB::table('notifications')->insert([
            'id'              => $id,
            'api_key_id'      => $this->reader->id,
            'channel'         => 'email',
            'recipient'       => 'someone@example.test',
            'priority'        => 'normal',
            'payload'         => json_encode(['subject' => 'Hi', 'text' => 'Body.']),
            'status'          => 'queued',
            'correlation_id'  => 'corr-' . $id,
            'idempotency_key' => 'idem-' . $id,
            'replay_of_id'    => null,
            'created_at'      => '2026-04-27T10:00:00Z',
            'updated_at'      => '2026-04-27T10:00:00Z',
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

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $attempts
     */
    private function seedDeadLetteredNotificationWithAttempts(
        ApiKey $apiKey,
        string $notificationId,
        string $deadLetteredAt,
        array $attempts = [],
    ): void {
        DB::table('notifications')->insert([
            'id'              => $notificationId,
            'api_key_id'      => $apiKey->id,
            'channel'         => 'email',
            'recipient'       => 'someone@example.test',
            'priority'        => 'normal',
            // Domain shape — see class docblock.
            'payload'         => json_encode(['subject' => 'Hi', 'text' => 'Body.']),
            'status'          => 'dead_lettered',
            'correlation_id'  => 'corr-' . $notificationId,
            'idempotency_key' => 'idem-' . $notificationId,
            'replay_of_id'    => null,
            'created_at'      => $deadLetteredAt,
            'updated_at'      => $deadLetteredAt,
        ]);

        DB::table('dead_letter_marks')->insert([
            'id'                     => $notificationId, // alignment with the controller's id-equals-id projection
            'notification_id'        => $notificationId,
            'reason'                 => 'max_retries_exceeded',
            'dead_lettered_at'       => $deadLetteredAt,
            'replay_notification_id' => null,
            'replayed_at'            => null,
            'created_at'             => $deadLetteredAt,
            'updated_at'             => $deadLetteredAt,
        ]);

        foreach ($attempts as $attempt) {
            DB::table('attempts')->insert([
                'id'              => Str::uuid()->toString(),
                'notification_id' => $notificationId,
                'number'          => $attempt['number'],
                'started_at'      => $attempt['started_at'],
                'completed_at'    => $attempt['completed_at'] ?? null,
                'succeeded'       => $attempt['succeeded']    ?? null,
                'classification'  => $attempt['classification'] ?? null,
                'reason'          => $attempt['reason']       ?? null,
                'created_at'      => $attempt['started_at'],
                'updated_at'      => $attempt['completed_at'] ?? $attempt['started_at'],
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(ApiKey $key): array
    {
        return [
            'Authorization' => "Bearer {$key->identifier}",
            'Accept'        => 'application/json',
        ];
    }
}
