<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Entity\Attempt;
use EventPulse\Domain\Notification\Entity\DeadLetterMark;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use Illuminate\Database\ConnectionInterface;

/**
 * Eloquent-backed implementation of `NotificationRepository`.
 *
 * Translation responsibilities:
 *  - Aggregate → Eloquent (in `save()`): copy domain state into rows in
 *    `notifications`, `attempts`, and (when present) `dead_letter_marks`.
 *  - Eloquent → Aggregate (in `findById*()`): hydrate via
 *    `Notification::reconstitute()` so no domain events are raised.
 *
 * Day 8 closes the Day-4 hand-wave: attempts and the dead-letter mark
 * are now actually persisted and rehydrated. Before today, every
 * `findById()` returned a notification with `attempts: []`, which broke
 * any read path that needed the history (the DLQ admin endpoints, the
 * status endpoint when it ships, the `recordFailure` invariant when a
 * worker resumes a partially-completed retry sequence).
 *
 * Concurrency and ordering inside `save()`:
 *  Wrapping the three-table write in a transaction keeps "the
 *  notification advanced its state" consistent with "the matching
 *  attempt and DL mark exist." Without the transaction, a worker crash
 *  between two writes could leave the notifications row reading
 *  `dead_lettered` while the `dead_letter_marks` row never made it.
 *  The transaction is short — three single-row upserts at most — so
 *  lock contention is not a concern.
 *
 * Why upsert and not delete-then-insert for attempts:
 *  Attempts are append-only (invariant 5.1.4) — a save() that overwrites
 *  the table by deleting first would briefly violate the invariant in
 *  an observer's view of the database. Per-row upsert by
 *  (notification_id, number) preserves the invariant in the persistent
 *  layer just as the aggregate preserves it in memory.
 */
