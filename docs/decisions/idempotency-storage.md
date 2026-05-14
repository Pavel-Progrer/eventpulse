# Decision note: Idempotency dedup is DB-based, not Redis-cached

- **Date:** 2026-04-23
- **Scope:** `SubmitNotificationHandler` only — does not generalise.
- **Status:** Active
- **Related ADRs:** [ADR-0003](../adr/0003-http-boundary-and-application-services.md) (HTTP boundary, application services, and command DTOs)

This is a *decision note*, not an ADR. The choice is local — it concerns one handler — and is reversible without restructuring anything else. ADRs in this project are reserved for cross-cutting architectural commitments (scope, layering, retry policy). Recording this here keeps the rationale findable without bloating the ADR sequence.

---

## Decision

The idempotency dedup window for `POST /api/v1/notifications` is implemented by querying the `notifications` table directly through `NotificationRepository::findByIdempotencyKey($apiKeyId, $idempotencyKey)`. The lookup is backed by the `notifications_idem_unique` composite-unique index on `(api_key_id, idempotency_key)`.

No Redis layer caches the prior response. There is no separate `idempotency_keys` table.

---

## Context

The OpenAPI contract describes a 24-hour idempotency window (`POST /notifications` §responses.202.headers.X-Idempotent-Replay), and the system specification (§5.1, "Acceptance and idempotency") suggests caching the prior response in Redis under the idempotency key for that window. Both requirements are satisfied by the implemented design — the difference is mechanism.

Three implementation shapes were considered:

1. **Redis-cached response.** Persist the rendered HTTP response under `idem:{api_key_id}:{key}` with a 24h TTL. On replay, return the cached body verbatim.
2. **Dedicated `idempotency_keys` table.** A separate row per submission with a foreign key to `notifications`. The handler checks this table first; the notifications table is reached only on cache-miss.
3. **DB-direct lookup on `notifications`.** No separate table, no cache. The unique index on `(api_key_id, idempotency_key)` *is* the dedup mechanism; the handler queries it before generating an id.

This note records why option 3 was chosen.

---

## Reasoning

**Single source of truth.** The notification row already exists and is the canonical record of "this submission was accepted." A Redis cache would be a derived view — and any derived view introduces invalidation rules. If the response shape changes (e.g., a new field is added to `NotificationAcceptedResource`), every cached entry from before the deploy is stale. Either we accept stale responses for up to 24h after each deploy, or we add explicit cache-busting on deploy. Both are operational liabilities for a feature whose user-visible behaviour is "the same response twice" — which the canonical row already provides.

**No coherence problem.** Consider the failure mode of option 1: the first POST persists the notification, writes to Redis, returns 202. A second POST sees the Redis entry, returns the cached body. Now imagine Redis evicts the key (memory pressure, restart, manual flush). The third POST does *not* see the cache — and tries to insert into `notifications` with the same `(api_key_id, idempotency_key)`. The `notifications_idem_unique` index throws a duplicate-key violation. The handler must catch it, fall back to a DB lookup, and synthesise a response. The "fast path" is always there *and* the DB path must always be there. Option 3 has only one path — the slow path of option 1, run unconditionally.

**Latency is well within budget.** The OpenAPI's stated p95 latency budget for this endpoint is 200 ms. A single indexed point-lookup on `notifications_idem_unique` returns in single-digit milliseconds against PostgreSQL 17 — measured locally at <2 ms for a 100k-row table. Even if scaled by 10× we are nowhere near the budget. The argument "Redis is faster than Postgres" is true in the abstract and irrelevant in concrete: the handler does several DB operations on the happy path anyway (insert, then via the queue worker an attempt write); a dedup lookup is a fraction of that cost.

**Smaller blast radius.** Redis adds a hard dependency for a feature that doesn't otherwise need it. A Redis outage would degrade the endpoint to "every request creates a new notification, idempotency is silently broken" — a correctness regression. Without the cache, a Redis outage affects only the queue (which has its own retry semantics) and not the at-most-once submission contract.

---

## Consequences

**Positive**

- One mechanism, one path, one type of failure to reason about.
- No cache invalidation on response-shape changes.
- Idempotency contract survives a full Redis outage.
- The unique index is itself a database-level safety net — even if the handler had a bug that bypassed `findByIdempotencyKey()`, a duplicate insert would fail rather than producing a duplicate notification. Defense in depth.

**Negative**

- Every replay does a database read. For workloads where the same key is replayed many times in quick succession, this is wasted work compared to a cache hit. (Acceptable for now — see "Triggers to revisit" below.)
- The `notifications` table participates in the dedup hot path. If it ever becomes write-heavy enough that read latency degrades, the dedup query degrades with it.
- The dedup window is whatever the row's retention policy is, not exactly 24 hours. As long as notifications are kept for ≥24h (currently they are kept indefinitely for Phase 1), this is fine; if a retention sweep job lands later, it must respect the contract.

---

## Triggers to revisit

Add a Redis cache layer (i.e., move toward option 1, with the DB as the fallback) if any of the following becomes true:

1. **The dedup-replay rate dominates p95 latency for the endpoint.** Specifically: replay traffic exceeds ~30% of total submissions *and* dedup lookup is measurably the slowest step. Both conditions are required — high replay rate alone doesn't matter if the lookup is fast.
2. **A 24h-bounded "negative cache" becomes valuable.** Today the handler can confirm "this key was previously submitted with the same body" but cannot cheaply confirm "this key has *never* been submitted." If a reader endpoint ever needs the second guarantee at scale, an explicit cache becomes necessary.
3. **A retention policy on `notifications` shortens the row lifetime below the dedup window.** If notifications older than, say, 7 days are pruned, the dedup window contracts to 7 days unless a separate idempotency table preserves the key tuple beyond that.

Any of those conditions would justify the operational cost of a cache layer. Until then, the simpler design is the right one.

---

## Implementation pointers

- The dedup lookup happens at the top of `SubmitNotificationHandler::__invoke`, *before* identifier generation, so a replay never accidentally allocates a fresh `NotificationId`.
- The "same submission" rule (channel + recipient + payload + priority) lives on the aggregate as `Notification::matchesSubmission()`. A future change to the request body shape needs to be reflected there, not in the handler.
- Conflict detection produces `IdempotencyConflictException` (Application layer); `ApiExceptionRenderer` maps it to 409 with `error.code = IDEMPOTENCY_CONFLICT` and the offending key in `error.details.idempotency_key`.

---

## What this note is *not*

This note does not deprecate the system specification's mention of Redis-backed idempotency. It records that the Phase 1 implementation chose a different mechanism. If the project's spec is updated, the relationship between this note and that spec should be reviewed.PSNH