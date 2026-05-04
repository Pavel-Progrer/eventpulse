<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use DateTimeImmutable;
use EventPulse\Application\Notification\DeadLetter\Query\DeadLetteredNotificationsRepository;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntry;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntryPage;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQuery;
use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent/query-builder read model for {@see DeadLetteredNotificationsRepository}.
 *
 * Joins `dead_letter_marks` to `notifications` for tenant scoping and channel,
 * applies the same filter and cursor semantics as the in-memory test double,
 * and derives `final_attempt_at` from `MAX(attempts.completed_at)`.
 */
final class EloquentDeadLetteredNotificationsRepository implements DeadLetteredNotificationsRepository
{
    #[\Override]
    public function list(ListDeadLetteredQuery $query): DlqEntryPage
    {
        $fetchLimit = $query->limit + 1;

        $builder = DB::table('dead_letter_marks as dlm')
            ->join('notifications as n', 'dlm.notification_id', '=', 'n.id')
            ->where('n.api_key_id', $query->apiKeyId)
            ->select([
                'dlm.id',
                'dlm.notification_id',
                'dlm.reason',
                'n.channel',
                'dlm.dead_lettered_at',
                'dlm.replay_notification_id',
                'dlm.replayed_at',
            ])
            ->selectRaw(
                '(SELECT MAX(completed_at) FROM attempts AS a '
                . 'WHERE a.notification_id = n.id AND a.completed_at IS NOT NULL) AS final_attempt_at',
            );

        if ($query->reason !== null) {
            $builder->where('dlm.reason', $query->reason);
        }

        if ($query->channel !== null) {
            $builder->where('n.channel', $query->channel->value);
        }

        if ($query->createdAfter !== null) {
            $builder->where('dlm.dead_lettered_at', '>=', $query->createdAfter);
        }

        if ($query->createdBefore !== null) {
            $builder->where('dlm.dead_lettered_at', '<', $query->createdBefore);
        }

        if ($query->cursor !== null) {
            [$cursorTs, $cursorId] = $this->decodeCursor($query->cursor);
            $builder->where(static function ($q) use ($cursorTs, $cursorId): void {
                $q->where('dlm.dead_lettered_at', '<', $cursorTs)
                    ->orWhere(static function ($q2) use ($cursorTs, $cursorId): void {
                        $q2->where('dlm.dead_lettered_at', '=', $cursorTs)
                            ->where('dlm.id', '<', $cursorId);
                    });
            });
        }

        /** @var Collection<int, object> $rows */
        $rows = $builder
            ->orderByDesc('dlm.dead_lettered_at')
            ->orderByDesc('dlm.id')
            ->limit($fetchLimit)
            ->get();

        $hasMore = $rows->count() > $query->limit;
        $pageRows = $rows->take($query->limit);

        $nextCursor = null;
        if ($hasMore && $pageRows->isNotEmpty()) {
            $last = $pageRows->last();
            $deadAt = $this->requireImmutable($last->dead_lettered_at);
            $nextCursor = $deadAt->format(\DateTimeInterface::ATOM) . '|' . (string) $last->id;
        }

        $entries = $pageRows
            ->map(fn (object $row): DlqEntry => $this->toDlqEntry($row))
            ->values()
            ->all();

        return new DlqEntryPage($entries, $nextCursor);
    }

    /**
     * @return array{0: DateTimeImmutable, 1: string}
     */
    private function decodeCursor(string $cursor): array
    {
        $parts = explode('|', $cursor, 2);

        if (\count($parts) !== 2 || $parts[1] === '') {
            throw new \InvalidArgumentException('Malformed DLQ list cursor.');
        }

        return [new DateTimeImmutable($parts[0]), $parts[1]];
    }

    private function toDlqEntry(object $row): DlqEntry
    {
        return new DlqEntry(
            id: (string) $row->id,
            notificationId: (string) $row->notification_id,
            reason: (string) $row->reason,
            channel: Channel::from((string) $row->channel),
            deadLetteredAt: $this->requireImmutable($row->dead_lettered_at),
            finalAttemptAt: $this->toImmutable($row->final_attempt_at ?? null),
            replayNotificationId: $row->replay_notification_id !== null ? (string) $row->replay_notification_id : null,
            replayedAt: $this->toImmutable($row->replayed_at ?? null),
        );
    }

    private function requireImmutable(mixed $value): DateTimeImmutable
    {
        $dt = $this->toImmutable($value);

        if ($dt === null) {
            throw new \RuntimeException('Expected a non-null timestamp for DLQ projection.');
        }

        return $dt;
    }

    private function toImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }
}
