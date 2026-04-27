<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `api_keys` table.
 *
 * Persistence row for the ApiKey aggregate (domain.md §3.3). The aggregate
 * itself is not implemented in Day 3 — only the bare table is created so
 * that auth middleware can resolve a Bearer token to an api_key_id and
 * verify the active scope.
 *
 * Future iterations (Day 9 — webhook signing) extend this table with the
 * Argon2id hash of the key secret. The `secret_hash` column is created
 * here as nullable so the migration does not need to be revised when the
 * full HMAC verification path lands; it is simply populated then.
 *
 * Columns:
 *  - id            UUID primary key. Aggregate identity.
 *  - identifier    Public key id ("ep_live_<id>"); used in the Authorization
 *                  header. Indexed because it is the lookup key for every
 *                  authenticated request.
 *  - secret_hash   Argon2id hash of the secret. Nullable for Day 3 (no HMAC
 *                  yet); becomes NOT NULL in the Day 9 follow-up migration.
 *  - scopes        JSONB array of scope strings — kept as JSON rather than a
 *                  many-to-many relation because scopes are a fixed enum
 *                  (notifications:write, notifications:read, dlq:read,
 *                  dlq:replay, search:read, admin) and a relation table
 *                  would be over-engineered for an enumerated set.
 *  - status        active | revoked | rotated.
 *  - revoked_at    Timestamp; null when status is not `revoked`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identifier', 64)->unique();
            $table->string('secret_hash', 255)->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->string('status', 16)->default('active');
            $table->string('label', 128)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
