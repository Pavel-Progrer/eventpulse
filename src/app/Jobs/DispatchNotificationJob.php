<?php

declare(strict_types=1);

namespace App\Jobs;

use EventPulse\Application\Notification\Exception\NotificationNotFoundForDispatchException;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Worker job: dispatch a previously persisted notification through its channel.
 *
 * This is the asynchronous half of the submission flow:
 *  - HTTP path:  validate → persist → enqueue (this job).
 *  - Worker path (this class): load → claim attempt → dispatch via channel
 *    strategy → record outcome → persist.
 *
 * Day 4 scope (the queue plumbing):
 *  - The job is constructible, queueable, and re-loads the aggregate by id
 *    on `handle()`.
 *  - The actual dispatch step is *deferred to Day 5* (channel strategy
 *    pattern). For Day 4, `handle()` re-loads the aggregate, logs a debug
 *    entry that records the worker received the job, and returns. This is
 *    enough to verify queue plumbing end-to-end without coupling to the
 *    not-yet-implemented channel adapters.
 *  - Tests use `Bus::fake()` to assert the job is dispatched; the job's
 *    no-op behaviour means worker runs in any environment cause no state
 *    change. Day 5 lands the real `handle()` body in a single change.
 *
 * Why a string `notificationId` rather than a `NotificationId` value object:
 *  Laravel's queue serialiser persists the job's constructor arguments as
 *  JSON. Value objects with private constructors do not round-trip cleanly
 *  through `serialize()` / `unserialize()` in all queue drivers
 *  (database/redis/SQS each have their own quirks). A primitive string is
 *  the lingua franca; the worker re-hydrates the VO via
 *  `NotificationId::fromString()`, which re-validates the format. If the id
 *  has become malformed in transit, the validation throws and the job fails
 *  to a retry — strictly safer than a silently-malformed value reaching the
 *  domain.
 *
 * Why a separate `correlationId` argument rather than reading it off the
 * loaded aggregate:
 *  Logging *before* the load (e.g. "loading notification {id}" on entry)
 *  needs a correlation id, and we don't yet have the aggregate. Keeping the
 *  correlation id on the job payload means every log line on the worker side
 *  can include it from the very first instruction.
 *
 * @see EventPulse\Application\Notification\NotificationDispatchQueue (port)
 * @see EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue (adapter)
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
     * `recordFailure(... $maxAttempts)` decides whether to re-queue or
     * dead-letter, with channel-specific limits (webhook 6, email 4, sms 3
     * — see specification §5.2). Letting Laravel's queue driver retry on
     * top of that would double-count attempts and obscure the domain's
     * retry policy.
     *
     * Day 5 introduces the channel-strategy code path; Day 7 wires the
     * domain-level retry decision back into a re-queue with delay.
     */
    public int $tries = 1;

    /**
     * Worker-side timeout: hard cap on a single execution. Higher than any
     * realistic channel timeout (HTTP webhook 30s, SMTP send 20s, SMS 15s)
     * with headroom for retries-within-attempt — but low enough that a
     * stuck job is killed and re-queued by the supervisor before it blocks
     * a worker indefinitely.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $correlationId,
    ) {}

    public function handle(NotificationRepository $repository): void
    {
        $id = NotificationId::fromString($this->notificationId);

        $notification = $repository->findById($id);

        if ($notification === null) {
            throw new NotificationNotFoundForDispatchException(notificationId: $id);
        }

        // ---------------------------------------------------------------------
        // Day 5 wires the dispatch flow here:
        //
        //     $attempt = $notification->beginAttempt($this->clock->now());
        //     $repository->save($notification);
        //
        //     $outcome = $this->channelDispatcher->dispatch($notification);
        //
        //     if ($outcome->succeeded) {
        //         $notification->recordSuccess($this->clock->now());
        //     } else {
        //         $notification->recordFailure(
        //             classification: $outcome->classification,
        //             reason:         $outcome->reason,
        //             maxAttempts:    $this->retryPolicy->maxAttemptsFor($notification->channel()),
        //             now:            $this->clock->now(),
        //             retryAfter:     $this->backoff->nextRetry($notification->channel(), $attempt->number()),
        //         );
        //     }
        //
        //     $repository->save($notification);
        //     $this->eventDispatcher->releaseAll($notification->pullPendingEvents());
        //
        // For Day 4 the body of `handle()` ends here. The aggregate is loaded
        // (proving the queue → repository → domain path works) and a single
        // structured-log entry records the worker pickup.
        // ---------------------------------------------------------------------

        Log::debug('notification.dispatch.deferred', [
            'event'           => 'notification.dispatch.deferred',
            'notification_id' => $notification->id()->toString(),
            'channel'         => $notification->channel()->value,
            'correlation_id'  => $this->correlationId,
            'reason'          => 'channel-strategy not yet implemented (Day 5)',
        ]);
    }
}