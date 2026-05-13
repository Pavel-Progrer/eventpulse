<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Notification;

use App\Models\ApiKey;
use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Factories\UsesNotificationFactory;
use Tests\TestCase;

/**
 * Behaviour: `GET /api/v1/notifications/{id}` returns the full notification
 * aggregate for the calling tenant; `GET /api/v1/notifications` returns a
 * paginated list with filter and cursor support.
 *
 * Both endpoints enforce:
 *  - authentication (401 without Bearer),
 *  - scope gate (`notifications:read`),
 *  - tenant isolation (other-tenant resources return 404).
 *
 * Fixtures are built through `NotificationFactory`, which drives the real
 * domain + repository write path — no seed-vs-production drift on column
 * shapes or recipient format.
 */
final class NotificationsReadTest extends TestCase
{
    use RefreshDatabase;
    use UsesNotificationFactory;

    private ApiKey $reader;
    private ApiKey $writer;
    private ApiKey $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = ApiKey::query()->create([
            'identifier' => 'ep_live_notif_reader_001',
            'scopes'     => ['notifications:read'],
            'status'     => 'active',
            'label'      => 'reader',
        ]);

        $this->writer = ApiKey::query()->create([
            'identifier' => 'ep_live_notif_writer_001',
            'scopes'     => ['notifications:write', 'notifications:read'],
            'status'     => 'active',
            'label'      => 'writer',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_notif_other_001',
            'scopes'     => ['notifications:read'],
            'status'     => 'active',
            'label'      => 'other tenant',
        ]);
    }

    // =========================================================================
    // GET /api/v1/notifications/{id}
    // =========================================================================

    #[Test]
    public function get_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000')
            ->assertStatus(401);
    }

    #[Test]
    public function get_returns_403_when_caller_lacks_read_scope(): void
    {
        $writeOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_write_only_001',
            'scopes'     => ['notifications:write'],
            'status'     => 'active',
            'label'      => 'write only',
        ]);

        $this->getJson(
            '/api/v1/notifications/00000000-0000-0000-0000-000000000000',
            $this->headersFor($writeOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function get_returns_404_for_unknown_id(): void
    {
        $this->getJson(
            '/api/v1/notifications/00000000-0000-0000-0000-000000000000',
            $this->headersFor($this->reader),
        )->assertStatus(404)
         ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function get_returns_404_for_another_tenants_notification(): void
    {
        // Writer owns this notification; reader is a different tenant.
        $notification = $this->factory()->dlqEntry($this->writer)->save();

        $this->getJson(
            '/api/v1/notifications/' . $notification->id()->toString(),
            $this->headersFor($this->reader),
        )->assertStatus(404);
    }

    #[Test]
    public function get_returns_200_with_full_notification_shape(): void
    {
        $notification = $this->factory()->dlqEntry($this->reader)->save();
        $id           = $notification->id()->toString();

        $response = $this->getJson(
            "/api/v1/notifications/{$id}",
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $response->assertJsonStructure([
            'id', 'channel', 'recipient', 'priority', 'status',
            'idempotency_key', 'correlation_id', 'created_at', 'attempts',
        ]);

        $response->assertJsonPath('id', $id);
        $response->assertJsonPath('channel', 'email');
        $response->assertJsonPath('status', 'dead_lettered');
    }

    #[Test]
    public function get_includes_attempt_history(): void
    {
        $notification = $this->factory()
            ->dlqEntry($this->reader)
            ->withPrecedingRetries(1)
            ->save();

        $response = $this->getJson(
            '/api/v1/notifications/' . $notification->id()->toString(),
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $attempts = $response->json('attempts');
        self::assertIsArray($attempts);
        // 1 preceding retry + 1 final attempt = 2 total.
        self::assertCount(2, $attempts);

        $response->assertJsonStructure([
            'attempts' => [
                '*' => ['number', 'started_at', 'completed_at', 'succeeded', 'classification', 'reason'],
            ],
        ]);
    }

    #[Test]
    public function get_does_not_include_payload_by_default(): void
    {
        $notification = $this->factory()->dlqEntry($this->reader)->save();

        $response = $this->getJson(
            '/api/v1/notifications/' . $notification->id()->toString(),
            $this->headersFor($this->reader),
        )->assertStatus(200);

        // Payload is omitted unless the caller has admin scope + include=payload.
        self::assertArrayNotHasKey('payload', $response->json());
    }

    // =========================================================================
    // GET /api/v1/notifications (list)
    // =========================================================================

    #[Test]
    public function list_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertStatus(401);
    }

    #[Test]
    public function list_returns_403_when_caller_lacks_read_scope(): void
    {
        $writeOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_write_only_002',
            'scopes'     => ['notifications:write'],
            'status'     => 'active',
            'label'      => 'write only',
        ]);

        $this->getJson(
            '/api/v1/notifications',
            $this->headersFor($writeOnly),
        )->assertStatus(403);
    }

    #[Test]
    public function list_returns_empty_data_when_no_notifications_exist(): void
    {
        $response = $this->getJson(
            '/api/v1/notifications',
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $response->assertJsonStructure(['data', 'pagination' => ['next_cursor']]);
        $response->assertJsonCount(0, 'data');
        self::assertNull($response->json('pagination.next_cursor'));
    }

    #[Test]
    public function list_returns_only_callers_notifications(): void
    {
        $this->factory()->dlqEntry($this->reader)->save();
        $this->factory()->dlqEntry($this->reader)->save();
        $this->factory()->dlqEntry($this->otherTenant)->save();

        $response = $this->getJson(
            '/api/v1/notifications',
            $this->headersFor($this->reader),
        )->assertStatus(200);

        // Reader has 2; the other tenant's row must not appear.
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function list_filters_by_status(): void
    {
        $this->factory()->dlqEntry($this->reader)->save(); // dead_lettered

        // dead_lettered filter matches.
        $this->getJson(
            '/api/v1/notifications?status[]=dead_lettered',
            $this->headersFor($this->reader),
        )->assertStatus(200)
         ->assertJsonCount(1, 'data');

        // queued filter matches nothing.
        $this->getJson(
            '/api/v1/notifications?status[]=queued',
            $this->headersFor($this->reader),
        )->assertStatus(200)
         ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function list_filters_by_channel(): void
    {
        $this->factory()->dlqEntry($this->reader)->withChannel(Channel::Email)->save();
        $this->factory()->dlqEntry($this->reader)->withChannel(Channel::Sms)->save();

        $response = $this->getJson(
            '/api/v1/notifications?channel[]=email',
            $this->headersFor($this->reader),
        )->assertStatus(200)
         ->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.channel', 'email');
    }

    #[Test]
    public function list_paginates_correctly_with_cursor(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->factory()->dlqEntry($this->reader)->save();
        }

        // First page: 2 of 3.
        $page1 = $this->getJson(
            '/api/v1/notifications?limit=2',
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $page1->assertJsonCount(2, 'data');
        $cursor = $page1->json('pagination.next_cursor');
        self::assertNotNull($cursor, 'Expected a next_cursor when more rows exist');

        // Second page: remaining 1.
        $page2 = $this->getJson(
            "/api/v1/notifications?limit=2&cursor={$cursor}",
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $page2->assertJsonCount(1, 'data');
        self::assertNull($page2->json('pagination.next_cursor'));
    }

    #[Test]
    public function list_cursor_pages_are_non_overlapping(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->factory()->dlqEntry($this->reader)->save();
        }

        $page1 = $this->getJson(
            '/api/v1/notifications?limit=2',
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $cursor = $page1->json('pagination.next_cursor');

        $page2 = $this->getJson(
            "/api/v1/notifications?limit=2&cursor={$cursor}",
            $this->headersFor($this->reader),
        )->assertStatus(200);

        $ids1 = array_column($page1->json('data'), 'id');
        $ids2 = array_column($page2->json('data'), 'id');

        // Zero overlap between pages — no row appears twice.
        self::assertEmpty(array_intersect($ids1, $ids2), 'Pages must not share rows');
    }

    #[Test]
    public function list_rejects_invalid_status_value(): void
    {
        $this->getJson(
            '/api/v1/notifications?status[]=not_a_real_status',
            $this->headersFor($this->reader),
        )->assertStatus(422);
    }

    #[Test]
    public function list_rejects_limit_above_max(): void
    {
        $this->getJson(
            '/api/v1/notifications?limit=201',
            $this->headersFor($this->reader),
        )->assertStatus(422);
    }

    #[Test]
    public function list_rejects_limit_below_one(): void
    {
        $this->getJson(
            '/api/v1/notifications?limit=0',
            $this->headersFor($this->reader),
        )->assertStatus(422);
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
}
