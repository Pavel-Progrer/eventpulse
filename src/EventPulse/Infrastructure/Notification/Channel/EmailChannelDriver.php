<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Psr\Log\LoggerInterface;

/**
 * Sends notifications through SMTP using Laravel's `Mailer` contract.
 *
 * Why the framework's `Mailer` rather than Symfony Mailer or PHPMailer
 * directly:
 *  - this is a Laravel application; using the framework's mail
 *    abstraction is consistent with the rest of the codebase,
 *  - the contract is a thin enough surface that swapping implementations
 *    (a queue-based mailer, a fake in tests, a Postmark transport) is a
 *    container-binding change, not a driver rewrite,
 *  - `Mail::fake()` gives integration tests a deterministic seam.
 *
 * Failure classification (specification §6.1):
 *  - SMTP 5xx codes that indicate permanent rejection (invalid mailbox,
 *    address syntax, blocked sender) → `Permanent`. The mailbox does not
 *    exist; retrying will not change that.
 *  - SMTP 4xx, connection failures, timeouts, throttling → `Transient`.
 *    These are the canonical retryable errors.
 *  - Any other `Throwable` → `Transient`. The conservative choice: we
 *    would rather retry an unknown failure than silently lose a
 *    notification that might actually have been deliverable on a second
 *    try. Repeated transient failures hit the channel's max-attempt
 *    ceiling and dead-letter normally.
 *
 * Why catch `Throwable` rather than specific Symfony Mailer exceptions:
 *  the `Mailer` contract promises nothing about its exception surface.
 *  Different transports throw different concrete types; the underlying
 *  Symfony Mailer alone has six concrete `TransportExceptionInterface`
 *  implementations. The driver is the seam where "any failure" becomes
 *  "a structured `DispatchOutcome`," which is the contract the rest of
 *  the system relies on.
 */
final class EmailChannelDriver implements ChannelDriver
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        // Defence-in-depth: the service provider validates these at
        // wiring time, but the driver also rejects empty values at the
        // boundary so an alternate construction path (a test, an
        // Artisan command, a future binding override) cannot produce
        // a driver that sends mail with a missing From header.
        if (trim($fromAddress) === '') {
            throw new \InvalidArgumentException(
                'EmailChannelDriver requires a non-empty fromAddress.'
            );
        }

        if (trim($fromName) === '') {
            throw new \InvalidArgumentException(
                'EmailChannelDriver requires a non-empty fromName.'
            );
        }
    }

    #[\Override]
    public function channel(): Channel
    {
        return Channel::Email;
    }

    #[\Override]
    public function dispatch(DispatchRequest $request): DispatchOutcome
    {
        // Domain invariant 5.1.9 guarantees the recipient/channel match,
        // so reaching this branch means the aggregate was constructed
        // through a path that bypassed `Notification::request()` —
        // a programming error we surface, not a delivery failure.
        if (! $request->recipient instanceof EmailRecipient) {
            throw new \LogicException(sprintf(
                'EmailChannelDriver received a %s recipient; expected EmailRecipient.',
                $request->recipient::class,
            ));
        }

        $payload = $request->payload->toArray();
        $subject = $payload['subject'];
        $bodyText = isset($payload['text']) && $payload['text'] !== '' ? (string) $payload['text'] : null;
        $bodyHtml = isset($payload['html']) && $payload['html'] !== '' ? (string) $payload['html'] : null;

        $logContext = [
            'event' => 'notification.email.dispatch',
            'notification_id' => $request->notificationId->toString(),
            'attempt_number' => $request->attemptNumber->toInt(),
            'recipient_address' => $request->recipient->toString(),
            'correlation_id' => $request->correlationId->toString(),
        ];

        try {
            $this->mailer->send(
                view: [],
                data: [],
                callback: function (Message $message) use ($request, $subject, $bodyText, $bodyHtml): void {
                    $message
                        ->from($this->fromAddress, $this->fromName)
                        ->to($request->recipient->toString())
                        ->subject($subject);

                    if ($bodyHtml !== null) {
                        $message->html($bodyHtml);
                    }

                    if ($bodyText !== null) {
                        // When both are present, Laravel sets text as the
                        // alternative part of a multipart/alternative
                        // message — same as the framework's MailMessage.
                        $message->text($bodyText);
                    }
                },
            );
        } catch (\Throwable $e) {
            $classification = $this->classifyMailerException($e);

            $this->logger->warning('notification.email.dispatch_failed', $logContext + [
                'exception_class' => $e::class,
                'reason' => $e->getMessage(),
                'classification' => $classification->value,
            ]);

            return DispatchOutcome::failure(
                classification: $classification,
                reason: sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }

        $this->logger->info('notification.email.dispatched', $logContext);

        // The `Mailer` contract has no return value; the underlying
        // provider message id is not exposed. We return success without
        // it. A future driver against a transactional ESP (Postmark, SES)
        // can carry the provider id back through the outcome.
        return DispatchOutcome::success();
    }

    /**
     * Map an exception thrown by the mailer into a domain failure
     * classification.
     *
     * The matching is on substrings of the exception message because the
     * `Mailer` contract does not type its exceptions (see class docblock).
     * Each matched substring is documented inline so the rationale is
     * visible alongside the rule, and the fallback is `Transient` on the
     * conservative principle that an unclassified failure is more likely
     * to be a one-off than a permanent rejection.
     */
    private function classifyMailerException(\Throwable $e): FailureClassification
    {
        $message = strtolower($e->getMessage());

        // SMTP 5xx codes that consistently indicate a permanent rejection.
        // We match on the textual signature rather than parsing the SMTP
        // response because the message format varies between transports
        // (Symfony Mailer renders as "code 550 ...", others as "550 ...").
        $permanentSignals = [
            '550',                  // mailbox unavailable, generic 5xx
            '551',                  // user not local; please try
            '553',                  // mailbox name not allowed
            '554',                  // transaction failed (often spam reject)
            'invalid address',
            'mailbox unavailable',
            'no such user',
            'recipient rejected',
            'address rejected',
        ];

        foreach ($permanentSignals as $signal) {
            if (str_contains($message, $signal)) {
                return FailureClassification::Permanent;
            }
        }

        return FailureClassification::Transient;
    }
}
