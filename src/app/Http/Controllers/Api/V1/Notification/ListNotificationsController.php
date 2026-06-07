<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Requests\Api\V1\Notification\ListNotificationsRequest;
use App\Http\Resources\Api\V1\Notification\NotificationPageResource;
use App\Models\ApiKey;
use DateTimeImmutable;
use EventPulse\Application\Notification\Query\ListNotificationsQuery;
use EventPulse\Application\Notification\Query\ListNotificationsQueryHandler;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/notifications` — paginated list of the caller's notifications.
 *
 * Required scope: `notifications:read`.
 *
 * Per ADR-0003 §1 this controller is thin: validate → map → invoke handler →
 * wrap result. No domain logic or Eloquent queries live here.
 */
final class ListNotificationsController
{
    public function __construct(
        private readonly ListNotificationsQueryHandler $handler,
    ) {}

    public function __invoke(ListNotificationsRequest $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $query = new ListNotificationsQuery(
            apiKeyId: (string) $apiKey->id,
            statuses: $this->parseStatuses($request->validated('status', [])),
            channels: $this->parseChannels($request->validated('channel', [])),
            correlationId: $request->validated('correlation_id'),
            createdAfter: $this->parseDateTime($request->validated('created_after')),
            createdBefore: $this->parseDateTime($request->validated('created_before')),
            limit: (int) $request->validated('limit', 50),
            cursor: $request->validated('cursor'),
        );

        $page = ($this->handler)($query);

        return NotificationPageResource::make($page)->response();
    }

    /**
     * @param  list<string>  $raw
     * @return list<NotificationStatus>
     */
    private function parseStatuses(array $raw): array
    {
        return array_map(
            static fn (string $s) => NotificationStatus::from($s),
            $raw,
        );
    }

    /**
     * @param  list<string>  $raw
     * @return list<Channel>
     */
    private function parseChannels(array $raw): array
    {
        return array_map(
            static fn (string $c) => Channel::from($c),
            $raw,
        );
    }

    private function parseDateTime(?string $raw): ?DateTimeImmutable
    {
        return $raw === null ? null : new DateTimeImmutable($raw);
    }
}
