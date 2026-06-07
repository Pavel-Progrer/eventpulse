<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Channel;

use EventPulse\Domain\Notification\Enum\FailureClassification;

/**
 * The result of a single channel dispatch attempt.
 *
 * Discriminated by the `succeeded` boolean: on success, `classification`
 * and `reason` are null and `providerMessageId` may carry an external
 * tracking id; on failure, `classification` and `reason` are populated and
 * `providerMessageId` is null.
 *
 * Why not a sealed hierarchy (`DispatchOutcome\Success`, `\Failure`)?
 *  PHP 8.4 has no `sealed` keyword; emulating it with abstract classes plus
 *  `match (true) { $o instanceof Success => ..., ... }` adds two files for
 *  one negligible safety gain. The named-constructor approach makes the two
 *  shapes structurally distinct (the private constructor prevents anyone
 *  building an outcome where both `succeeded === true` and
 *  `classification !== null`), and downstream consumers branch on
 *  `$outcome->succeeded` cleanly.
 *
 * Why include `providerMessageId`?
 *  It's the externally meaningful id for the dispatched message — the SMTP
 *  provider's message-id, a webhook receiver's response correlator, an SMS
 *  gateway's tracking id. Persisting it on `Attempt` (a future enhancement
 *  alongside `recordSuccess`) lets the operator correlate an EventPulse
 *  attempt with a provider's bounce report or delivery receipt. Day 5 only
 *  carries the field through the outcome; the persistence wiring lands
 *  with the bounce/delivery feature later in Phase 1.
 *
 * Why a non-empty `$reason` on failure:
 *  An empty string is indistinguishable from "no reason given," which makes
 *  log triage harder and disables the eventual operator-visible DLQ
 *  display. We reject the empty case at the named-constructor boundary so
 *  every failure outcome carries the diagnostic information that motivated
 *  the failure in the first place.
 */
final readonly class DispatchOutcome
{
    private function __construct(
        public bool $succeeded,
        public ?FailureClassification $classification,
        public ?string $reason,
        public ?string $providerMessageId,
    ) {}

    /**
     * The channel's external system accepted responsibility for the message.
     *
     * `$providerMessageId` is optional because not every transport returns
     * one (Laravel's mailer contract does not surface it; a Postmark or SES
     * integration would).
     */
    public static function success(?string $providerMessageId = null): self
    {
        return new self(
            succeeded: true,
            classification: null,
            reason: null,
            providerMessageId: $providerMessageId,
        );
    }

    /**
     * The channel attempted delivery and the external system rejected it
     * or the I/O itself failed.
     *
     * `$reason` must be non-empty: the application layer logs it on every
     * failed attempt and surfaces it on the DLQ entry. An empty reason
     * would silently degrade observability.
     */
    public static function failure(
        FailureClassification $classification,
        string $reason,
    ): self {
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'A failed DispatchOutcome must include a non-empty reason.'
            );
        }

        return new self(
            succeeded: false,
            classification: $classification,
            reason: $reason,
            providerMessageId: null,
        );
    }
}
