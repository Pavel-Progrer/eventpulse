<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent-backed handler for the paginated notification list.
 *
 * Lives in the Application layer because it mediates between the HTTP boundary
 * and the Infrastructure persistence layer. It is *not* in the Domain layer
 * because it depends on Eloquent. It is *not* in the Infrastructure layer
 * because it owns query policy (which filters are valid, how the cursor is
 * encoded), not raw I/O.
 *
 * **Cursor encoding.** The cursor is a base64-encoded `created_at|id` pair
 * (UTC ISO 8601 and UUID). This gives stable, stateless pagination even when
 * rows change status between requests. Keyset pagination over `(created_at
 * DESC, id DESC)` avoids OFFSET's performance penalty at depth.
 *
 * **Aggregates vs projections.** Unlike the DLQ list (which uses a flat
 * `DlqEntry` read model), this handler returns full `Notification` aggregates
 * because the notification list endpoint exposes attempt history per row and
 * the spec says `GET /notifications/{id}` and the list endpoint share the
 * same `Notification` schema. If the list becomes a hot query at scale, a flat
 * projection is the natural optimisation — no interface changes required.
 *
 * **Placement note.** This class imports Eloquent (`Builder`) and therefore
 * cannot live in `EventPulse\Domain\`. It is placed in
 * `EventPulse\Application\Notification\Query\` — the application layer's
 * query namespace — which is the correct home for use-case logic that
 * orchestrates infrastructure without containing domain rules.
 */
final class ListNotificationsQueryHandler
{
    // Enforced at the FormRequest level too; belt-and-suspenders.
    private const int MAX_LIMIT = 200;

    public function __invoke(ListNotificationsQuery $query): NotificationPage
    {
        $limit = min($query->limit, self::MAX_LIMIT);

        $builder = EloquentNotification::query()
            ->where('api_key_id', $query->apiKeyId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit + 1); // fetch one extra to detect next page

        $this->applyFilters($builder, $query);
        $this->applyCursor($builder, $query->cursor);

        /** @var list<EloquentNotification> $rows */
        $rows = $builder->get()->all();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(
            fn (EloquentNotification $row) => $this->hydrate($row),
            $rows,
        );

        $nextCursor = ($hasMore && count($items) > 0)
            ? $this->encodeCursor(end($items))
            : null;

        return new NotificationPage(items: $items, nextCursor: $nextCursor);
    }

    private function applyFilters(Builder $builder, ListNotificationsQuery $query): void
    {
        if (count($query->statuses) > 0) {
            $builder->whereIn(
                'status',
                array_map(fn (NotificationStatus $s) => $s->value, $query->statuses),
            );
        }

        if (count($query->channels) > 0) {
            $builder->whereIn(
                'channel',
                array_map(fn (Channel $c) => $c->value, $query->channels),
            );
        }

        if ($query->correlationId !== null) {
            $builder->where('correlation_id', $query->correlationId);
        }

        if ($query->createdAfter !== null) {
            $builder->where('created_at', '>', $query->createdAfter->format('Y-m-d H:i:s'));
        }

        if ($query->createdBefore !== null) {
            $builder->where('created_at', '<', $query->createdBefore->format('Y-m-d H:i:s'));
        }
    }

    private function applyCursor(Builder $builder, ?string $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $decoded = base64_decode($cursor, strict: true);
        if ($decoded === false) {
            // Malformed cursor — ignore and return from the start. The
            // FormRequest could validate this more strictly; at the handler
            // level we degrade gracefully rather than 422-ing mid-pagination.
            return;
        }

        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return;
        }

        [$createdAt, $id] = $parts;

        // Keyset pagination: rows strictly older than the cursor row, OR
        // same instant but with a lexicographically smaller UUID (DESC order).
        $builder->where(static function (Builder $q) use ($createdAt, $id): void {
            $q->where('created_at', '<', $createdAt)
                ->orWhere(static function (Builder $q2) use ($createdAt, $id): void {
                    $q2->where('created_at', $createdAt)
                        ->where('id', '<', $id);
                });
        });
    }

    private function encodeCursor(Notification $last): string
    {
        $payload = $last->createdAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            .'|'
            .$last->id()->toString();

        return base64_encode($payload);
    }

    /**
     * Reconstitute a Notification aggregate from an Eloquent row.
     *
     * Duplicates the hydration logic in `EloquentNotificationRepository` by
     * design: this handler is a read path that may evolve separately from the
     * write path, and sharing a private method across two classes in different
     * namespaces would require a public helper that does not belong on either
     * class. The duplication is small (14 lines of mapping), the coupling it
     * avoids is real. If it grows, a dedicated `NotificationHydrator` service
     * is the right extraction.
     *
     * Attempts are not loaded in the list path — the list schema (per spec
     * §4.1) does not include attempt history. If `include=attempts` is ever
     * added to the list endpoint, this method gains an eager-load branch.
     */
    private function hydrate(EloquentNotification $row): Notification
    {
        $channel = Channel::from($row->channel);

        return Notification::reconstitute(
            id: NotificationId::fromString($row->id),
            channel: $channel,
            recipient: match ($channel) {
                Channel::Email => EmailRecipient::fromString($row->recipient),
                Channel::Sms => SmsRecipient::fromE164($row->recipient),
                Channel::Webhook => WebhookRecipient::fromDestinationId($row->recipient),
            },
            payload: NotificationPayload::forChannel($row->payload, $channel),
            priority: Priority::from($row->priority),
            idempotencyKey: IdempotencyKey::fromString($row->idempotency_key),
            apiKeyId: $row->api_key_id,
            createdAt: DateTimeImmutable::createFromInterface($row->created_at)
                ->setTimezone(new DateTimeZone('UTC')),
            status: NotificationStatus::from($row->status),
            correlationId: CorrelationId::fromString($row->correlation_id),
            attempts: [],  // not loaded in list path — spec §4.1 does not include attempt history
            deadLetterMark: null,
            replayOf: $row->replay_of_id === null
                ? null
                : NotificationId::fromString($row->replay_of_id),
        );
    }
}
