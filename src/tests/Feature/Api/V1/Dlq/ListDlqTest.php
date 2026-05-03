<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour: `GET /api/v1/dlq` returns the dead-lettered notifications
 * visible to the caller's API key, with optional filters and cursor
 * pagination, in the OpenAPI `PaginatedDlqEntries` shape.
 *
 * The test boots the full Laravel stack, runs migrations against a test
 * database (including the new Day-8 `attempts` and `dead_letter_marks`
 * tables), and exercises the route through the real
 * middleware/controller/handler/repository chain.
 *
 * Why the test seeds rows directly into `dead_letter_marks` and
 * `notifications` rather than driving the aggregate to dead-lettered
 * via the API:
 *  - The dispatch path that walks a notification to dead-lettered is
 *    queue-async and would require fake clocks plus driving every
 *    retry. That is the dispatch flow's test, not this endpoint's.
 *  - Seeding the read side directly keeps this test focused on what
 *    the endpoint reads — the API contract, the filter semantics, the
 *    pagination shape, and the tenant scoping.
 *  - The `EloquentNotificationRepository` test (separately) covers the
 *    write path that produces these rows.
 */
final class ListDlqTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $reader;        // dlq:read
    private ApiKey $otherTenant;   // dlq:read but different api key
    private ApiKey $writeOnly;     // notifications:write only — must 403

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
        $this->getJson('/api/v1/dlq')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function returns_403_when_the_api_key_lacks_dlq_read_scope(): void
    {
        $this->getJson('/api/v1/dlq', $this->headersFor($this->writeOnly))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // -----------------------------------------------------------------------
    // Empty case
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_an_empty_page_when_no_dead_letter_marks_exist(): void
    {
        $response = $this->getJson('/api/v1/dlq', $this->headersFor($this->reader))
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination' => ['next_cursor']]);

        self::assertSame([], $response->json('data'));
        self::assertNull($response->json('pagination.next_cursor'));
    }

    // -----------------------------------------------------------------------
    // Tenant scoping
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_only_rows_belonging_to_the_calling_api_key(): void
    {
        $this->seedDlqEntry($this->reader,      'reader-row',  '2026-04-27T10:00:00Z');
        $this->seedDlqEntry($this->otherTenant, 'other-row',   '2026-04-27T10:01:00Z');

        $response = $this->getJson('/api/v1/dlq', $this->headersFor($this->reader))
            ->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame('reader-row', $rows[0]['notification_id']);
    }

    // -----------------------------------------------------------------------
    // Sort order and shape
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_rows_most_recent_first_with_the_documented_shape(): void
    {
        $this->seedDlqEntry($this->reader, 'older-row',  '2026-04-27T09:00:00Z', reason: 'unrecoverable_error');
        $this->seedDlqEntry($this->reader, 'newer-row',  '2026-04-27T10:00:00Z', reason: 'max_retries_exceeded');

        $response = $this->getJson('/api/v1/dlq', $this->headersFor($this->reader))
            ->assertOk();

        $rows = $response->json('data');
        self::assertCount(2, $rows);

        self::assertSame('newer-row', $rows[0]['notification_id']);
        self::assertSame('older-row', $rows[1]['notification_id']);

        // Shape per OpenAPI DlqEntry — every documented key present.
        foreach (['id', 'notification_id', 'reason', 'channel', 'created_at',
                  'final_attempt_at', 'replayed_at', 'replay_notification_id'] as $key) {
            self::assertArrayHasKey($key, $rows[0], "missing key: $key");
        }
    }

    // -----------------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------------

    #[Test]
    public function filters_by_reason(): void
    {
        $this->seedDlqEntry($this->reader, 'a', '2026-04-27T10:00:00Z', reason: 'max_retries_exceeded');
        $this->seedDlqEntry($this->reader, 'b', '2026-04-27T10:01:00Z', reason: 'unrecoverable_error');

        $response = $this->getJson(
            '/api/v1/dlq?reason=unrecoverable_error',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame('b', $rows[0]['notification_id']);
    }

    #[Test]
    public function filters_by_channel(): void
    {
        $this->seedDlqEntry($this->reader, 'a', '2026-04-27T10:00:00Z', channel: 'email');
        $this->seedDlqEntry($this->reader, 'b', '2026-04-27T10:01:00Z', channel: 'sms');
        $this->seedDlqEntry($this->reader, 'c', '2026-04-27T10:02:00Z', channel: 'webhook');

        $response = $this->getJson(
            '/api/v1/dlq?channel=sms',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame('b', $rows[0]['notification_id']);
    }

    #[Test]
    public function filters_by_date_range(): void
    {
        $this->seedDlqEntry($this->reader, 'before', '2026-04-26T23:00:00Z');
        $this->seedDlqEntry($this->reader, 'within', '2026-04-27T10:00:00Z');
        $this->seedDlqEntry($this->reader, 'after',  '2026-04-28T01:00:00Z');

        $response = $this->getJson(
            '/api/v1/dlq?created_after=2026-04-27T00:00:00Z&created_before=2026-04-28T00:00:00Z',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame('within', $rows[0]['notification_id']);
    }

    #[Test]
    public function rejects_invalid_filter_values_with_422(): void
    {
        $this->getJson('/api/v1/dlq?channel=carrier_pigeon', $this->headersFor($this->reader))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/dlq?limit=0', $this->headersFor($this->reader))
            ->assertStatus(422);

        $this->getJson('/api/v1/dlq?limit=101', $this->headersFor($this->reader))
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------------

    #[Test]
    public function paginates_with_a_cursor(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedDlqEntry(
                $this->reader,
                "row-$i",
                sprintf('2026-04-27T10:%02d:00Z', $i),
            );
        }

        // Page 1: limit 2.
        $first = $this->getJson('/api/v1/dlq?limit=2', $this->headersFor($this->reader))
            ->assertOk();
        $firstIds  = array_column($first->json('data'), 'notification_id');
        $cursor1   = $first->json('pagination.next_cursor');

        self::assertCount(2, $firstIds);
        self::assertNotNull($cursor1);

        // Page 2: cursor.
        $second = $this->getJson(
            '/api/v1/dlq?limit=2&cursor=' . urlencode($cursor1),
            $this->headersFor($this->reader),
        )->assertOk();
        $secondIds = array_column($second->json('data'), 'notification_id');
        $cursor2   = $second->json('pagination.next_cursor');

        self::assertCount(2, $secondIds);
        self::assertNotNull($cursor2);

        // Page 3: cursor — last one, single row, no further cursor.
        $third = $this->getJson(
            '/api/v1/dlq?limit=2&cursor=' . urlencode($cursor2),
            $this->headersFor($this->reader),
        )->assertOk();
        $thirdIds  = array_column($third->json('data'), 'notification_id');

        self::assertCount(1, $thirdIds);
        self::assertNull($third->json('pagination.next_cursor'));

        self::assertCount(
            5,
            array_unique(array_merge($firstIds, $secondIds, $thirdIds)),
            'paginated ids must not repeat across pages',
        );
    }

    // -----------------------------------------------------------------------
    // Test seam: insert a notifications row + a dead_letter_marks row.
    // The full aggregate write path is covered separately; here we
    // need rows that the read query joins against, no more.
    // -----------------------------------------------------------------------

    private function seedDlqEntry(
        ApiKey $apiKey,
        string $notificationId,
        string $deadLetteredAt,
        string $reason = 'max_retries_exceeded',
        string $channel = 'email',
    ): void {
        // notifications row in dead_lettered status. Any payload shape
        // valid for `email` is fine — this query never reads it.
        DB::table('notifications')->insert([
            'id'              => $notificationId,
            'api_key_id'      => $apiKey->id,
            'channel'         => $channel,
            'recipient'       => 'recipient@example.test',
            'priority'        => 'normal',
            'payload'         => json_encode(['subject' => 's', 'body_text' => 'b']),
            'status'          => 'dead_lettered',
            'correlation_id'  => 'corr-' . $notificationId,
            'idempotency_key' => 'idem-' . $notificationId,
            'replay_of_id'    => null,
            'created_at'      => $deadLetteredAt,
            'updated_at'      => $deadLetteredAt,
        ]);

        DB::table('dead_letter_marks')->insert([
            'id'                     => 'dlm-' . $notificationId,
            'notification_id'        => $notificationId,
            'reason'                 => $reason,
            'dead_lettered_at'       => $deadLetteredAt,
            'replay_notification_id' => null,
            'replayed_at'            => null,
            'created_at'             => $deadLetteredAt,
            'updated_at'             => $deadLetteredAt,
        ]);
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
