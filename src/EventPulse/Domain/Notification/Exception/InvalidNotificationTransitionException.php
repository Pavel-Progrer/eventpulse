<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Exception;

/**
 * Raised when a state transition is attempted that the domain model forbids.
 *
 * Examples:
 *  - Transitioning a dispatched (terminal) notification.
 *  - Dead-lettering a notification that has never been attempted.
 *  - Calling beginAttempt() while an attempt is already in progress.
 */
final class InvalidNotificationTransitionException extends \DomainException {}
