<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Use case: load one dead-lettered notification for inspection,
 * including its full attempt history and dead-letter metadata.
 *
 * Returns the full `Notification` aggregate, loaded via the existing
 * `NotificationRepository::findById()` (which now includes attempts
 * and the dead-letter mark, thanks to Day 8's repository extension).
 * The HTTP resource layer formats the response.
 *
 * Three concrete checks make this not-quite-trivial:
 *  1. **Tenant scope.** A notification visible to tenant A is invisible
 *     to tenant B even if B knows the id. Same exception for "not
 *     found" and "not yours" — see `DeadLetteredNotificationNotFoundException`.
 *  2. **Dead-lettered status.** A notification that exists but is
 *     queued/processing/dispatched/failed is not a DLQ entry; this
 *     endpoint is not the general-purpose status endpoint and should
 *     not double as one.
 *  3. **NotificationId validation.** Caller-supplied id is parsed
 *     through the value object's factory; an invalid format throws an
 *     `InvalidNotificationInputException` which the API renderer maps
 *     to 422. (Distinct from "valid format but no such row," which
 *     maps to 404 above.)
 */
final class GetDeadLetteredQueryHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
    ) {}

    public function __invoke(GetDeadLetteredQuery $query): Notification
    {
        $id = NotificationId::fromString($query->notificationId);

        $notification = $this->repository->findById($id);

        // The same exception covers three failure modes — see class docblock
        // and the exception's own docblock for why information disclosure
        // motivates that.
        if ($notification === null) {
            throw new DeadLetteredNotificationNotFoundException($query->notificationId);
        }

        if ($notification->apiKeyId() !== $query->apiKeyId) {
            throw new DeadLetteredNotificationNotFoundException($query->notificationId);
        }

        if ($notification->status() !== NotificationStatus::DeadLettered) {
            throw new DeadLetteredNotificationNotFoundException($query->notificationId);
        }

        return $notification;
    }
}
