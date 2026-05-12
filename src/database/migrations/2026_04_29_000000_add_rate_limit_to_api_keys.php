<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `rate_limit_per_minute` to the `api_keys` table.
 *
 * Specification §5.3: "Per-key overrides stored in api_keys.rate_limit_per_minute."
 *
 * The column is nullable (no override) rather than defaulting to the system
 * default (100/min write, 600/min read). Using null to mean "no override"
 * instead of storing the default values avoids the ambiguity of a stored
 * value that happens to equal the current default — if the default changes,
 * a stored 100 would be treated as a deliberate override rather than the
 * old default. Null is unambiguous.
 *
 * The column applies to write requests only (POST/PUT/PATCH/DELETE). There is
 * no per-key read override in the current spec; adding one later is an
 * additive column change with no data migration required.
 *
 * Minimum value enforcement (> 0) is done at the application layer
 * (`ThrottleApiRequests`) rather than a DB check constraint to keep the
 * migration simple and portable across PostgreSQL versions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->unsignedInteger('rate_limit_per_minute')
                ->nullable()
                ->after('label')
                ->comment('Per-key write rate limit override (requests/min). Null → system default (100/min).');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('rate_limit_per_minute');
        });
    }
};
