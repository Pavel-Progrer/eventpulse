<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Jobs;

use App\Jobs\DispatchNotificationJob;
use DateInterval;
use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Tests\Unit\Application\Notification\Channel\Doubles\FakeChannelDriver;
use EventPulse\Tests\Unit\Application\Notification\Retry\Doubles\StaticRetryPolicy;
use EventPulse\Tests\Unit\Application\Support\EnqueuedDispatch;
use EventPulse\Tests\Unit\Application\Support\FixedClock;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationDispatchQueue;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Behaviour: `DispatchNotificationJob` orchestrates the worker side of
 * the dispatch lifecycle. On a successful outcome it records success
 * and persists. On a transient outcome with retries remaining, it
 * records the failure, persists, and re-enqueues the job with
 * `availableAt = now + RetryPolicy::nextDelay()`. On a transient
 * outcome with no retries left, or on a permanent or unrecoverable
 * outcome, it dead-letters and does not re-enqueue. Domain events are
 * released only after the final persistence and re-enqueue.
 *
 * Sits in `tests/Integration/` because it exercises the job class
 * (`App\Jobs\…`) with all five collaborators wired together. Extends
 * `Tests\TestCase` to follow the repo's convention for integration-
 * shaped tests (consistent with `WebhookChannelDriverTest`,
 * `EmailChannelDriverTest`): a Laravel-bootstrapped context, even
 * though this test does not actually need DB, queue, or HTTP fixtures
 * — the in-memory test doubles cover those seams.
 *
 * Day 5's full feature test (`SubmitNotificationDispatchTest`) covers
 * the HTTP entry point; this test covers the worker entry point.
 */
#[CoversClass(DispatchNotificationJob::class)]
final class DispatchNotificationJobTest extends TestCase
{
    private InMemoryNotificationRepository $repository;

    private InMemoryNotificationDispatchQueue $dispatchQueue;

    private FakeChannelDriver $emailDriver;

    private FakeChannelDriver $webhookDriver;

    private FakeChannelDriver $smsDriver;

    private ChannelDispatcher $channelDispatcher;

    private FixedClock $clock;

    private DomainEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryNotificationRepository;
        $this->dispatchQueue = new InMemoryNotificationDispatchQueue;

        // Default each driver to "succeeded" — tests that exercise
        // failure paths reconfigure the driver they care about.
        $this->emailDriver = new FakeChannelDriver(Channel::Email, DispatchOutcome::success('mid-email'));
        $this->webhookDriver = new FakeChannelDriver(Channel::Webhook, DispatchOutcome::success('mid-webhook'));
        $this->smsDriver = new FakeChannelDriver(Channel::Sms, DispatchOutcome::success('mid-sms'));

        $this->channelDispatcher = new ChannelDispatcher([
            $this->emailDriver,
            $this->webhookDriver,
            $this->smsDriver,
        ]);

