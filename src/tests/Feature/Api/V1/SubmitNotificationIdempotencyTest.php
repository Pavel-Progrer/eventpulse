<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchNotificationJob;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotification;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behaviour: the `Idempotency-Key` header gives clients an at-most-once
 * submission contract for `POST /api/v1/notifications`:
 *
 *   - Same key + same body                  → 200 + same row + no second job (replay).
 *   - Same key + different body             → 409 + no second row + no second job.
 *   - Same key + different api key (tenant) → 202 + two rows + two jobs (scoped).
 *
 * Replay must echo the *original* correlation id, not whatever the caller
 * sent on the replay request — tracing identity belongs to the first
 * submission. Conflicts come back as `IDEMPOTENCY_CONFLICT` with the
 * offending key in `error.details.idempotency_key`.
 */
final class SubmitNotificationIdempotencyTest extends SubmitNotificationFeatureTestCase
{
    // ---------------------------------------------------------------------------
    // Replay (200, same body)
    // ---------------------------------------------------------------------------

    #[Test]
    public function an_identical_replay_returns_200_with_the_same_id(): void
    {
        $first = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-aaa'),
        );
        $first->assertStatus(202);

        $second = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-aaa'),
        );
        $second->assertStatus(200);
        $second->assertJson(['id' => $first->json('id')]);
    }

    #[Test]
    public function an_identical_replay_does_not_persist_a_second_row(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-bbb'),
        )->assertStatus(202);

        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-bbb'),
        )->assertStatus(200);

        self::assertSame(1, EloquentNotification::query()->count());
    }

    #[Test]
    public function an_identical_replay_does_not_enqueue_a_second_job(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-ccc'),
        )->assertStatus(202);

        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-replay-ccc'),
        )->assertStatus(200);

        Bus::assertDispatchedTimes(DispatchNotificationJob::class, 1);
    }

    #[Test]
    public function the_replay_response_echoes_the_original_correlation_id(): void
    {
        $first = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor(
                $this->writerKey,
                idempotencyKey: 'idem-replay-ddd',
                correlationId:  'req_first_request',
            ),
        );
        $first->assertStatus(202);
        $firstCorrelation = $first->json('correlation_id');

        // A different correlation id on the replay must not overwrite the
        // original — tracing identity belongs to the original submission.
        $second = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor(
                $this->writerKey,
                idempotencyKey: 'idem-replay-ddd',
                correlationId:  'req_second_request_different',
            ),
        );
        $second->assertStatus(200);
        $second->assertJson(['correlation_id' => $firstCorrelation]);
    }

    // ---------------------------------------------------------------------------
    // Conflict (409, different body)
    // ---------------------------------------------------------------------------

    #[Test]
    public function a_conflicting_payload_returns_409(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-aaa'),
        )->assertStatus(202);

        $different            = $this->validEmailBody();
        $different['payload'] = [
            'subject'   => 'Different subject',
            'body_text' => 'Different body',
        ];

        $response = $this->postJson(
            '/api/v1/notifications',
            $different,
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-aaa'),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $response->assertJsonPath('error.details.idempotency_key', 'idem-conflict-aaa');
    }

    #[Test]
    public function a_conflicting_recipient_returns_409(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-bbb'),
        )->assertStatus(202);

        $different              = $this->validEmailBody();
        $different['recipient'] = 'someone-else@example.com';

        $this->postJson(
            '/api/v1/notifications',
            $different,
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-bbb'),
        )->assertStatus(409);
    }

    #[Test]
    public function a_conflict_does_not_persist_or_enqueue_anything(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-ccc'),
        )->assertStatus(202);

        $different              = $this->validEmailBody();
        $different['recipient'] = 'someone-else@example.com';

        $this->postJson(
            '/api/v1/notifications',
            $different,
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-conflict-ccc'),
        )->assertStatus(409);

        self::assertSame(1, EloquentNotification::query()->count());
        Bus::assertDispatchedTimes(DispatchNotificationJob::class, 1);
    }

    // ---------------------------------------------------------------------------
    // Scoping — same key under different api keys does not collide
    // ---------------------------------------------------------------------------

    #[Test]
    public function the_same_key_under_two_api_keys_yields_two_independent_notifications(): void
    {
        $first = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'shared-tenant-key-001'),
        );
        $first->assertStatus(202);

        $second = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->otherWriterKey, idempotencyKey: 'shared-tenant-key-001'),
        );
        $second->assertStatus(202);

        self::assertNotSame($first->json('id'), $second->json('id'));
        self::assertSame(2, EloquentNotification::query()->count());
        Bus::assertDispatchedTimes(DispatchNotificationJob::class, 2);
    }
}