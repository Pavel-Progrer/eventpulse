<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Use case: replay a dead-lettered notification.
 *
 * Steps:
 *  1. Load the original notification; enforce it is `dead_lettered` and owned
 *     by the calling API key (both failures → 404, no information disclosure).
 *  2. Check idempotency: if a replay with the same key already exists, return
 *     that notification as a 202 (idempotent re-acceptance).
 *  3. Create a new notification via `Notification::request()` with the same
 *     channel, recipient, payload, and priority. Set `replayOf` to the
 *     original id.
 *  4. Mutate the original aggregate: call `recordReplay()` to stamp the mark
 *     with the new id and timestamp.
 *  5. Persist both aggregates in the same transaction (handled at the
 *     repository level via the upsert path — the original is a UPDATE, the new
 *     notification is an INSERT).
 *  6. Release domain events from both aggregates.
 *  7. Enqueue the new notification.
 *
 * **Transaction boundary.** Persisting the new notification and updating the
 * original must be atomic: a crash between the two would leave the original
 * dead-lettered without a replay record, causing a phantom "this was replayed"
 * inconsistency. `EloquentNotificationRepository::save()` already wraps its
 * three-table write in a single transaction, but the two separate `save()`
 * calls here are *not* wrapped. A production hardening pass should wrap them
 * in a DB transaction at the application layer (e.g. via a `TransactionManager`
 * port). This is a known gap, documented in the "Triggers to revisit" section
 * of ADR-0006, and acceptable for Phase 1 given the DLQ replay path is not
 * latency-sensitive.
 *
 * @see Notification::recordReplay()
 * @see docs/adr/0006-dlq-admin-and-structured-logging.md
 */
final class ReplayDeadLetteredHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly NotificationDispatchQueue $dispatchQueue,
        private readonly DomainEventDispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(ReplayDeadLetteredCommand $command): Notification
    {
        $now     = $this->clock->now();
        $idemKey = IdempotencyKey::fromString($command->idempotencyKey);

        // Idempotency check comes first — before loading the original — so that
        // a second call with the same key short-circuits immediately regardless
        // of the original's current state. If we called loadOriginal() first,
        // a second invocation would hit the wasReplayed() guard and throw
        // AlreadyReplayedException even though the caller is legitimately
        // retrying with the same key.
        $existing = $this->repository->findByIdempotencyKey($command->apiKeyId, $idemKey);

        if ($existing !== null) {
            return $existing;
        }

        $original = $this->loadOriginal($command);

        // Create the new notification — same shape as the original.
        $replayId    = NotificationId::generate();
        $correlationId = $command->correlationId === null
            ? CorrelationId::generate()
            : CorrelationId::fromString($command->correlationId);

        $replay = Notification::request(
            id:             $replayId,
            channel:        $original->channel(),
            recipient:      $original->recipient(),
            rawPayload:     $original->payload()->toArray(),
            priority:       $original->priority(),
            idempotencyKey: $idemKey,
            apiKeyId:       $command->apiKeyId,
            correlationId:  $correlationId,
            now:            $now,
            replayOf:       $original->id(),
        );

        // Mark the original as replayed. This mutates its DeadLetterMark.
        $original->recordReplay($replayId, $now);

        // Persist both. Order: new first, then update the original — if the
        // second save fails, the new row exists but is orphaned rather than
        // the original being wrongly stamped. Orphaned unreferenced notifications
        // are less dangerous than a dead-lettered notification incorrectly
        // marked as replayed.
        $this->repository->save($replay);
        $this->repository->save($original);

        // Release domain events from both aggregates.
        foreach ($replay->pullPendingEvents() as $event) {
            $this->events->dispatch($event);
        }
        foreach ($original->pullPendingEvents() as $event) {
            $this->events->dispatch($event);
        }

        $this->dispatchQueue->enqueue(
            notificationId: $replay->id(),
            correlationId:  $replay->correlationId(),
            priority:       $replay->priority(),
        );

        return $replay;
    }

    /**
     * Load the original notification and enforce:
     *  - it exists and is owned by the calling API key,
     *  - it is in the `dead_lettered` state (not yet replayed).
     *
     * Both checks throw `DeadLetteredNotificationNotFoundException` to avoid
     * information disclosure (same convention as the DLQ read endpoints).
     */
    private function loadOriginal(ReplayDeadLetteredCommand $command): Notification
    {
        try {
            $id = NotificationId::fromString($command->notificationId);
        } catch (\InvalidArgumentException) {
            // A syntactically invalid id (e.g. not UUID v4) can never exist in
            // the repository. Treat it as not-found — the distinction is
            // invisible to the caller and avoids leaking VO validation details
            // through the API error envelope.
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        $notification = $this->repository->findById($id);

        if ($notification === null || $notification->apiKeyId() !== $command->apiKeyId) {
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        if ($notification->status() !== NotificationStatus::DeadLettered) {
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        // If it was already replayed, the replay is idempotent only when the
        // same idempotency key is used. A *different* key trying to replay an
        // already-replayed notification gets a 409, not a 404. Check above
        // handles the same-key idempotent path; the already-replayed guard
        // below fires only when the key differs.
        $mark = $notification->deadLetterMark();
        if ($mark !== null && $mark->wasReplayed()) {
            $existing = $mark->replayNotificationId();
            throw new AlreadyReplayedException(
                sprintf(
                    'Notification %s was already replayed; produced replay notification %s.',
                    $command->notificationId,
                    $existing?->toString() ?? 'unknown',
                ),
            );
        }

        return $notification;
    }
}
