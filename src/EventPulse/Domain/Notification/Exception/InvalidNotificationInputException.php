<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Exception;

/**
 * Signals that a value supplied to a Notification factory or value object
 * was structurally invalid (malformed email, non-E.164 phone, payload shape
 * violation). Distinct from generic InvalidArgumentException so the HTTP
 * layer can map it to 422 without catching unrelated framework errors.
 */
class InvalidNotificationInputException extends \InvalidArgumentException {}
