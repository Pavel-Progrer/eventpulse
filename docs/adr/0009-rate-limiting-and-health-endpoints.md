# ADR-0009: Rate limiting and health endpoint strategy

- **Status**: Accepted
- **Date**: 2026-04-29
- **Phase**: Phase 1 (`v0.1.0`)
- **Supersedes**: none
- **Related**: ADR-0003 (HTTP boundary), ADR-0007 (secrets management), ADR-0008 (webhook signing)

## Context

Day 10 closes the last two gaps in the Phase 1 API surface: rate limiting across all authenticated endpoints and health probes for orchestration and monitoring. Both are specified explicitly in the technical specification (§5.3 and §4.5) and listed in the Phase 1 feature map (§8).

At this point in the plan, every core feature (submission, queuing, retry, DLQ, webhook destinations, structured logging) is implemented. Rate limiting and health probes are operational necessities rather than product features — they protect the service from abuse and make it observable to the infrastructure running it.

### Rate limiting context

Three requirements shape the design:

1. **Per-API-key limits**, not per-IP: IP-based rate limiting is trivially bypassed by clients rotating IPs and fails legitimate clients behind shared NAT. The spec is explicit: 100 write requests/min and 600 read requests/min per API key, with optional per-key overrides stored in `api_keys.rate_limit_per_minute`.

2. **Write/read split**: The asymmetric limits (100 write vs. 600 read) reflect the reality that writes are expensive (enqueue, persist) and reads are cheap (indexed query, no side effects). Clients polling `/dlq` or `/notifications/{id}` should not be penalised for aggressive monitoring.

3. **Per-key override on writes only**: The spec specifies `rate_limit_per_minute` as a single column. The natural reading is that overrides apply to the costly operation (writes). A separate read override column is deferred until there is a demonstrated need — speculative columns are dead weight.

### Health endpoint context

Two distinct probe semantics are required:

- **Liveness**: Is the process alive? Used by container orchestrators to decide whether to restart. Checking dependencies here is actively harmful — a temporary database outage would cause cascading container restarts, which is almost never the right response.

- **Readiness**: Are all dependencies healthy? Used by load balancers to decide whether to route traffic. A failing readiness probe removes the instance from rotation rather than restarting it.

The spec also requires the readiness endpoint to be unauthenticated (so monitoring systems don't require key rotation) but IP-rate-limited (so it can't be used as an amplification vector).

---

## Decision

### 1. Custom rate-limiter middleware over Laravel's built-in `throttle`

Laravel ships `throttle:n,m` middleware backed by `Illuminate\Cache\RateLimiter`. This is the correct underlying mechanism — we use it — but the built-in middleware has two limitations that prevent using it off-the-shelf:

1. It does not support dynamic per-request limits (the limit is baked into the route definition, not read from a model attribute at runtime).
2. Its 429 response is not the project's error envelope; it returns a redirect or a generic exception that would produce HTML unless the exception handler intercepts it.

`ThrottleApiRequests` wraps `RateLimiter` directly, resolving the limit from the `ApiKey` model at request time and returning a `JsonResponse` that matches the `Error` schema in `openapi.yaml`. The middleware is ~100 lines and has no dependencies beyond `RateLimiter` and the `ApiKey` model — it is not complex enough to justify a third-party package.

**Alternatives considered:**

- **Laravel's built-in `throttle` + exception handler patch**: The exception handler (`ApiExceptionRenderer`) already intercepts `ThrottleRequestsException` (added in Day 10) as a safety net. But relying on exception handling to shape the 429 response means the response construction is split across two classes with no obvious contract between them. Returning a `JsonResponse` directly from the middleware is cleaner.

- **`spatie/laravel-rate-limit`** or similar: Unnecessary dependency for a feature we can implement correctly in 100 lines. The project's dependency budget should be spent on things that solve genuinely hard problems.

### 2. Separate write and read buckets in Redis

The bucket key is `eventpulse:rl:{api_key_id}:{write|read}`. Two independent keys in Redis mean exhausting the write budget has no effect on the read budget. This is an intentional product decision (aggressive polling should not cause write failures) and a simple implementation decision (two keys instead of one).

The key does not include a timestamp segment. Laravel's `RateLimiter::hit($key, $decaySeconds)` handles window expiry using Redis TTL — the key expires after `$decaySeconds` (60), effectively resetting the bucket at that point. This is a sliding window, not a fixed window aligned to the clock minute. The difference matters only at window boundaries and does not warrant the complexity of a fixed-window implementation.

