<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Dlq;

use EventPulse\Application\Notification\DeadLetter\Query\DlqEntryPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a `DlqEntryPage` as the OpenAPI `PaginatedDlqEntries` JSON
 * shape: `{ data: DlqEntry[], pagination: { next_cursor: ?string } }`.
 *
 * The pagination shape is intentionally minimal:
 *  - **`next_cursor`** is the only field. The previous-page cursor is
 *    not exposed because we don't keep the back-pointer state needed
 *    to compute it (the read query is forward-only). Operators who
 *    need to scroll back take a step that the dashboard records — UX
 *    concern, not API concern.
 *  - No `total_count`. A count would force a second `COUNT(*)` query
 *    against the same predicate, doubling the cost of every list call,
 *    for a number that's stale before the response is rendered. Cursor
 *    pagination's contract is "give me the next batch," not "tell me
 *    how big this is."
 *
 * @property-read DlqEntryPage $resource
 */
final class DlqEntryPageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;

        return [
            'data'       => DlqEntryResource::collection($page->entries),
            'pagination' => [
                'next_cursor' => $page->nextCursor,
            ],
        ];
    }
}
