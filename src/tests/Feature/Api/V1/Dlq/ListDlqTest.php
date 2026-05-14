<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Factories\UsesNotificationFactory;
use Tests\TestCase;

/**
 * Behaviour: `GET /api/v1/dlq` returns the dead-lettered notifications
 * visible to the caller's API key, with optional filters and cursor
 * pagination, in the OpenAPI `PaginatedDlqEntries` shape.
 *
 * Test fixtures are built through `NotificationFactory`, which drives
 * the real `Notification::request()` + repository `save()` path. That
 * means the row shapes in `notifications`, `attempts`, and
 * `dead_letter_marks` are exactly what production code would write —
 * no chance of seed-vs-production drift on payload shape, recipient
 * format, or any other column the controller transforms.
 */
final class ListDlqTest extends TestCase
{
    use RefreshDatabase;
    use UsesNotificationFactory;

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
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
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
        $readerNotification = $this->factory()
            ->dlqEntry($this->reader)
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $this->factory()
            ->dlqEntry($this->otherTenant)
            ->deadLetteredAt('2026-04-27T10:01:00Z')
            ->save();

        $response = $this->getJson('/api/v1/dlq', $this->headersFor($this->reader))
            ->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame($readerNotification->id()->toString(), $rows[0]['notification_id']);
    }

    // -----------------------------------------------------------------------
    // Sort order and shape
    // -----------------------------------------------------------------------

    #[Test]
    public function returns_rows_most_recent_first_with_the_documented_shape(): void
    {
        $older = $this->factory()
            ->dlqEntry($this->reader)
            ->withReason('unrecoverable_error')
            ->deadLetteredAt('2026-04-27T09:00:00Z')
            ->save();

        $newer = $this->factory()
            ->dlqEntry($this->reader)
            ->withReason('max_retries_exceeded')
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $response = $this->getJson('/api/v1/dlq', $this->headersFor($this->reader))
            ->assertOk();

        $rows = $response->json('data');
        self::assertCount(2, $rows);

        self::assertSame($newer->id()->toString(), $rows[0]['notification_id']);
        self::assertSame($older->id()->toString(), $rows[1]['notification_id']);

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
        $this->factory()
            ->dlqEntry($this->reader)
            ->withReason('max_retries_exceeded')
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $unrecoverable = $this->factory()
            ->dlqEntry($this->reader)
            ->withReason('unrecoverable_error')
            ->deadLetteredAt('2026-04-27T10:01:00Z')
            ->save();

        $response = $this->getJson(
            '/api/v1/dlq?reason=unrecoverable_error',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame($unrecoverable->id()->toString(), $rows[0]['notification_id']);
    }

    #[Test]
    public function filters_by_channel(): void
    {
        $this->factory()
            ->dlqEntry($this->reader)
            ->withChannel(Channel::Email)
            ->deadLetteredAt('2026-04-27T10:00:00Z')
            ->save();

        $sms = $this->factory()
            ->dlqEntry($this->reader)
            ->withChannel(Channel::Sms)
            ->deadLetteredAt('2026-04-27T10:01:00Z')
            ->save();

        $this->factory()
            ->dlqEntry($this->reader)
            ->withChannel(Channel::Webhook)
            ->deadLetteredAt('2026-04-27T10:02:00Z')
            ->save();

        $response = $this->getJson(
            '/api/v1/dlq?channel=sms',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame($sms->id()->toString(), $rows[0]['notification_id']);
    }

    #[Test]
    public function filters_by_date_range(): void
    {
        $this->factory()->dlqEntry($this->reader)->deadLetteredAt('2026-04-26T23:00:00Z')->save();
        $within = $this->factory()->dlqEntry($this->reader)->deadLetteredAt('2026-04-27T10:00:00Z')->save();
        $this->factory()->dlqEntry($this->reader)->deadLetteredAt('2026-04-28T01:00:00Z')->save();

        $response = $this->getJson(
            '/api/v1/dlq?created_after=2026-04-27T00:00:00Z&created_before=2026-04-28T00:00:00Z',
            $this->headersFor($this->reader),
        )->assertOk();

        $rows = $response->json('data');
        self::assertCount(1, $rows);
        self::assertSame($within->id()->toString(), $rows[0]['notification_id']);
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
        /** @var list<Notification> $notifications */
        $notifications = [];
        for ($i = 1; $i <= 5; $i++) {
            $notifications[] = $this->factory()
                ->dlqEntry($this->reader)
                ->deadLetteredAt(sprintf('2026-04-27T10:%02d:00Z', $i))
                ->save();
        }
        $expectedIds = array_map(static fn (Notification $n): string => $n->id()->toString(), $notifications);

        // Page 1: limit 2.
        $first = $this->getJson('/api/v1/dlq?limit=2', $this->headersFor($this->reader))
            ->assertOk();
        $firstIds = array_column($first->json('data'), 'notification_id');
        $cursor1  = $first->json('pagination.next_cursor');

        self::assertCount(2, $firstIds);
        self::assertNotNull($cursor1);

        // Page 2.
        $second = $this->getJson(
            '/api/v1/dlq?limit=2&cursor=' . urlencode($cursor1),
            $this->headersFor($this->reader),
        )->assertOk();
        $secondIds = array_column($second->json('data'), 'notification_id');
        $cursor2   = $second->json('pagination.next_cursor');

        self::assertCount(2, $secondIds);
        self::assertNotNull($cursor2);

        // Page 3 — final, no further cursor.
        $third = $this->getJson(
            '/api/v1/dlq?limit=2&cursor=' . urlencode($cursor2),
            $this->headersFor($this->reader),
        )->assertOk();
        $thirdIds = array_column($third->json('data'), 'notification_id');

        self::assertCount(1, $thirdIds);
        self::assertNull($third->json('pagination.next_cursor'));

        self::assertCount(
            5,
            array_unique(array_merge($firstIds, $secondIds, $thirdIds)),
            'paginated ids must not repeat across pages',
        );
        self::assertEqualsCanonicalizing(
            $expectedIds,
            array_merge($firstIds, $secondIds, $thirdIds),
            'every saved id appears in some page',
        );
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