### 3. Single `HealthController` with two actions

Liveness (`liveness()`) and readiness (`readiness()`) are in the same controller class. They share no logic, but they share a route namespace (`/health` and `/health/detailed`) and a conceptual grouping (operational probes). Splitting them into two classes would require two files, two tests, and two registrations for four methods total. The single-class approach is simpler without closing off any future extension.

### 4. Readiness checks database, Redis, and queue depth

Three checks:

- **Database**: `SELECT 1` against the primary connection. Detects connection failures, authentication failures, and firewall rules — the most common production failure modes. Does not check schema version or query plans.

- **Redis**: `put` + `forget` on a probe key. Tests both the write and read code path on the cache connection. A read-only Redis check (GET only) would miss write failures, which are the more likely failure mode for a cache under write pressure.

- **Queue depth**: `QueueManager::size()` on the default queue. Reports the pending count but does not fail on a large queue — high depth is an operational concern (workers are falling behind) that does not warrant 503. A 503 here would be a false positive: the HTTP layer is healthy, the workers are just slow.

**What is deliberately not checked**: worker count, LLM provider connectivity, mail server connectivity. These are cross-process or external concerns. Worker count is not visible to the HTTP process; LLM and mail are only needed at dispatch time and their absence does not render the HTTP layer unable to accept new notifications.

### 5. `rate_limit_per_minute` column applies to writes only; null means system default

The column is nullable rather than defaulting to 100 (the current system default). Storing null means "use whatever the system default is," which allows the default to change without a data migration. Storing 100 would make it indistinguishable from a deliberate override — if the default is later raised to 150, rows with 100 would be silently capped at the old value.

The column is `unsignedInteger` to prevent negative values at the database level. Zero is technically allowed by the type but rejected at the application layer (`ThrottleApiRequests` treats 0 as invalid and falls back to the system default); a CHECK constraint could enforce this, but it is not worth the migration complexity for a value that is set only via Artisan and never via end-user input.

---

## Rationale

The decisions above share a common thread: prefer explicit, minimal implementations over framework magic when the framework abstraction does not fit the spec cleanly. Laravel's `throttle` middleware is appropriate for most applications; it is not appropriate here because the spec requires dynamic per-key limits and the error envelope contract.

The health endpoint design mirrors industry practice (Kubernetes liveness/readiness probe semantics) even though the project does not yet run on Kubernetes (ADR-0001 §exclusions). Getting the semantics right now means Phase 2's Docker and orchestration work does not require revisiting the health probe design.

---

## Consequences

### Positive

- Rate limiting is enforced on every authenticated endpoint with a single middleware alias (`throttle.api`), not duplicated route-by-route.
- Write and read clients are isolated: a write-heavy client does not degrade a monitoring client polling for status.
- Per-key overrides can be set without code changes (Artisan command → update `rate_limit_per_minute` → takes effect on next request).
- Health probes are usable by orchestrators and monitoring without API keys.
- 429 and 404 responses are consistent with the rest of the error envelope — no more HTML error pages leaking from the API surface.

### Negative

- The sliding-window implementation means two clients hitting the limit at exactly the same clock boundary can in theory each get 100 writes in the same 60-second span. This is a known limitation of the sliding-window approach. Fixed-window rate limiting would avoid this but would require storing a per-window counter and is not worth the complexity for this service's scale.
- Queue depth reporting via `QueueManager::size()` requires the queue driver to implement `Countable`. The Redis queue driver does; the `sync` driver does not. If the queue driver is changed, the readiness check degrades gracefully (returns a `fail` status with an explanatory error string) rather than silently returning 0.

---

## Triggers to revisit

- The spec adds a per-key *read* rate limit override → add `read_rate_limit_per_minute` column; extend `ThrottleApiRequests::resolveLimit()` to read it.
- The queue depth check needs a threshold to distinguish "large queue" (warning) from "queue is not draining" (error) → add a configurable threshold to `eventpulse.php` and promote the check to `fail` when exceeded.
- A key rotation event (ADR-0007) should reset the old key's rate-limit buckets → add a `RateLimiter::clear()` call to the key-rotation command.
- Phase 2 introduces distroless containers and a health endpoint that can be queried by the container orchestrator without a shell → confirm the readiness check latency is within the orchestrator's probe timeout (default: 30s Kubernetes, well above our < 10ms target).
