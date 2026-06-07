<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Exception;

use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use RuntimeException;

/**
 * The same `Idempotency-Key` was submitted with a different request body
 * within the dedup window (OpenAPI: HTTP 409).
 *
 * Why an Application-layer exception, not a Domain one:
 *  Detecting a conflict requires comparing the incoming submission against an
 *  *already-persisted* notification — which is only possible by going through
 *  the repository. The aggregate by itself cannot detect this; it only enforces
 *  that any single notification's identity-by-idempotency tuple is unique.
 *
 *  Phrased differently: invariant 5.1.8 (idempotency keys uniquely identify
 *  notifications scoped to api_key) is the *domain* rule; the *workflow* of
 *  detecting a conflict before persistence is application-layer logic.
 *
 * Why distinct from `RecipientChannelMismatchException` etc. (which are 422):
 *  A 422 says "your request was malformed; fix it." A 409 says "your request
 *  is well-formed but conflicts with an earlier one; the resolution requires
 *  understanding what was previously submitted." HTTP semantics differentiate
 *  these and so does this exception class.
 *
 * Carries the idempotency key for inclusion in the error envelope's `details`
 * — no payload data leaks, only the key the caller already knows.
 */
final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        private readonly string $apiKeyId,
        private readonly IdempotencyKey $idempotencyKey,
    ) {
        parent::__construct(
            sprintf(
                'Idempotency-Key "%s" was previously submitted with a different request body.',
                $idempotencyKey->toString(),
            ),
        );
    }

    public function apiKeyId(): string
    {
        return $this->apiKeyId;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }
}
