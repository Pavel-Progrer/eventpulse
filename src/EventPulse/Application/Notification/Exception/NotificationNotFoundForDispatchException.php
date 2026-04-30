<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Exception;

use EventPulse\Domain\Notification\ValueObject\NotificationId;
use RuntimeException;

/**
 * Thrown by `DispatchNotificationJob` when the notification identified by
 * the job payload cannot be loaded from the repository at dispatch time.
 *
 * This is a ground-truth anomaly, not a normal failure mode: the notification
 * was persisted before the job was enqueued, and EventPulse exposes no admin
 * endpoint or domain operation that deletes notifications. If the row is
 * missing at worker-pickup time, something out-of-band has happened — manual
 * SQL, a corrupted restore, a misconfigured wipe job — and the system should
 * surface that loudly rather than silently dropping the job.
 *
 * Why a named exception rather than `\RuntimeException` with a string message:
 *  - Worker log filters can target `NotificationNotFoundForDispatchException`
 *    by class to alert on this specific anomaly without false positives from
 *    other runtime errors that happen to mention "not found".
 *  - The class name itself documents that the case was considered. A bare
 *    `RuntimeException` reads as "we didn't think about this".
 *  - Tests can assert against the precise type rather than message-matching
 *    a brittle string.
 *
 * The job is configured with `tries = 1`, so this exception immediately
 * moves the job to the `failed_jobs` table for human inspection. Retrying
 * would not help — if the row is gone, it stays gone.
 *
 * Carries the `NotificationId` so the failed-job record and any structured
 * log entry can include the missing id without re-parsing the job payload.
 */
final class NotificationNotFoundForDispatchException extends RuntimeException
{
    public function __construct(
        private readonly NotificationId $notificationId,
    ) {
        parent::__construct(
            sprintf(
                'Notification %s not found at dispatch time; row may have been removed out-of-band.',
                $notificationId->toString(),
            ),
        );
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }
}