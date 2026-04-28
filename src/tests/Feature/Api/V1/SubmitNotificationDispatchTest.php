<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchNotificationJob;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behaviour: `POST /api/v1/notifications` enqueues a `DispatchNotificationJob`
 * after persistence, and the job carries the values the worker needs to
 * dispatch correctly:
 *
 *   - One job per fresh submission.
 *   - The job's notification id matches the persisted aggregate.
 *   - The job's correlation id matches the response's correlation id.
 *   - The job is routed to a queue named after the notification's priority.
 *
 * Bus is faked at the boundary (`Bus::fake()`), so we observe enqueue without
 * executing the job. Day 5 will introduce the actual dispatch logic; that is
 * the appropriate point to test the worker's `handle()` body. Until then,
 * "queue infrastructure works" means "submission causes a job to land in the
 * right queue with the right payload" — which is what this suite asserts.
 */
final class SubmitNotificationDispatchTest extends SubmitNotificationFeatureTestCase
{
    #[Test]
    public function a_fresh_submission_enqueues_a_single_dispatch_job(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-dispatch-001'),
        );

        $response->assertStatus(202);
        Bus::assertDispatchedTimes(DispatchNotificationJob::class, 1);
    }

    #[Test]
    public function the_dispatched_job_carries_the_persisted_notification_id(): void
    {
        $response = $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-dispatch-002'),
        );

        $response->assertStatus(202);
        $persistedId = $response->json('id');

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            static fn(DispatchNotificationJob $job): bool => $job->notificationId === $persistedId,
        );
    }

    #[Test]
    public function high_priority_routes_to_the_high_queue(): void
    {
        $body             = $this->validEmailBody();
        $body['priority'] = 'high';

        $this->postJson(
            '/api/v1/notifications',
            $body,
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-dispatch-003'),
        )->assertStatus(202);

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            static fn(DispatchNotificationJob $job): bool
                => $job->queue === 'notifications-high',
        );
    }

    #[Test]
    public function default_priority_routes_to_the_default_queue(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-dispatch-004'),
        )->assertStatus(202);

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            static fn(DispatchNotificationJob $job): bool
                => $job->queue === 'notifications-default',
        );
    }

    #[Test]
    public function low_priority_routes_to_the_low_queue(): void
    {
        $body             = $this->validEmailBody();
        $body['priority'] = 'low';

        $this->postJson(
            '/api/v1/notifications',
            $body,
            $this->headersFor($this->writerKey, idempotencyKey: 'idem-dispatch-005'),
        )->assertStatus(202);

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            static fn(DispatchNotificationJob $job): bool
                => $job->queue === 'notifications-low',
        );
    }

    #[Test]
    public function the_dispatched_job_carries_the_correlation_id(): void
    {
        $this->postJson(
            '/api/v1/notifications',
            $this->validEmailBody(),
            $this->headersFor(
                $this->writerKey,
                idempotencyKey: 'idem-dispatch-006',
                correlationId:  'req_correlation_xyz_789',
            ),
        )->assertStatus(202);

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            static fn(DispatchNotificationJob $job): bool
                => $job->correlationId === 'req_correlation_xyz_789',
        );
    }
}