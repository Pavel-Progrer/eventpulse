<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Entity;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;

/**
 * A single, concrete effort to dispatch a notification (domain.md §2, §5.1).
 *
 * Invariants enforced here:
 *  - Attempt records are append-only: written once, completed once, never
 *    modified thereafter (invariant 5.1.4).
 *  - An attempt in progress has `completedAt === null`; a completed attempt
 *    always has a non-null `completedAt` and a success or failure outcome.
 *
 * Attempt is an Entity (it has identity — the attempt number within the
 * notification), but it is NOT an aggregate root. No external code constructs
 * an Attempt directly; all construction goes through `Notification::beginAttempt()`.
 * The `internal` comment at the factory method is the convention that enforces
 * this in the absence of package-private visibility in PHP.
 */
final class Attempt
{
    private ?DateTimeImmutable $completedAt = null;

    private ?bool $succeeded = null;

    private ?FailureClassification $failureClassification = null;

    private ?string $failureReason = null;

    /**
     * @internal Called only by the Notification aggregate root.
     */
    public function __construct(
        private readonly AttemptNumber $number,
        private readonly DateTimeImmutable $startedAt,
    ) {}

    // ---------------------------------------------------------------------------
    // State transitions — called by Notification, not by external code
    // ---------------------------------------------------------------------------

    /**
     * @internal Called only by the Notification aggregate root on success.
     */
    public function recordSuccess(DateTimeImmutable $completedAt): void
    {
        $this->assertNotAlreadyCompleted();
        $this->completedAt = $completedAt;
        $this->succeeded = true;
    }

    /**
     * @internal Called only by the Notification aggregate root on failure.
     */
    public function recordFailure(
        FailureClassification $classification,
        string $reason,
        DateTimeImmutable $completedAt,
    ): void {
        $this->assertNotAlreadyCompleted();
        $this->completedAt = $completedAt;
        $this->succeeded = false;
        $this->failureClassification = $classification;
        $this->failureReason = $reason;
    }

    // ---------------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------------

    public function number(): AttemptNumber
    {
        return $this->number;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isInProgress(): bool
    {
        return $this->completedAt === null;
    }

    public function succeeded(): ?bool
    {
        return $this->succeeded;
    }

    public function failureClassification(): ?FailureClassification
    {
        return $this->failureClassification;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    // ---------------------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------------------

    private function assertNotAlreadyCompleted(): void
    {
        if ($this->completedAt !== null) {
            throw new \LogicException(
                sprintf('Attempt #%d has already been completed.', $this->number->toInt())
            );
        }
    }
}
