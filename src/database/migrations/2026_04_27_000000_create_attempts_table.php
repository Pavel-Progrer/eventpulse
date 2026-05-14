<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `attempts` table.
 *
 * Persistence row for the `Attempt` entity, owned by the Notification
 * aggregate. Day 4's `EloquentNotificationRepository` left this as a TODO
 * (`attempts: []` in `hydrate()`); Day 8 closes the gap because the DLQ
 * admin endpoint cannot inspect a notification's history without it.
 *
 * Schema notes:
 *  - `notification_id` + `number` is the natural key (one attempt per
 *    number per notification, invariant 5.1.2). The unique index makes
 *    a duplicate attempt write fail at the DB level — defence in depth
 *    against any future code path that bypasses the aggregate.
 *  - `succeeded` is a nullable boolean: `null` = in-progress,
 *    `true`/`false` = completed. Three-valued logic is intentional —
 *    encoding "in progress" as `succeeded = false` would conflate the
 *    "active" state with the "failed" state and break the
 *    "exactly one attempt in progress" invariant's queries.
 *  - `classification` and `reason` are populated only on failed
 *    attempts. CHECK constraint enforces consistency: a successful
 *    attempt has neither, a failed attempt has both, an in-progress
 *    attempt has neither.
 *  - All timestamps `TIMESTAMPTZ`. Domain operates in UTC; the DB
 *    column type is timezone-aware so a developer reading raw rows
 *    sees the explicit zone.
 *  - FK on `notification_id` cascades on delete: there is no scenario
 *    where attempts outlive the notification they belong to.
 *
 * Schema is forward-compatible with future read-model needs: a
 * `(notification_id, number)` ordered scan answers "list attempts for
 * a notification" without a sort, and a `created_at` index would
 * support time-windowed queries when the metrics layer arrives.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->unsignedSmallInteger('number');

            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();

            // Three-valued: NULL = in progress, TRUE/FALSE = completed.
            $table->boolean('succeeded')->nullable();

            // Populated only on failed attempts.
            $table->string('classification', 16)->nullable();
            $table->text('reason')->nullable();

            $table->timestampsTz();

            $table->unique(['notification_id', 'number'], 'attempts_notification_number_unique');
            $table->index('notification_id');

            $table->foreign('notification_id')
                ->references('id')->on('notifications')
                ->cascadeOnDelete();
        });

        // Classification is constrained at the DB level. The domain
        // already enforces it, but a defence-in-depth check stops a bug
        // in any future direct-SQL path from corrupting the table.
        Schema::getConnection()->statement(
            "ALTER TABLE attempts
                ADD CONSTRAINT attempts_classification_check
                CHECK (classification IS NULL
                    OR classification IN ('transient', 'permanent', 'unrecoverable'))"
        );

        // Internal consistency: a successful attempt has no
        // classification and no reason; a failed attempt has both;
        // an in-progress attempt (succeeded IS NULL) has neither.
        Schema::getConnection()->statement(
            "ALTER TABLE attempts
                ADD CONSTRAINT attempts_outcome_consistency_check
                CHECK (
                    (succeeded IS NULL  AND classification IS NULL AND reason IS NULL AND completed_at IS NULL)
                    OR (succeeded = TRUE  AND classification IS NULL AND reason IS NULL AND completed_at IS NOT NULL)
                    OR (succeeded = FALSE AND classification IS NOT NULL AND reason IS NOT NULL AND completed_at IS NOT NULL)
                )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
