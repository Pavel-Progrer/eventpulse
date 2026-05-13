<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `discarded_at` to `dead_letter_marks`.
 *
 * When an operator discards a DLQ entry via `DELETE /api/v1/dlq/{id}`,
 * this column is stamped with the current UTC timestamp. The DLQ list query
 * excludes rows where `discarded_at IS NOT NULL` by default, effectively
 * removing the entry from the operator view while preserving full history.
 *
 * The column is nullable: null means "not discarded," a timestamp means
 * "acknowledged and hidden." There is no separate status column because the
 * only two states are "visible" and "discarded" — a boolean would also work,
 * but a timestamp is strictly more informative (it answers "when was this
 * discarded?" for audit purposes without adding a second column).
 *
 * No CHECK constraint: replay and discard are independent operations. A
 * replayed notification may also be discarded later if the operator decides
 * the replay was unnecessary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dead_letter_marks', static function (Blueprint $table): void {
            $table->timestampTz('discarded_at')->nullable()->after('replayed_at');
        });
    }

    public function down(): void
    {
        Schema::table('dead_letter_marks', static function (Blueprint $table): void {
            $table->dropColumn('discarded_at');
        });
    }
};
