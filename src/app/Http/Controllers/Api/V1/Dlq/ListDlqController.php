<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dlq;

use App\Http\Requests\Api\V1\Dlq\ListDlqRequest;
use App\Http\Resources\Api\V1\Dlq\DlqEntryPageResource;
use App\Models\ApiKey;
use DateTimeImmutable;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQuery;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQueryHandler;
use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/dlq` — list dead-lettered notifications visible to the
 * caller's API key, with optional filters.
 *
 * Per project conventions (ADR-0003 §1) the controller is thin:
 *  - resolve the authenticated `ApiKey` from request attributes,
 *  - map validated query params to a `ListDeadLetteredQuery`,
 *  - invoke the handler,
 *  - wrap the result in a resource.
 *
 * Tenant scope (per ADR-0006 §"DLQ visibility is tenant-scoped"): the
 * `apiKeyId` is taken from the authenticated key, never from request
 * input. There is no path through this controller that returns a row
 * for any other tenant's key.
 */
final class ListDlqController
{
    public function __construct(
        private readonly ListDeadLetteredQueryHandler $handler,
    ) {}

    public function __invoke(ListDlqRequest $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $query = new ListDeadLetteredQuery(
            apiKeyId:      $apiKey->id,
            reason:        $request->validated('reason'),
            channel:       $this->parseChannel($request->validated('channel')),
            createdAfter:  $this->parseDateTime($request->validated('created_after')),
            createdBefore: $this->parseDateTime($request->validated('created_before')),
            limit:         (int) $request->validated('limit', 25),
            cursor:        $request->validated('cursor'),
        );

        $page = ($this->handler)($query);

        return DlqEntryPageResource::make($page)
            ->response();
    }

    private function parseChannel(?string $raw): ?Channel
    {
        return $raw === null ? null : Channel::from($raw);
    }

    private function parseDateTime(?string $raw): ?DateTimeImmutable
    {
        // FormRequest's `date` rule already validated the format; we just
        // construct the value object. Failure here would mean the rule
        // accepted something it shouldn't.
        return $raw === null ? null : new DateTimeImmutable($raw);
    }
}
