<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * POSTs notification payloads to a registered webhook destination.
 *
 * The driver depends on `WebhookEndpointResolver` to translate a
 * destination id (carried by `WebhookRecipient`) into a usable URL. Day 9
 * widens that resolved endpoint to include the signing secret and the
 * driver gains the HMAC headers; Day 5 ships the basic POST.
 *
 * Failure classification (specification §6.1):
 *  - 2xx                                   → success
 *  - 408 (timeout), 429 (rate limit), 5xx  → `Transient` (retryable)
 *  - other 4xx                             → `Permanent` (the receiver
 *    consistently rejects this; retrying won't help)
 *  - 410 Gone                              → `Permanent`. The receiver
 *    has explicitly told us it no longer accepts traffic at this URL —
 *    the spec mentions this as the canonical "stop trying" signal.
 *  - connection refused / DNS / TLS errors → `Transient`. These are
 *    network-state issues that may resolve on retry.
 *  - malformed endpoint, missing destination → `Unrecoverable`. The
 *    notification cannot ever succeed against a target that doesn't
 *    exist; dead-letter immediately.
 *
 * Why we always read the response body (truncated):
 *  failure debugging in webhooks is hard because the operator usually
 *  doesn't own the receiving side. Capturing a fragment of the body —
 *  capped to 1 KB so a misbehaving receiver dumping a 10 MB stack trace
 *  doesn't bloat our database — gives the operator the receiver's own
 *  error message, which is usually the fastest way to diagnose.
 *
 * Body shape:
 *  Per the OpenAPI contract, webhook payloads have the shape
 *  `{body: {...}, headers?: {...}}`. The driver sends `body` as the JSON
 *  request body and `headers` (if present) as additional request headers.
 *  When the payload was constructed from a non-HTTP path and lacks the
 *  `body` envelope, the entire payload becomes the body — the
 *  permissive fallback keeps the driver usable from Artisan and
 *  internal callers without forcing the OpenAPI shape to leak into the
 *  domain.
 */
final class WebhookChannelDriver implements ChannelDriver
{
    private const int RESPONSE_BODY_SNIPPET_BYTES = 1024;
    private const int DEFAULT_TIMEOUT_SECONDS    = 30;
    private const string USER_AGENT              = 'EventPulse/1.0';
    /**
     * Headers a caller is not allowed to override via the payload's
     * `headers` field. Reserving them prevents a caller from spoofing
     * EventPulse's own delivery metadata or breaking transport semantics.
     */
    private const RESERVED_HEADERS = [
        'content-type',
        'content-length',
        'host',
        'x-eventpulse-notification-id',
        'x-eventpulse-attempt',
        'x-correlation-id',
        'x-eventpulse-signature',
        'x-eventpulse-timestamp',
    ];

    /**
     * @param int $timeoutSeconds Per-request timeout. 30s by default,
     *                            matching the `DispatchNotificationJob::$timeout`
     *                            calculation: well under the worker
     *                            timeout, well over the typical receiver
     *                            response time.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly WebhookEndpointResolver $endpointResolver,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    #[\Override]
    public function channel(): Channel
    {
        return Channel::Webhook;
    }

    #[\Override]
    public function dispatch(DispatchRequest $request): DispatchOutcome
    {
        if (!$request->recipient instanceof WebhookRecipient) {
            throw new \LogicException(sprintf(
                'WebhookChannelDriver received a %s recipient; expected WebhookRecipient.',
                $request->recipient::class,
            ));
        }

        $logContext = [
            'event'           => 'notification.webhook.dispatch',
            'notification_id' => $request->notificationId->toString(),
            'attempt_number'  => $request->attemptNumber->toInt(),
            'destination_id'  => $request->recipient->destinationId(),
            'correlation_id'  => $request->correlationId->toString(),
        ];

        try {
            $endpoint = $this->endpointResolver->resolve($request->recipient);
        } catch (WebhookEndpointResolutionException $e) {
            $this->logger->warning('notification.webhook.endpoint_resolution_failed', $logContext + [
                'reason'         => $e->getMessage(),
                'classification' => $e->classification->value,
            ]);

            return DispatchOutcome::failure(
                classification: $e->classification,
                reason:         $e->getMessage(),
            );
        }

        [$body, $extraHeaders] = $this->extractBodyAndHeaders($request->payload->toArray());

        $headers = $this->buildRequestHeaders($request, $extraHeaders);

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint->url, $body);
        } catch (ConnectionException $e) {
            // DNS, TLS, connection-refused, idle-read timeout. All of
            // these are network-state issues; retry has a real chance of
            // succeeding.
            $this->logger->warning('notification.webhook.connection_failed', $logContext + [
                'url'             => $endpoint->url,
                'exception_class' => $e::class,
                'reason'          => $e->getMessage(),
                'classification'  => FailureClassification::Transient->value,
            ]);

            return DispatchOutcome::failure(
                classification: FailureClassification::Transient,
                reason:         sprintf('connection failure: %s', $e->getMessage()),
            );
        } catch (\Throwable $e) {
            // Anything else is a programmer error in the driver or its
            // dependencies — the wrong URL shape, a misconfigured client,
            // a timeout exception that didn't extend ConnectionException.
            // Transient is the safe choice: the worker will retry, and
            // if the bug is real the second try will surface it again.
            $this->logger->error('notification.webhook.unexpected_error', $logContext + [
                'url'             => $endpoint->url,
                'exception_class' => $e::class,
                'reason'          => $e->getMessage(),
                'classification'  => FailureClassification::Permanent->value,
            ]);

            return DispatchOutcome::failure(
                classification: FailureClassification::Transient,
                reason:         sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }

        return $this->classifyResponse($response, $endpoint->url, $logContext);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function extractBodyAndHeaders(array $payload): array
    {
        // OpenAPI shape: {body: {...}, headers?: {...}}
        if (isset($payload['body']) && is_array($payload['body'])) {
            $body = $payload['body'];
            /** @var array<string, string> $headers */
            $headers = [];

            if (isset($payload['headers']) && is_array($payload['headers'])) {
                foreach ($payload['headers'] as $name => $value) {
                    if (is_string($name) && is_scalar($value)) {
                        $headers[$name] = (string) $value;
                    }
                }
            }

            return [$body, $headers];
        }

        // Permissive fallback: treat the entire payload as the body.
        // Used when the notification was constructed from a non-HTTP
        // path that doesn't follow the OpenAPI envelope.
        return [$payload, []];
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function buildRequestHeaders(DispatchRequest $request, array $extraHeaders): array
    {
        $headers = [
            'Content-Type'                => 'application/json',
            'User-Agent'                  => self::USER_AGENT,
            'X-EventPulse-Notification-ID' => $request->notificationId->toString(),
            'X-EventPulse-Attempt'         => (string) $request->attemptNumber->toInt(),
            'X-Correlation-ID'             => $request->correlationId->toString(),
            // Day 9 adds: X-EventPulse-Signature, X-EventPulse-Timestamp.
        ];

        foreach ($extraHeaders as $name => $value) {
            if (in_array(strtolower($name), self::RESERVED_HEADERS, strict: true)) {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function classifyResponse(Response $response, string $url, array $logContext): DispatchOutcome
    {
        $status = $response->status();

        if ($response->successful()) {
            $this->logger->debug('notification.webhook.dispatched', $logContext + [
                'url'         => $url,
                'http_status' => $status,
            ]);

            return DispatchOutcome::success();
        }

        $bodySnippet = mb_substr((string) $response->body(), 0, self::RESPONSE_BODY_SNIPPET_BYTES);

        $classification = $this->classifyHttpStatus($status);

        $this->logger->warning('notification.webhook.dispatch_failed', $logContext + [
            'url'             => $url,
            'http_status'     => $status,
            'classification'  => $classification->value,
            'response_body'   => $bodySnippet,
        ]);

        return DispatchOutcome::failure(
            classification: $classification,
            reason:         sprintf(
                'webhook receiver returned HTTP %d: %s',
                $status,
                $bodySnippet === '' ? '(empty body)' : $bodySnippet,
            ),
        );
    }

    private function classifyHttpStatus(int $status): FailureClassification
    {
        // 410 Gone: the spec calls this out — the receiver has told us
        // explicitly to stop. Don't waste retries; permanent.
        if ($status === 410) {
            return FailureClassification::Permanent;
        }

        // 408 (request timeout) and 429 (too many requests) are the
        // 4xx codes that *are* retryable: the receiver is asking us to
        // back off, not telling us our request is wrong.
        if ($status === 408 || $status === 429) {
            return FailureClassification::Transient;
        }

        if ($status >= 400 && $status < 500) {
            return FailureClassification::Permanent;
        }

        // 5xx, plus the (unlikely) 1xx and 3xx that fell through here:
        // treat as transient. The receiver's own infrastructure may
        // recover on retry.
        return FailureClassification::Transient;
    }
}
