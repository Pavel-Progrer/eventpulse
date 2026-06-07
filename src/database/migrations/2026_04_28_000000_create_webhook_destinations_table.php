<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `webhook_destinations` table.
 *
 * Persistence row for the `WebhookDestination` aggregate (domain.md §3.2).
 *
 * Security design for `secret`:
 *  The column stores the destination's signing secret, AES-256-CBC-encrypted
 *  via Laravel's built-in `Encrypter` (app key). The plaintext secret is
 *  never logged, never returned in list or read responses, and is only
 *  decrypted by `EloquentWebhookEndpointResolver` on the hot dispatch path.
 *  See ADR-0005 §Decision for the full signing scheme and ADR-0007 for the
 *  broader secrets management policy.
 *
 * Tenant isolation:
 *  `api_key_id` is present on every query. All reads and writes must include
 *  the calling key's id so that destinations owned by one key are invisible
 *  to another. The `(api_key_id, status)` index makes the "find all active
 *  destinations for a key" query fast.
 *
 * Indexes:
 *  - `api_key_id` + `created_at DESC` — supports paginated listing per tenant.
 *  - `(api_key_id, status)` — supports the "is this destination active for
 *    this key?" lookup at submission time without scanning all destinations.
 *
 * Status constraint:
 *  A CHECK constraint mirrors the `WebhookDestinationStatus` enum values.
 *  Defence-in-depth: the domain enforces the constraint, but a future
 *  raw-SQL migration path or admin tool should not be able to corrupt the
 *  column silently.
 *
 * `name` is nullable (column default null) — the OpenAPI spec declares it
 * optional and many callers won't provide it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_destinations', function (Blueprint $table): void {
            // ── Identity ────────────────────────────────────────────────────
            $table->uuid('id')->primary();
            $table->uuid('api_key_id');

            // ── Target ──────────────────────────────────────────────────────
            $table->string('url', 2048);
            $table->string('name', 128)->nullable();

            // ── Security ────────────────────────────────────────────────────
            // Encrypted via Laravel Encrypter (app key, AES-256-CBC).
            // The ciphertext is longer than 256 chars; TEXT accommodates any
            // reasonable key size.
            $table->text('secret_encrypted');

            // ── Lifecycle ───────────────────────────────────────────────────
            $table->string('status', 16)->default('active');

            // ── Timestamps ──────────────────────────────────────────────────
            $table->timestampsTz();

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index(['api_key_id', 'created_at']);
            $table->index(['api_key_id', 'status'], 'webhook_destinations_key_status_idx');

            $table->foreign('api_key_id')->references('id')->on('api_keys');
        });

        // Status constraint — mirrors WebhookDestinationStatus enum.
        Schema::getConnection()->statement(
            "ALTER TABLE webhook_destinations
                ADD CONSTRAINT webhook_destinations_status_check
                CHECK (status IN ('active', 'disabled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_destinations');
    }
};
