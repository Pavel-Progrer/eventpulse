<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

/**
 * Page of `DlqEntry` projections returned by the list query.
 *
 * Cursor-based pagination chosen over offset-based:
 *  - Offset pagination is unstable across writes (a row inserted between
 *    page 1 and page 2 shifts every subsequent page).
 *  - Cursor pagination is stable: each page is anchored to a known
 *    boundary (the last entry's id + dead_lettered_at).
 *  - The DLQ is append-mostly with rare updates (replay), so cursor
 *    semantics fit the access pattern naturally.
 *
 * The cursor is opaque to the caller — encoded in the OpenAPI as a
 * string. The repository decides the format; today it's
 * "{ISO-timestamp}:{id}" but that is an implementation detail.
 *
 * `nextCursor` is null on the last page (no more results).
 */
final readonly class DlqEntryPage
{
    /**
     * @param  list<DlqEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {}
}
