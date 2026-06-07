<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Application\Notification\DeadLetter\Query\DeadLetteredNotificationsRepository;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntry;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntryPage;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQuery;
use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed implementation of `DeadLetteredNotificationsRepository`.
 *
 * Produces the flat `DlqEntry` projection — not full aggregates. This is
 * intentional: the list endpoint must be cheap (a single JOIN query with
 * a correlated sub-select for `final_attempt_at`), not N+1 aggregate loads.
 * The `GET /dlq/{id}` inspect endpoint earns full hydration (one row per
 * call); the list does not.
 *
 * Query shape:
 *   SELECT dlm.*, n.channel, n.api_key_id,
 *          (SELECT MAX(a.completed_at)
 *           FROM attempts a
 *           WHERE a.notification_id = dlm.notification_id) AS final_attempt_at
 *   FROM dead_letter_marks dlm
 *   JOIN notifications n ON n.id = dlm.notification_id
 *   WHERE n.api_key_id = ?
 *     AND dlm.discarded_at IS NULL          -- default: exclude discarded entries
 *     [AND dlm.reason = ?]
 *     [AND n.channel = ?]
 *     [AND dlm.dead_lettered_at > ?]
 *     [AND dlm.dead_lettered_at < ?]
 *     [cursor keyset clause]
 *   ORDER BY dlm.dead_lettered_at DESC, dlm.id DESC
 *   LIMIT ? + 1
 *
 * Cursor format: `{ISO-8601 UTC dead_lettered_at}|{dead_letter_mark uuid}`.
 * The pipe delimiter is used (not `:`) to avoid ambiguity with the colon in
 * ISO-8601 timestamps. Both segments are required for stable keyset pagination.
 *
 * Day 11 change: added `whereNull('dlm.discarded_at')` to the base query so
 * that entries acknowledged via `DELETE /api/v1/dlq/{id}` disappear from the
 * default list view. The condition is unconditional — there is no
 * `include_discarded` parameter in Phase 1.
 */
final class EloquentDeadLetteredNotificationsRepository implements DeadLetteredNotificationsRepository
{
    private const int MAX_LIMIT = 100;

    #[\Override]
    public function list(ListDeadLetteredQuery $query): DlqEntryPage
    {
        $limit = min($query->limit, self::MAX_LIMIT);

        $rows = $this->baseQuery($query)
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows->pop();
        }

        $entries = $rows->map(fn (object $row): DlqEntry => $this->project($row))->values()->all();
        $nextCursor = ($hasMore && count($entries) > 0)
            ? $this->encodeCursor(end($entries))
            : null;

        return new DlqEntryPage(entries: $entries, nextCursor: $nextCursor);
    }

    private function baseQuery(ListDeadLetteredQuery $query): Builder
    {
        $builder = DB::table('dead_letter_marks AS dlm')
            ->join('notifications AS n', 'n.id', '=', 'dlm.notification_id')
            ->selectRaw('
                dlm.id,
                dlm.notification_id,
                dlm.reason,
                dlm.dead_lettered_at,
                dlm.replay_notification_id,
                dlm.replayed_at,
                dlm.discarded_at,
                n.channel,
                (
                    SELECT MAX(a.completed_at)
                    FROM attempts a
                    WHERE a.notification_id = dlm.notification_id
                ) AS final_attempt_at
            ')
            ->where('n.api_key_id', $query->apiKeyId)
            // Day 11: exclude discarded entries from the default view.
            ->whereNull('dlm.discarded_at')
            ->orderBy('dlm.dead_lettered_at', 'desc')
            ->orderBy('dlm.id', 'desc');

        if ($query->reason !== null) {
            $builder->where('dlm.reason', $query->reason);
        }

        if ($query->channel !== null) {
            $builder->where('n.channel', $query->channel->value);
        }

        if ($query->createdAfter !== null) {
            $builder->where('dlm.dead_lettered_at', '>', $query->createdAfter->format('Y-m-d H:i:s'));
        }

        if ($query->createdBefore !== null) {
            $builder->where('dlm.dead_lettered_at', '<', $query->createdBefore->format('Y-m-d H:i:s'));
        }

        if ($query->cursor !== null) {
            $this->applyCursor($builder, $query->cursor);
        }

        return $builder;
    }

    private function applyCursor(Builder $builder, string $cursor): void
    {
        $decoded = base64_decode($cursor, strict: true);
        if ($decoded === false) {
            // Malformed cursor — degrade gracefully by ignoring it.
            return;
        }

        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return;
        }

        [$deadLetteredAt, $id] = $parts;

        // Keyset: rows strictly older than the cursor, or same timestamp with
        // a lexicographically smaller id (DESC sort tiebreaker).
        $builder->where(static function (Builder $q) use ($deadLetteredAt, $id): void {
            $q->where('dlm.dead_lettered_at', '<', $deadLetteredAt)
                ->orWhere(static function (Builder $q2) use ($deadLetteredAt, $id): void {
                    $q2->where('dlm.dead_lettered_at', $deadLetteredAt)
                        ->where('dlm.id', '<', $id);
                });
        });
    }

    private function encodeCursor(DlqEntry $last): string
    {
        $payload = $last->deadLetteredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s')
            .'|'
            .$last->id;

        return base64_encode($payload);
    }

    private function project(object $row): DlqEntry
    {
        return new DlqEntry(
            id: $row->id,
            notificationId: $row->notification_id,
            reason: $row->reason,
            channel: Channel::from($row->channel),
            deadLetteredAt: $this->toUtc($row->dead_lettered_at),
            finalAttemptAt: $row->final_attempt_at !== null
                                      ? $this->toUtc($row->final_attempt_at)
                                      : null,
            replayNotificationId: $row->replay_notification_id,
            replayedAt: $row->replayed_at !== null
                                      ? $this->toUtc($row->replayed_at)
                                      : null,
        );
    }

    private function toUtc(string $raw): DateTimeImmutable
    {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    }
}
