<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Exception;

/**
 * Raised by `WebhookDestination::disable()` when the destination is already
 * in the disabled state.
 *
 * Disabling an already-disabled destination is a caller error — not a
 * transient infrastructure failure — so this extends `\DomainException`
 * rather than `\RuntimeException`. The HTTP layer maps it to a 409 Conflict.
 */
final class WebhookDestinationAlreadyDisabledException extends \DomainException {}
