# ADR-0006: DLQ admin endpoints and structured logging of domain events

- **Status**: Accepted
- **Date**: 2026-04-27
- **Phase**: Phase 1 (`v0.1.0`)
- **Supersedes**: none
- **Related**: ADR-0002 (domain model structure), ADR-0003 (HTTP boundary), ADR-0005 (retry and dead-letter strategy)

## Context

Day 8 of the 28-day plan calls for two deliverables: dead-letter queue handling with an inspection endpoint, and structured logging across the dispatch flow with correlation IDs. Both pieces are operationally mandatory before this service can be considered production-ready: without DLQ visibility a stuck notification is invisible, and without structured logs a failure cannot be traced from API request to terminal disposition.

The plan numbers this ADR as "ADR-005" but the repository's accepted ADR sequence (0001 scope, 0002 domain model, 0003 HTTP boundary, 0004 channel dispatch, 0005 retry/DLQ) means today's ADR is **0006**. The plan-vs-repo offset is one-off and not worth retroactive renumbering.

A few constraints that shape the decisions below:

1. ADR-0005 already committed the project to a separate `dead_letter_marks` table joined to `notifications`. Day 4's `EloquentNotificationRepository` left attempts and the dead-letter mark hand-waved (`attempts: []` in `hydrate()`); that hand-wave needs to close before the DLQ inspection endpoints can render anything useful.
2. The OpenAPI spec already defines the `DlqEntry` and `PaginatedDlqEntries` schemas under `/dlq` and `/dlq/{id}`, including filter parameters (`reason`, `channel`, `created_after`, `created_before`) and a cursor pagination shape with only `next_cursor` (no `total_count`). The implementation is constrained to match.
3. ADR-0001 excludes any external event bus from Phase 1. Domain event observability today means writing to logs; it does not mean publishing to Kafka or NATS.

## Decision

### 1. Day 8 ships two read-only DLQ admin endpoints. Replay and discard are deferred.

`GET /api/v1/dlq` (list) and `GET /api/v1/dlq/{id}` (inspect) are released today. `POST /api/v1/dlq/{id}/replay` and `DELETE /api/v1/dlq/{id}` are documented in OpenAPI but not implemented in this commit.

The plan's Day 8 budget is 2 hours for "DLQ handling + admin endpoint to inspect" — read-only is what fits in two hours. Replay and discard each require a use-case handler with its own transactional concerns (replay creates a new aggregate; discard is a non-trivial state change with operational implications) and deserve their own scope.

### 2. The Day 4 hand-wave is closed: attempts and dead-letter marks now persist.

Two new tables (`attempts`, `dead_letter_marks`) with their migrations, two new Eloquent persistence models, and a rewritten `EloquentNotificationRepository::save()` that wraps the three-table write in a single transaction. `hydrate()` now loads attempts (ordered by `number`, applying `recordSuccess`/`recordFailure` based on the persisted outcome columns) and the dead-letter mark (using a new `reconstituteReplay()` factory that bypasses the once-only guard).

The `attempts` table has a CHECK constraint enforcing outcome consistency: an in-progress attempt has `(succeeded NULL, classification NULL, reason NULL, completed_at NULL)`; a successful attempt has `(succeeded TRUE, classification NULL, reason NULL, completed_at NOT NULL)`; a failed attempt has all four populated. The constraint mirrors the domain invariant in the schema — defence in depth.

### 3. The DLQ list is served by a separate read-model port, not the domain repository.

A new application-layer port `DeadLetteredNotificationsRepository` with one method, `list()`, is the contract; `EloquentDeadLetteredNotificationsRepository` is the implementation. The `Notification`-aggregate-shaped `NotificationRepository` is left alone.

### 4. DLQ visibility is tenant-scoped per API key, even for admin scopes.

Every DLQ query filters by the calling API key's id. There is no "look across all tenants" mode. Even when an `admin` scope is added (a future ADR), it grants access to additional endpoints, not to other tenants' notifications.

