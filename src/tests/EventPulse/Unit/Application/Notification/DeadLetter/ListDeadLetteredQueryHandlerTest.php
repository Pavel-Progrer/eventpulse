<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\DeadLetter;

use DateTimeImmutable;
use EventPulse\Application\Notification\DeadLetter\Query\DlqEntry;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQuery;
use EventPulse\Application\Notification\DeadLetter\Query\ListDeadLetteredQueryHandler;
use EventPulse\Domain\Notification\Enum\Channel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use EventPulse\Tests\Unit\Application\Support\InMemoryDeadLetteredNotificationsRepository;

/**
 * Coverage for the list-DLQ use case at the application boundary.
 *
 * Exercises the orchestration the handler is responsible for plus the
 * filter and pagination semantics it delegates to the repository port.
 * The in-memory repo enforces the same contract as the Eloquent one
 * (sort order, cursor format, limit-plus-one), so tests written here
 * stay valid against either implementation.
 *
 * What is *not* tested here:
 *   - Authorisation (the handler does not perform it; the middleware
 *     does).
 *   - Cross-tenant data leakage (covered in feature tests via the
 *     middleware + DB stack).
 */
#[CoversClass(ListDeadLetteredQueryHandler::class)]
final class ListDeadLetteredQueryHandlerTest extends TestCase
{
    private InMemoryDeadLetteredNotificationsRepository $repository;
    private ListDeadLetteredQueryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryDeadLetteredNotificationsRepository();
        $this->handler    = new ListDeadLetteredQueryHandler($this->repository);
    }

    #[Test]
    public function it_returns_an_empty_page_when_no_entries_match(): void
    {
        $page = ($this->handler)(new ListDeadLetteredQuery(apiKeyId: 'key-a'));

        self::assertSame([], $page->entries);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function it_isolates_tenants(): void
    {
        $this->repository->add('key-a', $this->entry(id: '11111111-aaaa-aaaa-aaaa-000000000001', at: '10:00:00'));
        $this->repository->add('key-b', $this->entry(id: '22222222-bbbb-bbbb-bbbb-000000000001', at: '10:00:00'));
        $this->repository->add('key-a', $this->entry(id: '11111111-aaaa-aaaa-aaaa-000000000002', at: '10:01:00'));

        $page = ($this->handler)(new ListDeadLetteredQuery(apiKeyId: 'key-a'));

        self::assertCount(2, $page->entries);
        foreach ($page->entries as $entry) {
            self::assertStringStartsWith('11111111-aaaa', $entry->id);
        }
    }

    #[Test]
    public function it_sorts_most_recent_first_with_id_tiebreaker(): void
    {
        // Two entries with identical timestamps — id is the tiebreaker.
        $this->repository->add('key-a', $this->entry(id: '00000000-0000-0000-0000-000000000001', at: '10:00:00'));
        $this->repository->add('key-a', $this->entry(id: '00000000-0000-0000-0000-000000000002', at: '10:00:00'));
        $this->repository->add('key-a', $this->entry(id: '00000000-0000-0000-0000-000000000003', at: '11:00:00'));

        $page = ($this->handler)(new ListDeadLetteredQuery(apiKeyId: 'key-a'));

        self::assertCount(3, $page->entries);
        self::assertSame('00000000-0000-0000-0000-000000000003', $page->entries[0]->id, 'newest first');
        self::assertSame('00000000-0000-0000-0000-000000000002', $page->entries[1]->id, 'tie broken by id desc');
        self::assertSame('00000000-0000-0000-0000-000000000001', $page->entries[2]->id, 'tie broken by id desc');
    }

    #[Test]
    public function it_filters_by_reason(): void
    {
        $this->repository->add('key-a', $this->entry(id: 'a', at: '10:00:00', reason: 'max_retries_exceeded'));
        $this->repository->add('key-a', $this->entry(id: 'b', at: '10:01:00', reason: 'unrecoverable_error'));

        $page = ($this->handler)(new ListDeadLetteredQuery(
            apiKeyId: 'key-a',
            reason:   'unrecoverable_error',
        ));

        self::assertCount(1, $page->entries);
        self::assertSame('b', $page->entries[0]->id);
    }

    #[Test]
    public function it_filters_by_channel(): void
    {
        $this->repository->add('key-a', $this->entry(id: 'a', at: '10:00:00', channel: Channel::Email));
        $this->repository->add('key-a', $this->entry(id: 'b', at: '10:01:00', channel: Channel::Sms));
        $this->repository->add('key-a', $this->entry(id: 'c', at: '10:02:00', channel: Channel::Webhook));

        $page = ($this->handler)(new ListDeadLetteredQuery(
            apiKeyId: 'key-a',
            channel:  Channel::Sms,
        ));

        self::assertCount(1, $page->entries);
        self::assertSame('b', $page->entries[0]->id);
    }

    #[Test]
    public function it_filters_by_created_after_and_created_before(): void
    {
        $this->repository->add('key-a', $this->entry(id: 'a', at: '09:00:00'));
        $this->repository->add('key-a', $this->entry(id: 'b', at: '10:30:00'));
        $this->repository->add('key-a', $this->entry(id: 'c', at: '12:00:00'));

        $page = ($this->handler)(new ListDeadLetteredQuery(
            apiKeyId:      'key-a',
            createdAfter:  new DateTimeImmutable('2026-04-27T10:00:00Z'),
            createdBefore: new DateTimeImmutable('2026-04-27T11:00:00Z'),
        ));

        self::assertCount(1, $page->entries);
        self::assertSame('b', $page->entries[0]->id, 'inclusive on after, exclusive on before');
    }

    #[Test]
    public function it_paginates_with_a_cursor(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->add('key-a', $this->entry(
                id: sprintf('00000000-0000-0000-0000-%012d', $i),
                at: sprintf('10:%02d:00', $i),
            ));
        }

        // First page, limit 2 — gets the two newest, returns a cursor.
        $first = ($this->handler)(new ListDeadLetteredQuery(apiKeyId: 'key-a', limit: 2));

        self::assertCount(2, $first->entries);
        self::assertNotNull($first->nextCursor);
        $firstIds = array_map(static fn(DlqEntry $e): string => $e->id, $first->entries);

        // Second page using the cursor — gets the next two, returns a cursor.
        $second = ($this->handler)(new ListDeadLetteredQuery(
            apiKeyId: 'key-a',
            limit:    2,
            cursor:   $first->nextCursor,
        ));

        self::assertCount(2, $second->entries);
        self::assertNotNull($second->nextCursor);
        $secondIds = array_map(static fn(DlqEntry $e): string => $e->id, $second->entries);

        // Third page using the cursor — gets the last one, no more cursor.
        $third = ($this->handler)(new ListDeadLetteredQuery(
            apiKeyId: 'key-a',
            limit:    2,
            cursor:   $second->nextCursor,
        ));

        self::assertCount(1, $third->entries);
        self::assertNull($third->nextCursor, 'last page has no next cursor');
        $thirdIds = array_map(static fn(DlqEntry $e): string => $e->id, $third->entries);

        // No id appears across any two pages.
        self::assertCount(
            5,
            array_unique(array_merge($firstIds, $secondIds, $thirdIds)),
            'paginated ids must not repeat across pages',
        );
    }

    #[Test]
    public function single_page_does_not_emit_a_cursor(): void
    {
        $this->repository->add('key-a', $this->entry(id: 'a', at: '10:00:00'));
        $this->repository->add('key-a', $this->entry(id: 'b', at: '11:00:00'));

        $page = ($this->handler)(new ListDeadLetteredQuery(apiKeyId: 'key-a', limit: 25));

        self::assertCount(2, $page->entries);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function limit_below_one_is_rejected_at_the_query_dto_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListDeadLetteredQuery(apiKeyId: 'key-a', limit: 0);
    }

    #[Test]
    public function limit_above_one_hundred_is_rejected_at_the_query_dto_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListDeadLetteredQuery(apiKeyId: 'key-a', limit: 101);
    }

    private function entry(
        string $id,
        string $at,
        ?string $reason = null,
        ?Channel $channel = null,
    ): DlqEntry {
        return new DlqEntry(
            id:                   $id,
            notificationId:       $id,
            reason:               $reason ?? 'max_retries_exceeded',
            channel:              $channel ?? Channel::Email,
            deadLetteredAt:       new DateTimeImmutable("2026-04-27T{$at}Z"),
            finalAttemptAt:       new DateTimeImmutable("2026-04-27T{$at}Z"),
            replayNotificationId: null,
            replayedAt:           null,
        );
    }
}
