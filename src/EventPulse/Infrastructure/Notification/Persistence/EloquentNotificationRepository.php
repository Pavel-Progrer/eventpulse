<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * Eloquent-backed implementation of `NotificationRepository`.
 *
 * Translation responsibilities:
 *  - Aggregate → Eloquent (in `save()`): copy domain state into a row.
 *  - Eloquent → Aggregate (in `findById*()`): hydrate via
 *    `Notification::reconstitute()` so no domain events are raised.
 *
 * Day 3 scope: only the root row. Attempts and dead-letter marks become
 * relevant in Day 4 (queue dispatch / retry) and gain their own tables and
 * mapping methods then.
 */
final class EloquentNotificationRepository implements NotificationRepository
{
    #[\Override]
    public function save(Notification $notification): void
    {
        // Upsert keyed by notification id. updateOrCreate is atomic at the row
        // level under Postgres' default isolation (READ COMMITTED) and is
        // sufficient given the unique idempotency constraint at the schema
        // level (`notifications_idem_unique`) — duplicate inserts hit that
        // constraint regardless of any application-layer race.
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

    /**
     * Reconstitute the aggregate from a persisted row.
     *
     * Mirror image of `save()`. Day 4 will extend this to load the attempts
     * and the dead-letter mark — the stub paths are commented below to make
     * the extension obvious.
     */
    private function hydrate(EloquentNotification $row): Notification
    {
        $channel = Channel::from($row->channel);

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
            attempts:       [],   // Day 4 wires this up.
            deadLetterMark: null, // Day 4 wires this up.
            replayOf:       $row->replay_of_id === null
                ? null
                : NotificationId::fromString($row->replay_of_id),
        );
    }

    private function reconstituteRecipient(Channel $channel, string $raw): Recipient
    {
        // The recipient string was produced by the domain's own `toString()`
        // in `save()`, so re-validating through the same factories here is
        // safe and consistent. If we ever start writing recipients through
        // any other path, this method is the single point of audit.
        return match ($channel) {
            Channel::Email   => EmailRecipient::fromString($raw),
            Channel::Sms     => SmsRecipient::fromE164($raw),
            Channel::Webhook => WebhookRecipient::fromDestinationId($raw),
        };
    }

    /**
     * Convert a Carbon-or-DateTimeInterface into the immutable UTC form the
     * domain expects. Eloquent gives us `Carbon`; the aggregate accepts
     * `DateTimeImmutable`. This is the conversion seam.
     */
    private function toUtc(\DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
