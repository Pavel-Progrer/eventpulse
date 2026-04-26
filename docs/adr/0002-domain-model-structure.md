# ADR-0002: Domain Model Structure and Aggregate Boundaries

- **Status:** Accepted
- **Date:** 2026-04-21
- **Deciders:** Pavel Rodin

---

## Context

Day 2 of the implementation sprint required translating the domain model described in `docs/domain.md` into PHP code. Several non-obvious decisions arose during implementation that are not fully resolved by the domain document alone. This ADR records those decisions and their rationale so that a future engineer — or a future self — can understand not just what the code does but why it is shaped the way it is.

The domain.md establishes the high-level model (three aggregates, six states, seven lifecycle events). This ADR covers the **implementation-level decisions** within that model: what is a class, what is an enum, where validation lives, how events are collected, and what the reconstitution boundary looks like.

---

## Decisions

### 1. `DeadLetterMark` is a component of `Notification`, not a separate class hierarchy

`domain.md §3.1` specifies this explicitly, but it's worth reinforcing here: `DeadLetterMark` is an `Entity` inside the `Notification` aggregate. It is not a separate aggregate, a value object, or a flag field.

**Why an entity and not a value object?** Because it mutates: when a dead-lettered notification is replayed, the mark records the replay notification id. A value object cannot mutate; producing a new `DeadLetterMark` with the replay id would leave the aggregate holding a stale reference. Making it a mutable entity with a single allowed mutation (`recordReplay()`) is the cleaner model.

**Why not a separate aggregate?** The operator-facing dead-letter queue endpoint returns *notifications in the dead-lettered state* — not a separate entity type. Modelling it as a separate aggregate would create a consistency obligation (the mark must always refer to an existing notification) across aggregate boundaries, which violates the DDD rule that cross-aggregate consistency is eventually consistent. The mark is part of the notification's state; keeping it inside the aggregate keeps the invariant synchronous.

---

### 2. State machine is encoded in `NotificationStatus::canTransitionTo()`, not scattered across the aggregate

The `Notification` aggregate enforces all state transitions through a single private `transitionTo()` method, which consults `NotificationStatus::canTransitionTo()`. Dead-lettering bypasses `transitionTo()` and sets `$this->status` directly because it requires an additional domain invariant check (at least one failed attempt) that the status enum cannot know about.

**Alternative considered:** Putting transition validation entirely inside the aggregate, with a large `match` in `transitionTo()`. Rejected because the state machine is a property of the *status*, not of the aggregate. An enum with a `canTransitionTo()` method makes the state machine testable in isolation and keeps it visible as a first-class model artifact.

**Alternative considered:** Using a state pattern (separate class per state). Rejected as over-engineered for six states. The complexity does not justify the indirection at this domain size.

---

### 3. Domain events are collected internally and released by the application layer

The aggregate holds a `$pendingEvents` array populated by every state transition method. The application layer calls `pullPendingEvents()` after persisting the aggregate and dispatches those events.

**Why not dispatch immediately?** Publishing an event for an aggregate that was never persisted (due to a database error after the event was dispatched) creates a split-brain: the event says something happened that, from the database's perspective, did not. Collecting and releasing after persistence avoids this.

**Why not use Laravel's own event system here?** The domain layer has zero framework dependencies by design (see `domain.md §1` and `ADR-0001`). Laravel's `event()` helper is an infrastructure concern. The application service owns the bridge between domain events and Laravel's dispatcher.

**Implication:** The application layer must call `pullPendingEvents()` after every aggregate operation. This is a convention enforced by code review, not by the type system. A future improvement could make the repository responsible for this, but that would couple the repository to event dispatch — a different trade-off.

---

### 4. `Notification::reconstitute()` is the infrastructure entry point

Two paths create a `Notification` object: `Notification::request()` (new aggregate, raises `NotificationRequested`) and `Notification::reconstitute()` (rebuilding from persistence, raises nothing). Having both named constructors makes the intent explicit and prevents the repository from accidentally raising events when hydrating.

**Why not a constructor with a flag?** A boolean `$isNew` parameter is a code smell — it makes the constructor responsible for two different things. Two named constructors with different signatures and different responsibilities is cleaner.

---

### 5. `NotificationPayload` validates channel-specific shape at construction

Invariant 5.1.10 (payload shape matches channel) is enforced by `NotificationPayload::forChannel()`. The payload object knows its channel and validates its own content. The aggregate calls this during `request()`, so an invalid payload is rejected before the aggregate is ever constructed.

**Alternative considered:** Validating at the HTTP boundary only (in the Laravel FormRequest). Rejected because the domain model would then depend on the application layer having done the right thing. A self-validating value object means the aggregate is correct by construction regardless of which path created it — including tests and future non-HTTP callers.

