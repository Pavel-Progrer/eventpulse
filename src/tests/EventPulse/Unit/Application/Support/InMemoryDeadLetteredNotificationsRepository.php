<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Support;

use EventPulse\Application\Notification\DeadLetter\Query\DeadLetteredNotificationsRepository;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntry;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntryPage;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQuery;

/**
 * In-memory test double for `DeadLetteredNotificationsRepository`.
 *
 * Models the same access patterns as the Eloquent implementation:
 *   - filter by api_key_id (always),
 *   - optional reason / channel / date-range filters,
 *   - sort by deadLetteredAt desc, with id desc as a tiebreaker,
 *   - cursor pagination with the same `{ATOM}|{id}` format,
 *   - "limit + 1" detection of a next page.
 *
 * Living in tests/ — not src/ — because no production code path uses
 * an in-memory DLQ. It exists to keep handler tests fast and free of
 * a database boot.
 */
final class InMemoryDeadLetteredNotificationsRepository implements DeadLetteredNotificationsRepository
{
    /** @var list<DlqEntry> */
    private array $entries = [];

    /**
     * Caller supplies (apiKeyId, entry). The double does not infer the
     * tenant from the entry — the read model in production joins to
     * `notifications` and filters by `api_key_id` there, but the
     * projection itself doesn't carry the tenant id. We carry it
     * alongside in the test double.
     *
     * @var list<array{apiKeyId: string, entry: DlqEntry}>
     */
    private array $rows = [];

    public function add(string $apiKeyId, DlqEntry $entry): void
    {
        $this->rows[] = ['apiKeyId' => $apiKeyId, 'entry' => $entry];
    }

    #[\Override]
    public function list(ListDeadLetteredQuery $query): DlqEntryPage
    {
        $matching = array_values(array_filter(
            $this->rows,
            fn(array $row): bool => $this->matches($row, $query),
        ));

        // Sort: dead_lettered_at desc, id desc (tiebreaker).
        usort($matching, function (array $a, array $b): int {
            $cmp = $b['entry']->deadLetteredAt <=> $a['entry']->deadLetteredAt;

            return $cmp !== 0 ? $cmp : strcmp($b['entry']->id, $a['entry']->id);
        });

        // Cursor: drop everything up to and including the cursor row.
        if ($query->cursor !== null) {
            [$cursorTs, $cursorId] = $this->decodeCursor($query->cursor);

            $matching = array_values(array_filter(
                $matching,
                static function (array $row) use ($cursorTs, $cursorId): bool {
                    $entry = $row['entry'];

                    if ($entry->deadLetteredAt < $cursorTs) {
                        return true;
                    }

                    return $entry->deadLetteredAt == $cursorTs
                        && strcmp($entry->id, $cursorId) < 0;
                },
            ));
        }

        // limit + 1 trick.
        $hasMore = count($matching) > $query->limit;
        $page    = array_slice($matching, 0, $query->limit);

        $entries = array_map(static fn(array $row): DlqEntry => $row['entry'], $page);

        $nextCursor = null;
        if ($hasMore && $page !== []) {
            $last = end($page)['entry'];
            $nextCursor = $last->deadLetteredAt->format(\DateTimeInterface::ATOM)
                . '|' . $last->id;
        }

        return new DlqEntryPage($entries, $nextCursor);
    }

    /**
     * @param array{apiKeyId: string, entry: DlqEntry} $row
     */
    private function matches(array $row, ListDeadLetteredQuery $query): bool
    {
        if ($row['apiKeyId'] !== $query->apiKeyId) {
            return false;
        }

        $entry = $row['entry'];

        if ($query->reason !== null && $entry->reason !== $query->reason) {
            return false;
        }

        if ($query->channel !== null && $entry->channel !== $query->channel) {
            return false;
        }

        if ($query->createdAfter !== null && $entry->deadLetteredAt < $query->createdAfter) {
            return false;
        }

        if ($query->createdBefore !== null && $entry->deadLetteredAt >= $query->createdBefore) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: string}
     */
    private function decodeCursor(string $cursor): array
    {
        $parts = explode('|', $cursor, 2);

        return [new \DateTimeImmutable($parts[0]), $parts[1]];
    }
}