### 5. Three failure modes on `GET /dlq/{id}` collapse to a single 404 response.

"Id doesn't exist," "id belongs to another tenant," and "id exists but isn't in the dead-lettered status" all return the same `{ "error": { "code": "NOT_FOUND" } }` envelope.

### 6. `DeadLetterMark` gains a `replayedAt` field.

Previously `recordReplay()` took only the new notification's id; the timestamp was inferred at the persistence boundary. The entity now tracks both. `reconstituteReplay()` (new) is the repository's hydration path and skips the once-only guard.

### 7. `NullDomainEventDispatcher` is replaced by `StructuredLogDomainEventDispatcher` in the production binding.

The implementation renders each `DomainEvent` as one structured log line with: `event` (snake_case event name), `correlation_id`, `occurred_at` (ATOM format), and per-event-type fields (notification id, attempt number, classification, reason, etc.). Levels are per-event-type: `info` for requested/attempted/dispatched/scheduled-for-retry/replayed, `warning` for `dispatch_failed`, `error` for `dead_lettered`.

The renderer is a `match (true)` over `instanceof`, with a `default => throw new LogicException(...)` arm.

### 8. Pagination is cursor-based with `{ATOM-timestamp}|{id}` as the cursor format.

The `next_cursor` field is the only pagination metadata returned. No `total_count`, no previous-page cursor.

## Rationale

### Why a separate read-model port (decision 3)

CQRS-lite. The `NotificationRepository` is **write-shaped**: load aggregate, save aggregate, find by idempotency key. Adding `listDeadLetteredFiltered(LotsOfFilters $q)` would (a) conflate two responsibilities on one port and (b) push the domain layer to know about pagination, cursors, and operational filter columns — none of which are properties of a notification.

The DLQ list returns a flat projection (`DlqEntry`) that is intentionally not the aggregate. Loading the full aggregate per row would mean N+1 queries against `attempts` for a list operators scan through hundreds of times a day. The projection is one `SELECT` with a `MAX(completed_at)` correlated sub-query; the inspect endpoint earns the cost of full hydration because it's one row per call.

The application layer is the right home for this port (not the domain layer): a "list dead-lettered notifications matching these operational filters" question is an observability concern, not an invariant of what a notification *is*. Same shape as `RetryPolicy` from ADR-0005 §1.

### Why tenant-scoping is non-negotiable (decision 4)

Notifications carry user-supplied recipients, payloads, idempotency keys. A poorly-scoped DLQ endpoint is a cross-tenant data leak vector: any operator with `dlq:read` on tenant B could read tenant A's recipients and payloads. There is no operational requirement that justifies cross-tenant DLQ visibility — when the platform team needs to triage cross-tenant patterns, they go through a metrics layer (counts per tenant per channel per reason), not through one tenant's API key.

The scope (`dlq:read`) gates **whether you can use the endpoint**; the per-key data-layer filter gates **which rows are even visible**. Two distinct concerns, layered.

### Why one 404 for three failure modes (decision 5)

If the response distinguished "doesn't exist" from "wrong tenant" from "wrong status," an attacker could:
- enumerate notification ids by trying them and watching for the response code change ("403 means it exists; 404 means it doesn't");
- discover that a notification of theirs has been replayed by getting "wrong status" on its old id.

The response cost of returning the same shape for all three is tiny; the privacy benefit is real.

The diagnostic message stays in `details.reason` of the engineer-facing log entry (the renderer puts it there) but never on the wire response.

### Why DeadLetterMark gained replayedAt (decision 6)

The `dead_letter_marks` schema CHECK constraint requires `replay_notification_id` and `replayed_at` to be both null or both populated. Before today, the entity tracked only the id; the timestamp was inferred at the persistence boundary by reusing `dead_lettered_at`, which is wrong (the replay can happen weeks after the dead-letter event). Closing the gap means the entity is the single source of truth and the future replay handler — which has `$now` in scope — passes both values explicitly.

