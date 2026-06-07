<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `dead_letter_marks` table.
 *
 * Persistence row for the `DeadLetterMark` entity, owned by the Notification
 * aggregate. At most one row per notification — invariant from the domain
 * (a notification is dead-lettered exactly once; replay produces a new
 * aggregate, not a transition of the original).
 *
 * The DLQ admin endpoints (`GET /api/v1/dlq`, `GET /api/v1/dlq/{id}`)
 * read from this table joined to `notifications`. The list endpoint's
 * filters (reason, channel, date range) are served by the indexes below.
 *
 * Why a separate table instead of nullable columns on `notifications`:
 *  - The DL mark has its own lifecycle (created at dead-letter time,
 *    later updated when replayed) and its own observability surface.
 *    A separate row keeps that lifecycle visible and indexable.
 *  - A future "discard" feature (mark an entry as acknowledged without
 *    replaying) is a new column on this table, not on `notifications`.
 *  - `dead_lettered_at` indexed independently of `notifications.created_at`
 *    means "DLQ entries from the last hour" is a one-row-per-result scan.
 *
 * `replay_notification_id` references back into `notifications.id` —
 * an FK, but only enforced one direction (the replay row's `replay_of_id`
 * → original notification's id is on the notifications table per Day 3's
 * schema). We do not enforce a reverse FK here because the replay row may
 * not yet exist when the mark is first inserted; the value is nullable and
 * populated when an operator triggers replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letter_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');

            $table->text('reason');
            $table->timestampTz('dead_lettered_at');

            // Set when an operator replays this entry. The replay creates
            // a new Notification aggregate (with `replay_of_id` pointing
            // here) and updates this column to that new id.
            $table->uuid('replay_notification_id')->nullable();
            $table->timestampTz('replayed_at')->nullable();

            $table->timestampsTz();

            // At most one mark per notification (domain invariant).
            $table->unique('notification_id', 'dead_letter_marks_notification_unique');

            // List-endpoint filter: reason + dead_lettered_at range.
            $table->index(['dead_lettered_at']);
            $table->index('replay_notification_id');

            $table->foreign('notification_id')
                ->references('id')->on('notifications')
                ->cascadeOnDelete();
        });

        // Replay metadata consistency: either both populated (replayed)
        // or both null (not replayed). A half-populated row would mean
        // operator-tooling drift and is rejected at the DB level.
        Schema::getConnection()->statement(
            'ALTER TABLE dead_letter_marks
                ADD CONSTRAINT dead_letter_marks_replay_consistency_check
                CHECK (
                    (replay_notification_id IS NULL AND replayed_at IS NULL)
                    OR
                    (replay_notification_id IS NOT NULL AND replayed_at IS NOT NULL)
                )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letter_marks');
    }
};
