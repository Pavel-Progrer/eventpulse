<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\Channel;

/**
 * Input for `ListDeadLetteredQueryHandler`.
 *
 * Fields mirror the OpenAPI `GET /api/v1/dlq` query parameters. Each
 * filter is optional; combining filters narrows the result set
 * (intersection semantics).
 *
 * `apiKeyId` is required and not optional: every DLQ list request
 * runs in the security context of an API key. Cross-tenant DLQ access
 * is intentionally not exposed even to admins (an admin scope still
 * binds to a key); the rationale is in ADR-0006.
 *
 * `limit` is bounded at the application layer: the FormRequest in the
 * HTTP boundary additionally validates the range, but the query
 * handler does not trust HTTP-layer validation as the only line of
 * defence.
 */
final readonly class ListDeadLetteredQuery
{
    public function __construct(
        public string $apiKeyId,
        public ?string $reason = null,
        public ?Channel $channel = null,
        public ?DateTimeImmutable $createdAfter = null,
        public ?DateTimeImmutable $createdBefore = null,
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException(sprintf(
                'Limit must be in [1, 100]; got %d.',
                $limit,
            ));
        }
    }
}