**Channel-specific validation rules defined here:**
- **Email:** requires `subject` (string) + at least one of `text` or `html` body.
- **Webhook:** requires a non-empty array (any JSON-serialisable structure).
- **SMS:** requires `body` (string, max 1600 characters / ~10 SMS segments).

These rules are not exhaustive (no spam filtering, no encoding checks) — they are the minimum necessary to guarantee a delivery attempt is not structurally invalid.

---

### 6. `Recipient` is an abstract class hierarchy, not an interface

`EmailRecipient`, `WebhookRecipient`, and `SmsRecipient` all extend `Recipient`. This allows the aggregate to hold a single typed `Recipient` property while the concrete type carries channel-specific behaviour (e.g., `WebhookRecipient::destinationId()` is only meaningful on that type).

**Why abstract class and not interface?** The `__toString()` default implementation is shared across all three types and should not be re-declared everywhere. PHP interfaces cannot provide default method bodies. A single shared `toString()` delegation via `__toString()` in the abstract base is the right trade-off.

**Why not a sealed interface (PHP doesn't have one)?** PHP has no sealed/union-type hierarchy enforcement. The convention is: if you need to handle a `Recipient` in a channel-specific way, use `match(true)` with `instanceof` checks, which is exhaustive by inspection and caught by static analysis tools (Psalm, PHPStan) configured to warn on non-exhaustive matches.

---

### 7. `CorrelationId` and `IdempotencyKey` are separate value objects despite similar shapes

Both are validated ASCII strings. The temptation is to unify them. They are kept separate because:

- Their semantics are opposite: `IdempotencyKey` is stable across retries of the same logical request; `CorrelationId` changes on each submission.
- Their max lengths differ (255 vs 128).
- Type-level separation means the compiler catches callers who accidentally pass one where the other is expected.

---

### 8. `maxAttempts` is passed into `recordFailure()`, not stored on the aggregate

The retry policy ceiling (`maxAttempts`) is a configuration value, not domain state. Storing it on the aggregate would mean: (a) it must be supplied at construction time even though it's not a property of the notification itself, and (b) changing the policy would require migrating existing aggregate state.

Passing it as a parameter to `recordFailure()` keeps it in the application layer (where configuration belongs) and keeps the aggregate's invariant simple: "has the current attempt number reached the ceiling the caller provided?"

---

## Consequences

### Positive

- The aggregate is self-defending: it enforces all invariants regardless of which path created it.
- The domain layer has zero framework dependencies and is unit-testable without bootstrapping Laravel.
- The state machine is visible and testable in isolation via `NotificationStatus`.
- Domain events are safe to dispatch: they are only released after persistence.
- The reconstitute/request distinction makes repository code obviously correct.

### Negative

- `pullPendingEvents()` must be called by convention after every aggregate mutation. If the application layer forgets, events are silently dropped. This is an accepted risk at this project size; a larger team might enforce it via a repository base class.
- Passing `maxAttempts` as a parameter means the signature of `recordFailure()` carries an infrastructural concern. An alternative is a `RetryPolicy` value object, which would be the right refactor if retry policies become more complex (per-channel, per-priority). Noted for Phase 2.
- The `Recipient` hierarchy uses `instanceof` checks in `assertRecipientMatchesChannel()`. PHP has no exhaustive pattern matching; static analysis must be configured to catch gaps when a new `Channel` case is added.

---

## What is inside the aggregate

| Component | Type | Reason |
|-----------|------|--------|
| `NotificationId` | Value Object (identity) | Immutable root identity |
| `Channel` | Enum (value object) | Part of notification definition |
| `Recipient` | Value Object hierarchy | Part of notification definition |
| `NotificationPayload` | Value Object | Part of notification definition |
| `Priority` | Enum (value object) | Part of notification definition |
| `IdempotencyKey` | Value Object | Part of notification definition |
| `NotificationStatus` | Enum | Aggregate state |
| `CorrelationId` | Value Object | Request tracing, set at construction |
| `Attempt[]` | Entity collection | Attempt history, owned by aggregate |
| `DeadLetterMark` | Entity (optional) | Dead-letter state, owned by aggregate |

## What is outside the aggregate

| Component | Type | Reason |
|-----------|------|--------|
| `WebhookDestination` | Separate aggregate | Independent lifecycle; referenced by id |
| `ApiKey` | Separate aggregate | Independent lifecycle; referenced by id string |
| `maxAttempts` / retry policy | Configuration | Not domain state |
| `retryAfter` calculation | Application layer | Infrastructure concern (backoff algorithm) |
| Domain event dispatch | Application layer | Must happen after persistence |
| Idempotency window check | Application layer | Cross-aggregate invariant |