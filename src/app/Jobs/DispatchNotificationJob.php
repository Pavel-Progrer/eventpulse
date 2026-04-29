<?php

declare(strict_types=1);

namespace App\Jobs;

use DateInterval;
use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Exception\NotificationNotFoundForDispatchException;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
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
 *    via channel strategy → record outcome → persist → release events.
 *
 * Day 5 lands the dispatch flow itself. Compared to Day 4's stub:
 *  - the in-progress attempt is now actually claimed and persisted,
 *  - `ChannelDispatcher` selects and runs the right driver,
 *  - success/failure outcomes feed back into the aggregate via
 *    `recordSuccess` / `recordFailure`,
 *  - pending domain events are released to the dispatcher port after
 *    final persistence.
 *
 * What is *not* in Day 5 and is documented inline:
 *  - the `RetryPolicy` and `Backoff` (Day 7). Today the job hard-codes
 *    `MAX_ATTEMPTS_DAY_5 = 1` and a fixed retry-after, which means any
 *    transient failure dead-letters on the first attempt. That is
 *    acceptable for Phase 1's interim state — failures are still
 *    correctly classified and observable, and Day 7 replaces the
 *    constants with channel-aware policy in a single change to this
 *    method's body.
 *  - HMAC webhook signing (Day 9).
 *  - structured-logging dispatcher and DLQ admin endpoint (Day 8).
 *
 * Why a string `notificationId` rather than a `NotificationId` value
 * object: Laravel's queue serialiser persists the job's constructor
 * arguments as JSON. Value objects with private constructors do not
 * round-trip cleanly through `serialize()` / `unserialize()` in all
 * queue drivers (database/redis/SQS each have their own quirks). A
 * primitive string is the lingua franca; the worker re-hydrates the VO
 * via `NotificationId::fromString()`, which re-validates the format. If
 * the id has become malformed in transit the validation throws and the
 * job fails — strictly safer than a silently-malformed value reaching
 * the domain.
 *
 * Why a separate `correlationId` argument rather than reading it off the
 * loaded aggregate: logging *before* the load (e.g. "loading
 * notification {id}" on entry) needs a correlation id, and we don't yet
 * have the aggregate. Keeping the correlation id on the job payload
 * means every log line on the worker side can include it from the very
 * first instruction.
 *
 * @see EventPulse\Application\Notification\NotificationDispatchQueue (port)
 * @see EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue (adapter)
 * @see EventPulse\Application\Notification\Channel\ChannelDispatcher
 */
final class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Day 5 placeholder for `RetryPolicy::maxAttemptsFor($channel)`.
     *
     * Set to 1 — meaning a single attempt total — so any transient
     * failure dead-letters immediately. This is intentionally
     * conservative for the interim: it means dispatch outcomes are
     * fully observable through the existing DLQ mechanism without
     * Day 7's exponential backoff being in place. Day 7 replaces this
     * with channel-specific limits (email 4, webhook 6, sms 3 per
     * specification §5.2).
     */
    private const MAX_ATTEMPTS_DAY_5 = 1;

    /**
     * Day 5 placeholder for `Backoff::nextRetry($channel, $attempt)`.
     *
     * Used only when `MAX_ATTEMPTS_DAY_5 > 1` (i.e. effectively
     * unreachable in this build because `attempt 1 < 1` is false). The
     * value still has to be supplied to `recordFailure()` to satisfy the
     * aggregate's signature; we use 60 seconds as a round, sane default
     * that Day 7 replaces.
     */
    private const RETRY_AFTER_SECONDS_DAY_5 = 60;

    /**
     * Maximum attempts at the *job* level (Laravel's queue retry).
     *
     * Set to 1 because retry is a domain decision: the aggregate's
     * `recordFailure(... $maxAttempts)` decides whether to re-queue or
     * dead-letter, with channel-specific limits (Day 7). Letting
     * Laravel's queue driver retry on top of that would double-count
     * attempts and obscure the domain's retry policy.
     */
    public int $tries = 1;

    /**
     * Worker-side timeout: hard cap on a single execution. Higher than
     * any realistic channel timeout (HTTP webhook 30s, SMTP send 20s,
     * SMS 15s) with headroom for retries-within-attempt — but low
     * enough that a stuck job is killed and re-queued by the supervisor
     * before it blocks a worker indefinitely.
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
        $attempt = $notification->beginAttempt($clock->now());
        $repository->save($notification);

        $logger->info('notification.dispatch.started', $logContext + [
            'event'          => 'notification.dispatch.started',
            'attempt_number' => $attempt->number()->toInt(),
        ]);

        // 2. Run the channel-specific dispatch. The dispatcher selects
        //    the driver; the driver returns a structured outcome rather
        //    than throwing — see `ChannelDriver` docblock.
        $outcome = $channelDispatcher->dispatch($notification, $attempt);

        // 3. Apply the outcome to the aggregate. Persisting *after*
        //    is what closes the attempt: until this `save`, the attempt
        //    is still "in progress" from the database's point of view,
        //    even if the I/O has already completed.
        if ($outcome->succeeded) {
            $notification->recordSuccess($clock->now());

            $logger->info('notification.dispatch.succeeded', $logContext + [
                'event'              => 'notification.dispatch.succeeded',
                'attempt_number'     => $attempt->number()->toInt(),
                'provider_message_id' => $outcome->providerMessageId,
            ]);
        } else {
            $now        = $clock->now();
            $retryAfter = $now->add(new DateInterval('PT' . self::RETRY_AFTER_SECONDS_DAY_5 . 'S'));

            $notification->recordFailure(
                classification: $outcome->classification,
                reason:         $outcome->reason,
                maxAttempts:    self::MAX_ATTEMPTS_DAY_5,
                now:            $now,
                retryAfter:     $retryAfter,
            );

            $logger->warning('notification.dispatch.failed', $logContext + [
                'event'          => 'notification.dispatch.failed',
                'attempt_number' => $attempt->number()->toInt(),
                'classification' => $outcome->classification->value,
                'reason'         => $outcome->reason,
            ]);
        }

        $repository->save($notification);

        // 4. Release domain events to subscribers. Done *after* the
        //    final save so any subscriber that crashes does not leave
        //    the aggregate in an inconsistent persistent state. Today's
        //    subscriber is the null dispatcher; Day 8 wires the
        //    structured-log + event-bus implementation behind the same
        //    interface and this call site is unchanged.
        foreach ($notification->pullPendingEvents() as $event) {
            $eventDispatcher->dispatch($event);
        }
    }
}