`reconstituteReplay()` exists because `recordReplay()`'s once-only guard exists, and rehydrating a previously-replayed mark from the database is not "recording a new replay" — it's replaying historical state. Conflating the two would either weaken the once-only guard or fail rehydration of legitimate previously-replayed marks.

### Why a single match-driven dispatcher (decision 7)

The alternatives considered:

1. **Per-event subscriber classes**, registered in a map. Fine if subscribers diverged in behaviour, but every event today goes to the same destination (PSR-3 logger). Per-event indirection without per-event behaviour difference is decoration, not abstraction.
2. **A naive `info` for every event with the full event as context.** Rejected: the operator dashboard for "dead-lettering rate" deserves to surface at error level so it lights up on existing alerting; warnings on retry-failed are how you watch a channel start to degrade before it tips over. Levelling each event type is the cheapest way to make a single log stream feed alerts.
3. **A reflection-based renderer that walks event accessors.** Rejected: the per-event log shape is part of the operational contract. Tying it to accessor names couples log shape to source code naming, which is the wrong way around.

The `default => throw` arm is the safety net. Adding a new domain event without adding a render branch fails at the call site (the first time the new event flows through a use case, in dev or in CI) — which is exactly the failure mode that makes "did the operator add this event to the observability surface?" answerable yes/no rather than "yes-but-we-forgot."

### Why correlation_id is THE anchor field

Every `DomainEvent` carries a `CorrelationId` by construction (the base class enforces it). One id flows through the HTTP request → the notification aggregate → every event the aggregate raises → every log line this dispatcher emits. A single grep against the JSON logs reconstructs the full lifecycle of one user-visible request, across the synchronous HTTP path and the asynchronous worker path. No other field has the cross-context cardinality to do that job.

### Why `event_name` instead of class names

Operator dashboards filter on column values; FQCNs are noisy and break when the namespace changes. The snake_case name (e.g. `notification_scheduled_for_retry`) is human-readable, stable across refactors, and the same value appears as the message string and the `event` context field — operators filter on whichever they prefer.

### Why cursor pagination with no total_count (decision 8)

Offset pagination is unstable across writes — a row inserted between page 1 and page 2 shifts every subsequent page. Cursor pagination is stable: each page is anchored to the prior page's last `(dead_lettered_at, id)` tuple. The DLQ is append-mostly; cursor semantics fit naturally.

`total_count` is intentionally absent. A count would force a second `COUNT(*)` against the same predicate, doubling the cost of every list call, for a number that's stale before the response is rendered. Cursor pagination's contract is "give me the next batch," not "tell me how big this is."

The cursor format (`{ATOM-timestamp}|{id}`) is opaque to the caller per the OpenAPI contract — the spec types it as `string`. The pipe is a separator that doesn't appear in either component (UUIDs use only hex+hyphens; ISO-8601 uses digits, `T`, dashes, colons, dot, `Z`). Tied timestamps are broken by id descending so paging is deterministic.

## Consequences

### Positive

- DLQ visibility is operational. Triage no longer requires direct database access.
- Every domain event has a structured-log surface. A future log-to-metrics pipeline (LogQL, Vector, Loki) aggregates `event_name` and `channel` directly; no second instrumentation pass needed.
- The Day 4 hand-wave is closed. Repository tests can now assert real persistence round-trips for attempts and dead-letter marks.
- Replay implementation is unblocked: the entity tracks `replayedAt`, the schema enforces consistency, the read model surfaces both fields. The only piece missing is the handler.
- The `DomainEventDispatcher` interface unchanged. Switching from `Null...` to `StructuredLog...` was a single binding edit. This is what a port-and-adapter design buys you.

### Negative

