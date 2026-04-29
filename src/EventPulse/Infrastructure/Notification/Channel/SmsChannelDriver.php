<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use Psr\Log\LoggerInterface;

/**
 * Honest stub: SMS dispatch is part of the public channel surface but no
 * SMS provider has been integrated yet.
 *
 * Why a stub that *fails* rather than a stub that silently succeeds:
 *  a stub that returns success would let SMS notifications progress
 *  through the system as if they had been delivered, with no operator-
 *  visible signal that real customers never received anything. A stub
 *  that fails — with a `Permanent` classification and an actionable
 *  reason — fails predictably, surfaces in logs and the DLQ exactly as
 *  any other configuration problem would, and tells the operator the
 *  one thing they need to know: "to enable SMS, replace this driver."
 *
 * Why `Permanent` and not `Unrecoverable`:
 *  unrecoverable is the spec's classification for "the dependency is
 *  catastrophically gone" (a deleted destination). The SMS driver is in
 *  a known, intentional unconfigured state — equivalent to a 4xx from
 *  a real provider rejecting requests. Permanent dead-letters cleanly
 *  after the channel's max-attempt count, giving the operator the same
 *  failure shape as any other persistent provider rejection.
 *
 * The class still implements the full `ChannelDriver` contract so that
 * `ChannelDispatcher`'s exhaustiveness check at boot is satisfied: an
 * SMS notification doesn't crash the worker; it dead-letters with a
 * clear reason, which is the production-correct behaviour.
 *
 * Replacing this driver is a single binding change in
 * `EventPulseServiceProvider`. The interface contract is the only
 * surface a real provider integration needs to honour.
 */
final class SmsChannelDriver implements ChannelDriver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function channel(): Channel
    {
        return Channel::Sms;
    }

    #[\Override]
    public function dispatch(DispatchRequest $request): DispatchOutcome
    {
        if (!$request->recipient instanceof SmsRecipient) {
            throw new \LogicException(sprintf(
                'SmsChannelDriver received a %s recipient; expected SmsRecipient.',
                $request->recipient::class,
            ));
        }

        $this->logger->warning('notification.sms.driver_unconfigured', [
            'event'           => 'notification.sms.driver_unconfigured',
            'notification_id' => $request->notificationId->toString(),
            'attempt_number'  => $request->attemptNumber->toInt(),
            'recipient'       => $request->recipient->toString(),
            'correlation_id'  => $request->correlationId->toString(),
            'classification'  => FailureClassification::Permanent->value,
        ]);

        return DispatchOutcome::failure(
            classification: FailureClassification::Permanent,
            reason:         'SMS dispatch is not configured in this build. '
                          . 'Replace EventPulse\\Infrastructure\\Notification\\Channel\\SmsChannelDriver '
                          . 'with a provider integration (e.g. Twilio, MessageBird) and re-bind it '
                          . 'in EventPulseServiceProvider to enable SMS delivery.',
        );
    }
}
