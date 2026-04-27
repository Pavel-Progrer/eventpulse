<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notifications` table.
 *
 * Persistence row for the Notification aggregate root. The aggregate itself
 * is framework-independent (`EventPulse\Domain\Notification\Aggregate\Notification`);
 * this table stores its serialised state, and `EloquentNotificationRepository`
 * mediates between them.
 *
 * Day 3 scope:
 *  - Root row only (no `attempts` or `dead_letter_marks` tables yet).
 *  - All status values from the `NotificationStatus` enum are accepted via a
 *    CHECK constraint, even though Day 3 only writes `queued`. The table is
 *    designed for the full lifecycle so Day 4–7 do not need ALTER TABLE.
 *  - `replay_of_id` and `dispatched_at` are nullable for the same reason.
 *
 * Indexes:
 *  - `(api_key_id, idempotency_key)` UNIQUE — enforces idempotency at the
 *    DB level. Day 4's application-layer dedup is the user-facing contract;
 *    this index is the last line of defence against a race between two
 *    concurrent submissions of the same key.
 *  - `(api_key_id, created_at DESC)` — supports the list endpoint (`GET
 *    /notifications`) without table scans on a per-tenant basis.
 *  - `correlation_id` — supports the `correlation_id` filter on the list
 *    endpoint and the cross-event log correlation in the dispatch flow.
 *
 * Storage choices:
 *  - `payload` is JSONB. The shape varies per channel and is not queried
 *    by structure — Postgres' JSONB compresses better than JSON and is
 *    indexable if needed later (currently no expression index).
 *  - `recipient` is stored as the canonical string form of the value object
 *    (lowercased email, E.164 phone, destination UUID). `channel` is needed
 *    to interpret it, which is why the two columns are sibling.
 *  - All timestamps are `TIMESTAMPTZ` (UTC). The column type names align
 *    with `SystemClock::now()` (`UTC`); see ADR-0003 §6.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            // ── Identity ────────────────────────────────────────────────────
            $table->uuid('id')->primary();
            $table->uuid('api_key_id');

            // ── Routing ─────────────────────────────────────────────────────
            $table->string('channel', 16);
            $table->string('recipient', 320); // RFC 5321 max email length is 320.
            $table->string('priority', 16)->default('normal');

            // ── Content ─────────────────────────────────────────────────────
            $table->jsonb('payload');

            // ── Lifecycle ───────────────────────────────────────────────────
            $table->string('status', 24)->default('queued');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('scheduled_for')->nullable();

            // ── Tracing & idempotency ───────────────────────────────────────
            $table->string('correlation_id', 128);
            $table->string('idempotency_key', 255);
            $table->uuid('replay_of_id')->nullable();

            // ── Timestamps ──────────────────────────────────────────────────
            $table->timestampsTz();

            // ── Indexes ─────────────────────────────────────────────────────
            $table->unique(['api_key_id', 'idempotency_key'], 'notifications_idem_unique');
            $table->index(['api_key_id', 'created_at']);
            $table->index('correlation_id');
            $table->index('status');

            $table->foreign('api_key_id')->references('id')->on('api_keys');
        });

        // Channel and status are constrained at the DB level too. The domain
        // enforces them, but a defence-in-depth check stops a bug in any
        // future direct-SQL path from corrupting the table.
        Schema::getConnection()->statement(
            "ALTER TABLE notifications
                ADD CONSTRAINT notifications_channel_check
                CHECK (channel IN ('email', 'webhook', 'sms'))"
        );
        Schema::getConnection()->statement(
            "ALTER TABLE notifications
                ADD CONSTRAINT notifications_status_check
                CHECK (status IN ('queued', 'processing', 'dispatched', 'failed', 'dead_lettered'))"
        );
        Schema::getConnection()->statement(
            "ALTER TABLE notifications
                ADD CONSTRAINT notifications_priority_check
                CHECK (priority IN ('low', 'normal', 'high'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
