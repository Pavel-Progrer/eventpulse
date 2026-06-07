<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Entity;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Entity\DeadLetterMark;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Domain-level coverage for the DeadLetterMark entity.
 *
 * These tests focus on behaviour that the entity owns:
 *   - construction sets reason and dead-lettered-at, and starts in the
 *     "not yet replayed" state,
 *   - `recordReplay` populates both id and timestamp,
 *   - a second `recordReplay` raises a logic error (replay is once),
 *   - `reconstituteReplay` skips the guard so the repository can rehydrate
 *     a previously-replayed mark without tripping the once-only rule.
 *
 * The tests deliberately do not go through the Notification aggregate;
 * the aggregate has its own coverage. Here we exercise the entity in
 * isolation so a domain-level regression surfaces at the smallest scope
 * possible.
 */
#[CoversClass(DeadLetterMark::class)]
final class DeadLetterMarkTest extends TestCase
{
    #[Test]
    public function it_starts_in_the_not_yet_replayed_state(): void
    {
        $mark = new DeadLetterMark(
            reason: 'max_retries_exceeded',
            deadLetteredAt: new DateTimeImmutable('2026-04-27T10:00:00Z'),
        );

        self::assertSame('max_retries_exceeded', $mark->reason());
        self::assertEquals(new DateTimeImmutable('2026-04-27T10:00:00Z'), $mark->deadLetteredAt());
        self::assertFalse($mark->wasReplayed());
        self::assertNull($mark->replayNotificationId());
        self::assertNull($mark->replayedAt());
    }

    #[Test]
    public function record_replay_populates_id_and_timestamp_together(): void
    {
        $mark = $this->newMark();
        $replayId = NotificationId::generate();
        $replayedAt = new DateTimeImmutable('2026-04-27T11:30:00Z');

        $mark->recordReplay($replayId, $replayedAt);

        self::assertTrue($mark->wasReplayed());
        self::assertTrue($mark->replayNotificationId()?->equals($replayId));
        self::assertEquals($replayedAt, $mark->replayedAt());
    }

    #[Test]
    public function record_replay_is_once_only(): void
    {
        $mark = $this->newMark();
        $mark->recordReplay(NotificationId::generate(), new DateTimeImmutable('2026-04-27T11:00:00Z'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already has a replay notification recorded');

        $mark->recordReplay(NotificationId::generate(), new DateTimeImmutable('2026-04-27T12:00:00Z'));
    }

    #[Test]
    public function reconstitute_replay_skips_the_once_only_guard(): void
    {
        // Hydration scenario: the persistence layer is feeding the entity
        // historical state. We must not fail on the "second" call when the
        // initial state itself was already-replayed.
        $mark = $this->newMark();

        $first = NotificationId::generate();
        $firstAt = new DateTimeImmutable('2026-04-27T11:00:00Z');

        $mark->reconstituteReplay($first, $firstAt);

        // Re-hydrating the same row twice (e.g. a refresh inside a single
        // request) must not blow up. This is intentionally permissive: the
        // domain's once-only invariant is enforced by `recordReplay`, not
        // by `reconstituteReplay`.
        $second = NotificationId::generate();
        $secondAt = new DateTimeImmutable('2026-04-27T12:00:00Z');

        $mark->reconstituteReplay($second, $secondAt);

        self::assertTrue($mark->replayNotificationId()?->equals($second));
        self::assertEquals($secondAt, $mark->replayedAt());
    }

    private function newMark(): DeadLetterMark
    {
        return new DeadLetterMark(
            reason: 'max_retries_exceeded',
            deadLetteredAt: new DateTimeImmutable('2026-04-27T10:00:00Z'),
        );
    }
}
