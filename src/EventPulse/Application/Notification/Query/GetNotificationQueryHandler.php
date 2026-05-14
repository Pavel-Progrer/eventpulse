<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Returns the requested `Notification` aggregate, or throws if the id is
 * unknown or belongs to a different API key.
 *
 * Tenant isolation is implicit: `findById()` returns the aggregate regardless
 * of owner, so the handler must compare `apiKeyId` explicitly. The exception
 * type is `NotificationNotFoundException` (not a forbidden exception) to avoid
 * leaking the existence of another tenant's notification — the HTTP layer maps
 * both conditions to 404.
 */
final class GetNotificationQueryHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
    ) {}

    public function __invoke(GetNotificationQuery $query): Notification
    {
        try {
            $id = NotificationId::fromString($query->notificationId);
        } catch (\InvalidArgumentException) {
            // A syntactically invalid id (e.g. not UUID v4) can never exist
            // in the repository. Treat it identically to "not found" so the
            // caller cannot distinguish a malformed id from a missing one —
            // the same information-disclosure reasoning that collapses wrong-
            // tenant results into 404 rather than 403.
            throw new NotificationNotFoundException(sprintf(
                'Notification %s not found.',
                $query->notificationId,
            ));
        }

        $notification = $this->repository->findById($id);

        if ($notification === null || $notification->apiKeyId() !== $query->apiKeyId) {
            throw new NotificationNotFoundException(sprintf(
                'Notification %s not found.',
                $query->notificationId,
            ));
        }

        return $notification;
    }
}
