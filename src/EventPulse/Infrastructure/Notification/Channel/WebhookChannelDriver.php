<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Channel;

use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Application\Notification\Channel\DispatchRequest;
use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Application\Notification\Channel\WebhookEndpoint;
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
 * Day 9 additions over Day 5:
 *  - `X-EventPulse-Signature` and `X-EventPulse-Timestamp` headers for
 *    HMAC-SHA256 origin verification (ADR-0005).
 *  - The signing secret is sourced from `WebhookEndpoint::signingSecret()`
 *    (decrypted by `EloquentWebhookEndpointResolver`). The driver adds the
 *    header when a secret is present; it omits it when `hasSigning()` is
 *    false, which happens only in in-memory test resolvers and the legacy
 *    unconfigured stub. This makes signing opt-in per-resolver, not
 *    opt-in per-driver, so production dispatch always signs.
 *
 * Signature scheme (see ADR-0005 §Decision):
 *  ```
 *  timestamp = Unix timestamp (seconds, UTC)
 *  signed_payload = "{timestamp}.{request_body_json}"
 *  signature = HMAC-SHA256(secret, signed_payload), hex-encoded
 *  X-EventPulse-Timestamp: {timestamp}
 *  X-EventPulse-Signature: sha256={signature}
 *  ```
 *
 * Failure classification (specification §6.1):
 *  - 2xx                                   → success
 *  - 408 (timeout), 429 (rate limit), 5xx  → `Transient` (retryable)
 *  - other 4xx                             → `Permanent`
 *  - 410 Gone                              → `Permanent`
 *  - connection refused / DNS / TLS errors → `Transient`
 *  - malformed endpoint, missing destination → `Unrecoverable`
 *
 * Why we always read the response body (truncated):
 *  failure debugging in webhooks is hard because the operator usually
 *  doesn't own the receiving side. Capturing a fragment of the body —
 *  capped to 1 KB — gives the operator the receiver's own error message.
 *
 * Body shape:
 *  Per the OpenAPI contract, webhook payloads have the shape
 *  `{body: {...}, headers?: {...}}`. The driver sends `body` as the JSON
 *  request body and `headers` (if present) as additional request headers.
 *  When the payload was constructed from a non-HTTP path and lacks the
 *  `body` envelope, the entire payload becomes the body — the permissive
 *  fallback keeps the driver usable from Artisan and internal callers.
 */
final class WebhookChannelDriver implements ChannelDriver
{
    private const int    RESPONSE_BODY_SNIPPET_BYTES = 1024;
    private const int    DEFAULT_TIMEOUT_SECONDS     = 30;
    private const string USER_AGENT                  = 'EventPulse/1.0';
    private const string SIGNATURE_ALGORITHM         = 'sha256';

    /**
     * Headers a caller is not allowed to override via the payload's `headers`
     * field. Reserving them prevents spoofing EventPulse's delivery metadata
     * or breaking transport semantics.
     */
    private const array RESERVED_HEADERS = [
        'content-type',
        'content-length',
        'host',
        'x-eventpulse-notification-id',
        'x-eventpulse-attempt',
        'x-correlation-id',
        'x-eventpulse-signature',
        'x-eventpulse-timestamp',
    ];

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

        $timestamp = (string) time();
        $bodyJson  = json_encode($body, JSON_THROW_ON_ERROR);

        $headers = $this->buildRequestHeaders(
            request:      $request,
            extraHeaders: $extraHeaders,
            endpoint:     $endpoint,
            timestamp:    $timestamp,
            bodyJson:     $bodyJson,
        );

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint->url(), $body);
        } catch (ConnectionException $e) {
            $this->logger->warning('notification.webhook.connection_failed', $logContext + [
                'url'             => $endpoint->url(),
                'exception_class' => $e::class,
                'reason'          => $e->getMessage(),
                'classification'  => FailureClassification::Transient->value,
            ]);

            return DispatchOutcome::failure(
                classification: FailureClassification::Transient,
                reason:         sprintf('connection failure: %s', $e->getMessage()),
            );
        } catch (\Throwable $e) {
            $this->logger->error('notification.webhook.unexpected_error', $logContext + [
                'url'             => $endpoint->url(),
                'exception_class' => $e::class,
                'reason'          => $e->getMessage(),
            ]);

            return DispatchOutcome::failure(
                classification: FailureClassification::Transient,
                reason:         sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }

        return $this->classifyResponse($response, $endpoint->url(), $logContext);
    }

    // ---------------------------------------------------------------------------
    // HMAC signing
    // ---------------------------------------------------------------------------

    /**
     * Computes the `X-EventPulse-Signature` value.
     *
     * Signed payload = `{timestamp}.{body_json}`.
     *
     * Including the timestamp in the signed payload means an attacker who
     * captures a valid signature cannot reuse it at a later timestamp — the
     * receiver checks that the timestamp is within a tolerance window (e.g.
     * ±5 minutes) and rejects signatures that have expired.
     *
     * Including the body in the signed payload binds the signature to the
     * exact content of the request — an attacker cannot replay a genuine
     * signature with a modified body.
     *
     * See ADR-0005 for the full rationale and the receiver verification steps.
     */
    private function computeSignature(string $secret, string $timestamp, string $bodyJson): string
    {
        $signedPayload = $timestamp . '.' . $bodyJson;

        return self::SIGNATURE_ALGORITHM . '=' . hash_hmac(
            self::SIGNATURE_ALGORITHM,
            $signedPayload,
            $secret,
        );
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function extractBodyAndHeaders(array $payload): array
    {
        // OpenAPI shape: {body: {...}, headers?: {...}}
        if (isset($payload['body']) && is_array($payload['body'])) {
            $body    = $payload['body'];
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
        return [$payload, []];
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function buildRequestHeaders(
        DispatchRequest $request,
        array $extraHeaders,
        WebhookEndpoint $endpoint,
        string $timestamp,
        string $bodyJson,
    ): array {
        $headers = [
            'Content-Type'                 => 'application/json',
            'User-Agent'                   => self::USER_AGENT,
            'X-EventPulse-Notification-ID' => $request->notificationId->toString(),
            'X-EventPulse-Attempt'         => (string) $request->attemptNumber->toInt(),
            'X-Correlation-ID'             => $request->correlationId->toString(),
        ];

        if ($endpoint->hasSigning()) {
            // Timestamp and signature are added together — the timestamp is
            // only meaningful to the receiver in the context of signature
            // verification (replay-window check). Sending it without a
            // signature would be noise; omitting both keeps the unsigned
            // path clean.
            $headers['X-EventPulse-Timestamp'] = $timestamp;
            $headers['X-EventPulse-Signature'] = $this->computeSignature(
                secret:    (string) $endpoint->signingSecret(),
                timestamp: $timestamp,
                bodyJson:  $bodyJson,
            );
        }

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
            'url'            => $url,
            'http_status'    => $status,
            'classification' => $classification->value,
            'response_body'  => $bodySnippet,
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
        // 410 Gone: the receiver has told us explicitly to stop.
        if ($status === 410) {
            return FailureClassification::Permanent;
        }

        // 408 (request timeout) and 429 (too many requests) are retryable 4xx.
        if ($status === 408 || $status === 429) {
            return FailureClassification::Transient;
        }

        if ($status >= 400 && $status < 500) {
            return FailureClassification::Permanent;
        }

        return FailureClassification::Transient;
    }
}