        $this->clock = FixedClock::at('2026-04-25T10:00:00Z');
        $this->eventDispatcher = new NullDomainEventDispatcher;
    }

    #[Test]
    public function throws_when_the_notification_does_not_exist(): void
    {
        // We use try/catch with a class-name string compare rather than
        // `expectException(NotificationNotFoundForDispatchException::class)`
        // because `expectException` triggers the autoloader on the
        // exception class via `class_exists()`, and we'd rather not
        // couple this test to whether that import resolves cleanly under
        // every test bootstrap. The class-name string compare verifies
        // the contract just as strictly without that coupling.
        $job = new DispatchNotificationJob(
            notificationId: NotificationId::generate()->toString(),
            correlationId: 'cor-doesnt-matter',
        );

        $thrown = null;
        try {
            $this->runJob($job, StaticRetryPolicy::uniform(max: 3, delaySeconds: 60));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Expected an exception to be thrown.');
        self::assertSame(
            'EventPulse\\Application\\Notification\\Exception\\NotificationNotFoundForDispatchException',
            $thrown::class,
            'Expected NotificationNotFoundForDispatchException to be thrown.',
        );
    }

    #[Test]
    public function on_success_persists_dispatched_state_and_does_not_reenqueue(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 4, delaySeconds: 30),
        );

        $reloaded = $this->repository->findById($notification->id());

        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::Dispatched, $reloaded->status());
        self::assertSame(0, $this->dispatchQueue->count(),
            'A successful dispatch must not re-enqueue.');
    }

    #[Test]
    public function on_transient_failure_with_retries_remaining_reenqueues_with_computed_delay(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->emailDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Transient,
            'SMTP 421 service not available',
        ));

        // max=4 means retry is allowed after attempts 1, 2, 3.
        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 4, delaySeconds: 90),
        );

        $reloaded = $this->repository->findById($notification->id());
        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::Queued, $reloaded->status(),
            'Transient failure with retries remaining must leave the aggregate in `queued`.');

        self::assertSame(1, $this->dispatchQueue->count());

        $enqueued = $this->dispatchQueue->lastEnqueued();
        self::assertInstanceOf(EnqueuedDispatch::class, $enqueued);
        self::assertTrue($enqueued->notificationId->equals($notification->id()));
        self::assertNotNull($enqueued->availableAt);

        // Now is 10:00:00; delay is 90s; expected availableAt is 10:01:30.
        $expected = $this->clock->now()->add(new DateInterval('PT90S'));
        self::assertSame($expected->getTimestamp(), $enqueued->availableAt->getTimestamp());
    }

    #[Test]
    public function on_transient_failure_with_attempts_exhausted_dead_letters_and_does_not_reenqueue(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->emailDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Transient,
            'SMTP timeout',
        ));

        // max=1 → first failure exhausts attempts → dead-letter.
        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 1, delaySeconds: 60),
        );

        $reloaded = $this->repository->findById($notification->id());
        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::DeadLettered, $reloaded->status());
        self::assertSame(0, $this->dispatchQueue->count(),
            'A dead-lettered notification must not be re-enqueued.');
    }

    #[Test]
    public function on_permanent_failure_dead_letters_immediately_and_does_not_reenqueue(): void
    {
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->emailDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Permanent,
            'recipient address rejected',
        ));

        // Even with max=10, a Permanent classification is not retry-eligible.
        // The aggregate dead-letters on the first failure.
        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 10, delaySeconds: 60),
        );

        $reloaded = $this->repository->findById($notification->id());
        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::DeadLettered, $reloaded->status());
        self::assertSame(0, $this->dispatchQueue->count());
    }

    #[Test]
    public function on_unrecoverable_failure_dead_letters_immediately_and_does_not_reenqueue(): void
    {
        $notification = NotificationMother::webhookNotification();
        $this->repository->save($notification);

        $this->webhookDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Unrecoverable,
            'webhook destination does not exist',
        ));

        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 10, delaySeconds: 60),
        );

        $reloaded = $this->repository->findById($notification->id());
        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::DeadLettered, $reloaded->status());
        self::assertSame(0, $this->dispatchQueue->count());
    }

    #[Test]
    public function reenqueues_with_priority_and_correlation_id_carried_through(): void
    {
        // The retry must carry the same correlation id and priority as
        // the original submission so a reviewer scanning logs sees a
        // continuous trace and high-priority retries continue to use
        // the high-priority queue.
        $notification = NotificationMother::smsNotification(); // priority=High
        $this->repository->save($notification);

        $this->smsDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Transient,
            'gateway congestion',
        ));

        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 3, delaySeconds: 15),
        );

        $enqueued = $this->dispatchQueue->lastEnqueued();
        self::assertInstanceOf(EnqueuedDispatch::class, $enqueued);
        self::assertTrue(
            $enqueued->correlationId->equals($notification->correlationId()),
            'Re-enqueue must carry the original correlation id.',
        );
        self::assertSame(
            $notification->priority(),
            $enqueued->priority,
            'Re-enqueue must use the original priority.',
        );
    }

    #[Test]
    public function applies_email_channel_policy_to_email_notifications(): void
    {
        // Half of the per-channel-routing assertion: a
        // `StaticRetryPolicy::perChannel` configured with email=30s
        // produces a re-enqueue with a 30-second `availableAt` for an
        // email notification. The webhook half is in the next test.
        // Splitting per channel keeps each test single-runJob: no two
        // dispatches share state through this test class's fixtures.
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->emailDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Transient, 'smtp timeout',
        ));

        $policy = StaticRetryPolicy::perChannel(
            defaultMax: 1,
            defaultDelaySeconds: 1,
            overrides: [
                Channel::Email->value => ['max' => 4, 'delay_seconds' => 30],
                Channel::Webhook->value => ['max' => 6, 'delay_seconds' => 10],
            ],
        );

        $this->runJob($this->jobFor($notification), $policy);

        $expected = $this->clock->now()->add(new DateInterval('PT30S'));
        $enqueued = $this->dispatchQueue->lastEnqueued();

        self::assertNotNull($enqueued);
        self::assertSame(
            $expected->getTimestamp(),
            $enqueued->availableAt?->getTimestamp(),
            'Email notification must use the email policy delay (30s), not the default (1s).',
        );
    }

    #[Test]
    public function applies_webhook_channel_policy_to_webhook_notifications(): void
    {
        // Other half of the per-channel-routing assertion. Same
        // policy as the previous test: email=30s, webhook=10s. A
        // webhook notification must use 10s, proving the policy is
        // consulted with the right channel argument.
        $notification = NotificationMother::webhookNotification();
        $this->repository->save($notification);

        $this->webhookDriver->willReturn(DispatchOutcome::failure(
            FailureClassification::Transient, 'http 503',
        ));

        $policy = StaticRetryPolicy::perChannel(
            defaultMax: 1,
            defaultDelaySeconds: 1,
            overrides: [
                Channel::Email->value => ['max' => 4, 'delay_seconds' => 30],
                Channel::Webhook->value => ['max' => 6, 'delay_seconds' => 10],
            ],
        );

        $this->runJob($this->jobFor($notification), $policy);

        $expected = $this->clock->now()->add(new DateInterval('PT10S'));
        $enqueued = $this->dispatchQueue->lastEnqueued();

        self::assertNotNull($enqueued);
        self::assertSame(
            $expected->getTimestamp(),
            $enqueued->availableAt?->getTimestamp(),
            'Webhook notification must use the webhook policy delay (10s), not the default (1s).',
        );
    }

    #[Test]
    public function pulls_pending_events_after_persistence_and_reenqueue(): void
    {
        // We can't easily observe ordering with a NullDomainEventDispatcher,
        // but we can verify that pendingEvents is drained after handle()
        // runs — i.e. the job did call pullPendingEvents at the end.
        $notification = NotificationMother::emailNotification();
        $this->repository->save($notification);

        $this->runJob(
            $this->jobFor($notification),
            StaticRetryPolicy::uniform(max: 4, delaySeconds: 30),
        );

        $reloaded = $this->repository->findById($notification->id());
        self::assertNotNull($reloaded);
        self::assertSame([], $reloaded->pullPendingEvents(),
            'pullPendingEvents must be empty after the job has released all events.');
    }

    /**
     * Run the job's `handle()` with the test doubles. We don't go
     * through Laravel's queue dispatcher because we're testing the
     * handle-method's logic, not Laravel's queue serialisation (which
     * has dedicated coverage in `LaravelNotificationDispatchQueueTest`).
     *
     * Named `runJob` (not `run`) because PHPUnit's `TestCase::run()` is
     * `final` and would collide.
     */
    private function runJob(DispatchNotificationJob $job, RetryPolicy $retryPolicy): void
    {
        $job->handle(
            repository: $this->repository,
            channelDispatcher: $this->channelDispatcher,
            clock: $this->clock,
            eventDispatcher: $this->eventDispatcher,
            retryPolicy: $retryPolicy,
            dispatchQueue: $this->dispatchQueue,
            logger: new NullLogger,
        );
    }

    private function jobFor(Notification $notification): DispatchNotificationJob
    {
        return new DispatchNotificationJob(
            notificationId: $notification->id()->toString(),
            correlationId: $notification->correlationId()->toString(),
        );
    }
}