final class EloquentNotificationRepository implements NotificationRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    #[\Override]
    public function save(Notification $notification): void
    {
        // Three-table write inside a transaction — see class docblock.
        $this->connection->transaction(function () use ($notification): void {
            $this->saveRoot($notification);
            $this->saveAttempts($notification);
            $this->saveDeadLetterMark($notification);
        });
    }

    #[\Override]
    public function findById(NotificationId $id): ?Notification
    {
        $row = EloquentNotification::query()->find($id->toString());

        return $row === null ? null : $this->hydrate($row);
    }

    #[\Override]
    public function findByIdempotencyKey(string $apiKeyId, IdempotencyKey $key): ?Notification
    {
        $row = EloquentNotification::query()
            ->where('api_key_id', $apiKeyId)
            ->where('idempotency_key', $key->toString())
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    // -----------------------------------------------------------------------
    // Save — three sub-steps, one transaction
    // -----------------------------------------------------------------------

    private function saveRoot(Notification $notification): void
    {
        // updateOrCreate for the root row. Atomic at the row level under
        // Postgres' default isolation given the schema's
        // `notifications_idem_unique` constraint as the last line of
        // defence against a race.
        EloquentNotification::query()->updateOrCreate(
            ['id' => $notification->id()->toString()],
            [
                'api_key_id'      => $notification->apiKeyId(),
                'channel'         => $notification->channel()->value,
                'recipient'       => $notification->recipient()->toString(),
                'priority'        => $notification->priority()->value,
                'payload'         => $notification->payload()->toArray(),
                'status'          => $notification->status()->value,
                'correlation_id'  => $notification->correlationId()->toString(),
                'idempotency_key' => $notification->idempotencyKey()->toString(),
                'replay_of_id'    => $notification->replayOf()?->toString(),
                'created_at'      => $notification->createdAt(),
                'updated_at'      => $notification->createdAt(),
            ],
        );
    }

    private function saveAttempts(Notification $notification): void
    {
        $notificationId = $notification->id()->toString();

        foreach ($notification->attempts() as $attempt) {
            $payload = [
                'notification_id' => $notificationId,
                'number'          => $attempt->number()->toInt(),
                'started_at'      => $attempt->startedAt(),
                'completed_at'    => $attempt->completedAt(),
                'succeeded'       => $attempt->succeeded(),
                'classification'  => $attempt->failureClassification()?->value,
                'reason'          => $attempt->failureReason(),
            ];

            // Keyed on (notification_id, number) per the unique index on
            // the table. The DB-level `attempts_outcome_consistency_check`
            // CHECK constraint refuses any row where (succeeded, classification,
            // reason, completed_at) are inconsistent — same invariant the
            // domain enforces, repeated at the persistence boundary.
            EloquentAttempt::query()->updateOrCreate(
                [
                    'notification_id' => $notificationId,
                    'number'          => $attempt->number()->toInt(),
                ],
                $payload,
            );
        }
    }

    private function saveDeadLetterMark(Notification $notification): void
    {
        $mark = $notification->deadLetterMark();

        if ($mark === null) {
            // Defensive: if the aggregate's mark is null, there must not
            // be a row. We do not delete here — the aggregate cannot
            // "un-dead-letter" — but a never-dead-lettered notification
            // simply has no mark to write.
            return;
        }

        EloquentDeadLetterMark::query()->updateOrCreate(
            ['notification_id' => $notification->id()->toString()],
            [
                'reason'                 => $mark->reason(),
                'dead_lettered_at'       => $mark->deadLetteredAt(),
                'replay_notification_id' => $mark->replayNotificationId()?->toString(),
                'replayed_at'            => $mark->replayedAt(),
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Hydrate — reverse direction, called by findById* and the read model
    // -----------------------------------------------------------------------

    /**
     * Reconstitute the aggregate from a persisted row plus its attempts
     * and (optional) dead-letter mark.
     */
    private function hydrate(EloquentNotification $row): Notification
    {
        $channel = Channel::from($row->channel);

        $attempts = $this->loadAttempts($row->id);
        $mark     = $this->loadDeadLetterMark($row->id);

        return Notification::reconstitute(
            id:             NotificationId::fromString($row->id),
            channel:        $channel,
            recipient:      $this->reconstituteRecipient($channel, $row->recipient),
            payload:        NotificationPayload::forChannel($row->payload, $channel),
            priority:       Priority::from($row->priority),
            idempotencyKey: IdempotencyKey::fromString($row->idempotency_key),
            apiKeyId:       $row->api_key_id,
            createdAt:      $this->toUtc($row->created_at),
            status:         NotificationStatus::from($row->status),
            correlationId:  CorrelationId::fromString($row->correlation_id),
            attempts:       $attempts,
            deadLetterMark: $mark,
            replayOf:       $row->replay_of_id === null
                ? null
                : NotificationId::fromString($row->replay_of_id),
        );
    }

    /**
     * @return array<int, Attempt> indexed by 1-based attempt number.
     */
    private function loadAttempts(string $notificationId): array
    {
        $rows = EloquentAttempt::query()
            ->where('notification_id', $notificationId)
            ->orderBy('number')
            ->get();

        $attempts = [];

        foreach ($rows as $row) {
            // Reconstitute via the public constructor + state-transition
            // methods. The Attempt entity has no separate `reconstitute`
            // factory because the constructor + recordSuccess/Failure
            // covers every legal in-memory state.
            $attempt = new Attempt(
                AttemptNumber::fromInt($row->number),
                $this->toUtc($row->started_at),
            );

            // Apply the completion state if the attempt was finished.
            // succeeded === null means "in progress" — leave the entity
            // in its just-constructed state.
            if ($row->succeeded === true) {
                $attempt->recordSuccess($this->toUtc($row->completed_at));
            } elseif ($row->succeeded === false) {
                $attempt->recordFailure(
                    classification: FailureClassification::from((string) $row->classification),
                    reason:         (string) $row->reason,
                    completedAt:    $this->toUtc($row->completed_at),
                );
            }

            $attempts[$row->number] = $attempt;
        }

        return $attempts;
    }

    private function loadDeadLetterMark(string $notificationId): ?DeadLetterMark
    {
        $row = EloquentDeadLetterMark::query()
            ->where('notification_id', $notificationId)
            ->first();

        if ($row === null) {
            return null;
        }

        $mark = new DeadLetterMark(
            reason:         $row->reason,
            deadLetteredAt: $this->toUtc($row->dead_lettered_at),
        );

        if ($row->replay_notification_id !== null) {
            $mark->reconstituteReplay(
                NotificationId::fromString($row->replay_notification_id),
                $this->toUtc($row->replayed_at),
            );
        }

        return $mark;
    }

    private function reconstituteRecipient(Channel $channel, string $raw): Recipient
    {
        return match ($channel) {
            Channel::Email   => EmailRecipient::fromString($raw),
            Channel::Sms     => SmsRecipient::fromE164($raw),
            Channel::Webhook => WebhookRecipient::fromDestinationId($raw),
        };
    }

    /**
     * Convert a Carbon-or-DateTimeInterface into the immutable UTC form
     * the domain expects. Eloquent gives us `Carbon`; the aggregate
     * accepts `DateTimeImmutable`. This is the conversion seam.
     */
    private function toUtc(\DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
