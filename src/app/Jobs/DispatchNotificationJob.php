<?php

declare(strict_types=1);

namespace App\Jobs;

use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Exception\NotificationNotFoundForDispatchException;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Worker job: dispatch a previously persisted notification through its channel.
 *
 * The asynchronous half of the submission flow:
 *  - HTTP path:  validate → persist → enqueue (this job).
 *  - Worker path (this class): load → claim attempt → persist → dispatch
 *    via channel strategy → record outcome → persist → release events
 *    → if the aggregate decided to retry, re-enqueue with delay.
 *
 * Day 6 lands the retry policy and re-enqueue. Compared to Day 5:
 *  - the `MAX_ATTEMPTS_DAY_5 = 1` constant is gone; the job consults
 *    the injected `RetryPolicy` for the channel's ceiling
 *    (specification §5.2: webhook 6, email 4, sms 3),
 *  - the `RETRY_AFTER_SECONDS_DAY_5 = 60` constant is gone; the job
 *    consults `RetryPolicy::nextDelay()`, which applies the
 *    exponential-with-jitter formula from spec §5.2,
 *  - on a transient failure the aggregate decides whether to retry
 *    (transitions to `Queued`) or dead-letter; if it retries, the job
 *    re-enqueues itself via `NotificationDispatchQueue` with the
 *    `availableAt` timestamp it just passed to `recordFailure`.
 *
 * What is *not* in Day 6 and is documented as a "trigger to revisit"
 * in ADR-0005:
 *  - HTTP `Retry-After` honouring on webhook 408/429 responses. The
 *    spec calls for it; today the formula always wins. Adding it is a
 *    field on `DispatchOutcome` plus a one-line preference in this
 *    method.
 *  - Structured-log dispatcher and DLQ admin endpoint (Day 8).
 *  - HMAC webhook signing (Day 9).
 *
 * Why a string `notificationId` rather than a `NotificationId` value
 * object: Laravel's queue serialiser persists the job's constructor
 * arguments as JSON. Value objects with private constructors do not
 * round-trip cleanly through `serialize()` / `unserialize()` in all
 * queue drivers (database/redis/SQS each have their own quirks). A
 * primitive string is the lingua franca; the worker re-hydrates the VO
 * via `NotificationId::fromString()`, which re-validates the format.
 *
 * Why a separate `correlationId` argument rather than reading it off
 * the loaded aggregate: logging *before* the load (e.g. "loading
 * notification {id}" on entry) needs a correlation id, and we don't
 * yet have the aggregate. Keeping the correlation id on the job
 * payload means every log line on the worker side can include it from
 * the very first instruction.
 *
 * @see EventPulse\Application\Notification\NotificationDispatchQueue (port)
 * @see EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue (adapter)
 * @see EventPulse\Application\Notification\Channel\ChannelDispatcher
 * @see EventPulse\Application\Notification\Retry\RetryPolicy
 */
final class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum attempts at the *job* level (Laravel's queue retry).
     *
     * Set to 1 because retry is a domain decision: the aggregate's
     * `recordFailure(... $maxAttempts)` consults the injected
     * `RetryPolicy` and decides whether to schedule a retry or
     * dead-letter. Letting Laravel's queue driver retry on top of that
     * would double-count attempts and obscure the domain's policy.
     *
     * The domain-driven retry is implemented as a *fresh* enqueue (not
     * a queue-driver re-throw), so each attempt arrives at the worker
     * with `tries = 1` and decides its own fate from current persisted
     * state.
     */
    public int $tries = 1;

    /**
     * Worker-side timeout: hard cap on a single execution. Higher than
     * any realistic channel timeout (HTTP webhook 30s, SMTP send 20s,
     * SMS 15s) with headroom for retries-within-attempt — but low
     * enough that a stuck job is killed and re-queued by the
     * supervisor before it blocks a worker indefinitely.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $correlationId,
    ) {}

    public function handle(
        NotificationRepository $repository,
        ChannelDispatcher $channelDispatcher,
        Clock $clock,
        DomainEventDispatcher $eventDispatcher,
        RetryPolicy $retryPolicy,
        NotificationDispatchQueue $dispatchQueue,
        LoggerInterface $logger,
    ): void {
        $id = NotificationId::fromString($this->notificationId);

        $notification = $repository->findById($id);

        if ($notification === null) {
            throw new NotificationNotFoundForDispatchException(notificationId: $id);
        }

        $logContext = [
            'notification_id' => $notification->id()->toString(),
            'channel'         => $notification->channel()->value,
            'correlation_id'  => $this->correlationId,
        ];

        // 1. Claim the attempt. Persisting *before* the I/O is deliberate:
        //    if the worker crashes mid-dispatch, the in-progress attempt
        //    is visible in the database, which makes operator triage
        //    possible without log archaeology.
        $now           = $clock->now();
        $attempt       = $notification->beginAttempt($now);
        $attemptNumber = $attempt->number()->toInt();
        $repository->save($notification);

        $logger->info('notification.dispatch.started', $logContext + [
            'event'          => 'notification.dispatch.started',
            'attempt_number' => $attemptNumber,
        ]);

        // 2. Run the channel-specific dispatch. The dispatcher selects
        //    the driver; the driver returns a structured outcome rather
        //    than throwing — see `ChannelDriver` docblock.
        $outcome = $channelDispatcher->dispatch($notification, $attempt);

        // 3. Apply the outcome to the aggregate. Persisting *after* is
        //    what closes the attempt: until this `save`, the attempt is
        //    still "in progress" from the database's point of view,
        //    even if the I/O has already completed.
        $retryAt = null;

        if ($outcome->succeeded) {
            $notification->recordSuccess($now);

            $logger->info('notification.dispatch.succeeded', $logContext + [
                'event'               => 'notification.dispatch.succeeded',
                'attempt_number'      => $attemptNumber,
                'provider_message_id' => $outcome->providerMessageId,
            ]);
        } else {
            // Compute the retry-after timestamp before calling
            // recordFailure so we can pass it both to the aggregate and
            // (if a retry actually happens) to the queue. The aggregate
            // ignores the timestamp on classifications that don't
            // retry; passing it unconditionally keeps this code path
            // free of "retry vs not" branching that the domain already
            // owns.
            $maxAttempts = $retryPolicy->maxAttemptsFor($notification->channel());
            $retryAt     = $now->add(
                $retryPolicy->nextDelay($notification->channel(), $attempt->number()),
            );

            $notification->recordFailure(
                classification: $outcome->classification,
                reason:         $outcome->reason,
                maxAttempts:    $maxAttempts,
                now:            $now,
                retryAfter:     $retryAt,
            );

            $logger->warning('notification.dispatch.failed', $logContext + [
                'event'          => 'notification.dispatch.failed',
                'attempt_number' => $attemptNumber,
                'classification' => $outcome->classification->value,
                'reason'         => $outcome->reason,
                'max_attempts'   => $maxAttempts,
            ]);
        }

        $repository->save($notification);

        // 4. If the aggregate decided to retry, re-enqueue with the
        //    computed delay. The signal is the post-recordFailure
        //    status: `Queued` means "transient, not exhausted, retry
        //    scheduled"; any other status means terminal (Dispatched,
        //    DeadLettered, Failed) and there is nothing more to do.
        //
        //    Reading the status off the aggregate after persistence is
        //    the right contract direction: the domain decided, the
        //    application reacts. We don't second-guess the decision
        //    based on the outcome's classification — the aggregate
        //    knows things we don't (the current attempt count after
        //    increment, terminal-state guards, etc.).
        if ($notification->status() === NotificationStatus::Queued && $retryAt !== null) {
            $dispatchQueue->enqueue(
                notificationId: $notification->id(),
                correlationId:  $notification->correlationId(),
                priority:       $notification->priority(),
                availableAt:    $retryAt,
            );

            $logger->info('notification.dispatch.retry_scheduled', $logContext + [
                'event'                 => 'notification.dispatch.retry_scheduled',
                'failed_attempt_number' => $attemptNumber,
                'retry_after'           => $retryAt->format(\DateTimeInterface::ATOM),
            ]);
        }

        // 5. Release domain events to subscribers. Done *after* the
        //    final save (and the re-enqueue, which is an
        //    infrastructure-idempotent operation we'd rather have run
        //    even if a subscriber crashes) so any subscriber that
        //    crashes does not leave the aggregate in an inconsistent
        //    persistent state. Today's subscriber is the null
        //    dispatcher; Day 8 wires the structured-log + event-bus
        //    implementation behind the same interface and this call
        //    site is unchanged.
        foreach ($notification->pullPendingEvents() as $event) {
            $eventDispatcher->dispatch($event);
        }
    }
}
