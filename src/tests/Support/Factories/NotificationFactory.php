<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use App\Models\ApiKey;
use EventPulse\Domain\Notification\Repository\NotificationRepository;

/**
 * Test-time fixture builder for `Notification` aggregates.
 *
 * Built to remove the seam that bit Day 8 twice: feature tests were
 * inserting rows directly into `notifications`, `attempts`, and
 * `dead_letter_marks` with shapes the controller would have produced
 * from a real submission — except they kept getting the shapes
 * subtly wrong (wire-name `body_text` vs domain-name `text`, bare
 * string ids vs UUIDs). The fix is to stop bypassing the domain and
 * persistence layers in tests; this factory drives the real
 * `Notification::request()` + `NotificationRepository::save()` path.
 *
 * **Scope.** Today the factory only knows how to build a
 * dead-lettered notification — that's what every Day-8 feature test
 * needs. When replay or discard endpoints arrive, a `replayed()`
 * terminal step or a sibling builder for queued/dispatched states
 * slots in alongside.
 *
 * **What it is not.** Not a replacement for `NotificationMother` (the
 * domain-layer in-memory mother that has no Laravel dependencies).
 * Both have a place — the factory persists through Eloquent so it
 * exercises the same write path as production; the mother stays in
 * memory and runs without a Laravel container. Choose the one whose
 * layer matches the test's layer.
 *
 * **Tenant.** The factory takes an `ApiKey` model (live row from the
 * test database) rather than a string id. Feature tests already
 * have one in scope from `setUp`, and the type signature makes the
 * relationship explicit at the call site.
 *
 * Usage:
 *
 * ```php
 * $notification = $this->factory()
 *     ->dlqEntry($this->reader)
 *     ->withChannel(Channel::Webhook)
 *     ->withReason('unrecoverable_error')
 *     ->deadLetteredAt('2026-04-27T10:00:00Z')
 *     ->save();
 *
 * $id = $notification->id()->toString();
 * ```
 *
 * The factory itself is small (one entrypoint, one builder); the
 * builder is where the configuration surface lives.
 */
final class NotificationFactory
{
    public function __construct(
        private readonly NotificationRepository $repository,
    ) {}

    /**
     * Begin building a notification destined for the dead-letter queue.
     *
     * Default shape if no `with*` calls follow:
     *  - email channel,
     *  - normal priority,
     *  - one transient failure exhausting the retry budget (max=1),
     *    so the notification dead-letters with reason
     *    `max_retries_exceeded`,
     *  - dead-lettered at a fixed test-time instant
     *    (2026-04-27T10:00:00Z) — override via `deadLetteredAt()`.
     */
    public function dlqEntry(ApiKey $apiKey): DlqEntryBuilder
    {
        return new DlqEntryBuilder($this->repository, (string) $apiKey->id);
    }
}