- Two new tables means two more migrations to keep in sync with the rest of the schema (and a slightly slower DB boot in CI).
- The `match (true)` over `instanceof` is exhaustive only by convention; PHP doesn't compile-check it. PHPStan/Psalm catch the gap, but a developer running tests without static analysis won't see the missing branch until the new event class flows through a use case.
- Cursor pagination doesn't support "jump to page N" — if operators want that UX, the dashboard would have to remember earlier cursors itself. For a triage tool it's the right call; if a user-facing UI ever wants the DLQ shape, this becomes a constraint.
- "Same 404 for three failure modes" makes debugging marginally harder for operators (they can't tell from the response alone whether the id was typo'd or genuinely cross-tenant). The diagnostic stays in `details.reason` in logs, not on the wire — operators read logs.
- Closing the `attempts: []` hand-wave means existing notifications saved before Day 8 will rehydrate with empty attempt lists. There are no such rows in any environment yet (the project hasn't shipped), so no migration step is required, but if the project is forked from an earlier checkpoint a backfill is needed.

### What this dispatcher does *not* do (yet)

- It does not emit metrics. Metrics come from the same JSON stream via a log-to-metrics pipeline that aggregates by `event_name` and `channel`. Doing it twice would double storage cost and risk drift.
- It does not publish to an external event bus. That seam exists at the `DomainEventDispatcher` interface — a future `CompositeDomainEventDispatcher` can fan out to both this logger and a Kafka/NATS bus without changing any application service or aggregate.

## Triggers to revisit

- **Replay handler is implemented.** When `POST /dlq/{id}/replay` ships, the application service uses `Notification::recordReplay($newId, $now)` and the second-save updates `replay_notification_id` and `replayed_at` together. No schema change.
- **Discard handler is implemented.** Adding `DELETE /dlq/{id}` (operator acknowledges, no replay) requires either a status change on `dead_letter_marks` (e.g. add a `discarded_at` column) or a hard delete of the row. The decision is deferred to that ADR.
- **Cross-tenant operator visibility is requested.** If platform engineers genuinely need cross-tenant DLQ access (e.g. for incident triage), the response is a separate `/admin/...` endpoint with its own scope, not a relaxation of the per-key filter on the existing DLQ endpoints.
- **An external event bus is added.** ADR-0001 excludes Kafka in Phase 1; if a later phase introduces one, this dispatcher becomes one branch of a `CompositeDomainEventDispatcher`. The interface stays the same.
- **Reconciliation pass for the "saved-as-queued, never re-enqueued" window from ADR-0005.** That gap is not closed by Day 8 and is its own ADR when the reconciler ships.
- **A new domain event is added.** The dispatcher's `default => throw` arm makes it impossible to ship without adding a render branch — but the operator should also confirm the new event has the right level (info / warning / error) and the right context fields for the dashboards keying on it.

## Alternatives rejected

- **Reusing `NotificationRepository` for the DLQ list.** Conflates write-shape and read-shape on one port, drags pagination into the domain layer, and forces N+1 hydration of attempts on every list call. Rejected for clarity and performance.
- **Returning structured `DlqEntry` directly from a domain service.** The `DlqEntry` is read-model-shaped (`final_attempt_at`, `replayed_at` in projection form). Putting it behind a domain service mixes domain types with application projections; the application layer is the right home.
- **Different HTTP codes for "wrong tenant" vs "wrong status" vs "missing."** Information disclosure outweighs the small operator-DX gain.
- **A separate `attempts_log` table for "in-progress" attempts.** Considered as an alternative to the three-valued `succeeded` column. Rejected: the three-state column is one fewer table and the CHECK constraint makes invalid combinations impossible at the DB level. Two tables would let an `attempts_log` row outlive the `attempts` row it references — the simpler shape is also the safer one.
- **Storing log records in `notifications.event_log` JSONB.** Considered as an alternative to structured logs to disk. Rejected: writes during dispatch already touch `notifications`, `attempts`, and (sometimes) `dead_letter_marks`; adding a fourth column to the same row on every event would balloon the row size and force a `UPDATE` per event instead of one per state transition. Logs to disk + a log-to-metrics pipeline is the lower-cost, higher-surface solution.
