<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Enum;

use EventPulse\Domain\Notification\Enum\NotificationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The state machine is the domain's most important invariant. These tests
 * exhaustively cover every allowed and disallowed transition so that a future
 * edit to canTransitionTo() cannot silently break the model.
 */
#[CoversClass(NotificationStatus::class)]
final class NotificationStatusTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Terminal state detection
    // ---------------------------------------------------------------------------

    public function test_dispatched_is_terminal(): void
    {
        self::assertTrue(NotificationStatus::Dispatched->isTerminal());
    }

    public function test_failed_is_terminal(): void
    {
        self::assertTrue(NotificationStatus::Failed->isTerminal());
    }

    public function test_queued_is_not_terminal(): void
    {
        self::assertFalse(NotificationStatus::Queued->isTerminal());
    }

    public function test_processing_is_not_terminal(): void
    {
        self::assertFalse(NotificationStatus::Processing->isTerminal());
    }

    public function test_dead_lettered_is_not_terminal(): void
    {
        // dead_lettered is not terminal — the aggregate persists and can be
        // queried; replay creates a new aggregate from it (domain.md §4).
        self::assertFalse(NotificationStatus::DeadLettered->isTerminal());
    }

    // ---------------------------------------------------------------------------
    // Allowed transitions
    // ---------------------------------------------------------------------------

    public function test_queued_can_transition_to_processing(): void
    {
        self::assertTrue(
            NotificationStatus::Queued->canTransitionTo(NotificationStatus::Processing)
        );
    }

    public function test_processing_can_transition_to_dispatched(): void
    {
        self::assertTrue(
            NotificationStatus::Processing->canTransitionTo(NotificationStatus::Dispatched)
        );
    }

    public function test_processing_can_transition_to_queued_for_retry(): void
    {
        self::assertTrue(
            NotificationStatus::Processing->canTransitionTo(NotificationStatus::Queued)
        );
    }

    public function test_processing_can_transition_to_failed(): void
    {
        self::assertTrue(
            NotificationStatus::Processing->canTransitionTo(NotificationStatus::Failed)
        );
    }

    // ---------------------------------------------------------------------------
    // Disallowed transitions (invariant 5.1.6 + state machine)
    // ---------------------------------------------------------------------------

    #[DataProvider('disallowedTransitionProvider')]
    public function test_disallowed_transitions_return_false(
        NotificationStatus $from,
        NotificationStatus $to,
    ): void {
        self::assertFalse($from->canTransitionTo($to));
    }

    /**
     * @return array<string, array{NotificationStatus, NotificationStatus}>
     */
    public static function disallowedTransitionProvider(): array
    {
        return [
            // Queued cannot skip to terminal or dead-letter directly
            'queued → dispatched'         => [NotificationStatus::Queued, NotificationStatus::Dispatched],
            'queued → dead_lettered'      => [NotificationStatus::Queued, NotificationStatus::DeadLettered],
            'queued → failed'             => [NotificationStatus::Queued, NotificationStatus::Failed],
            'queued → queued'             => [NotificationStatus::Queued, NotificationStatus::Queued],

            // Terminal: dispatched cannot go anywhere
            'dispatched → queued'         => [NotificationStatus::Dispatched, NotificationStatus::Queued],
            'dispatched → processing'     => [NotificationStatus::Dispatched, NotificationStatus::Processing],
            'dispatched → dead_lettered'  => [NotificationStatus::Dispatched, NotificationStatus::DeadLettered],
            'dispatched → failed'         => [NotificationStatus::Dispatched, NotificationStatus::Failed],
            'dispatched → dispatched'     => [NotificationStatus::Dispatched, NotificationStatus::Dispatched],

            // Terminal: failed cannot go anywhere
            'failed → queued'             => [NotificationStatus::Failed, NotificationStatus::Queued],
            'failed → processing'         => [NotificationStatus::Failed, NotificationStatus::Processing],
            'failed → dispatched'         => [NotificationStatus::Failed, NotificationStatus::Dispatched],
            'failed → dead_lettered'      => [NotificationStatus::Failed, NotificationStatus::DeadLettered],
            'failed → failed'             => [NotificationStatus::Failed, NotificationStatus::Failed],

            // dead_lettered cannot transition back into the lifecycle
            'dead_lettered → queued'      => [NotificationStatus::DeadLettered, NotificationStatus::Queued],
            'dead_lettered → processing'  => [NotificationStatus::DeadLettered, NotificationStatus::Processing],
            'dead_lettered → dispatched'  => [NotificationStatus::DeadLettered, NotificationStatus::Dispatched],
            'dead_lettered → failed'      => [NotificationStatus::DeadLettered, NotificationStatus::Failed],

            // processing cannot self-transition
            'processing → processing'     => [NotificationStatus::Processing, NotificationStatus::Processing],
        ];
    }
}