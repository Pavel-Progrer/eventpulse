# ADR-0005: Retry Policy and Dead-Letter Strategy

- **Status:** Accepted
- **Date:** 2026-04-25
- **Deciders:** Pavel Rodin
- **Related:** ADR-0001 (scope and exclusions), ADR-0002 (domain model structure and aggregate boundaries), ADR-0004 (channel dispatch via strategy pattern)

> **Numbering note.** The 28-day plan listed this as "ADR-004 — retry policy" because it predates ADR-0003 (HTTP boundary). The repo's actual ADR sequence is the source of truth: 0001 → 0002 → 0003 (HTTP boundary, written on Day 3) → 0004 (channel dispatch, Day 5) → 0005 (this one). Subsequent ADRs continue from 0006.

---

## Context

Day 5 landed the dispatch flow with two intentional placeholders in `DispatchNotificationJob`:

```php
private const MAX_ATTEMPTS_DAY_5    = 1;   // single attempt → DLQ on first transient failure
private const RETRY_AFTER_SECONDS_DAY_5 = 60;  // unused; retry never actually happens
```

The aggregate's `recordFailure(maxAttempts, retryAfter)` already takes those values as parameters — that was deliberate from Day 2's domain modelling. ADR-0002 §"What's *not* in the aggregate" puts retry policy outside the domain on the grounds that it is a tunable orchestration decision, not an invariant of what a notification *is*.

The questions to settle now are:

- **Where does `RetryPolicy` live?** Domain port, application port, or infrastructure-only?
- **What shape does the policy take?** One interface or two? Per-channel constants or a formula? Pure or pluggable randomness?
- **How does the retry actually happen?** Day 5 records the failure but the worker never re-enqueues — Day 6 has to close that gap. Is re-enqueue triggered by a domain event subscriber, by inspection of the aggregate's post-failure state, or by something else?
- **Where does dead-lettering happen, given the aggregate already does it?** The decision is in the domain; what's left for the application layer is observability and DLQ administration (the latter shipping Day 8). Is there anything for ADR-0005 to say about it?
- **What about receiver-controlled overrides?** The specification (§5.2) calls out "honor `Retry-After` if present" for webhook 408/429. Does Day 6 ship that, or defer it?

The cumulative shape of these answers governs how every transient failure flows from Day 6 onward, so it is worth recording at decision time.

---

## Decisions

### 1. `RetryPolicy` is an Application port, not Domain

```
src/EventPulse/Application/Notification/Retry/RetryPolicy.php
```

The interface is in the application layer because retry policy is *not* a domain invariant. The aggregate's `recordFailure(maxAttempts, retryAfter)` accepts both as parameters precisely so the policy can change per-channel, per-tenant, eventually per-destination — without the domain caring. Compare to `NotificationStatus::canTransitionTo()`, which encodes a state-machine rule that is a property of what a notification *is* and lives on the enum.

The application layer is the natural home for "rules an operator tunes." It is also where `DispatchNotificationJob` (the only consumer) lives logically, even though the job itself sits in `app/Jobs/` (a Laravel constraint, not an architectural one).

**Why not a Domain port?** A domain port would pull policy parameters into the domain's vocabulary. The next step from there is "should the aggregate own its own retry policy?" — at which point per-tenant retries means the aggregate carries the tenant's tuning, which is exactly the coupling we wanted to avoid by parametrising `recordFailure`.

**Alternative considered:** a global `config/eventpulse.php` table read directly inside the job. Rejected because it makes the job non-testable (every test needs to seed config) and because it puts policy in two places — the table and the line in the job that reads it. The interface centralises the seam: tests substitute `StaticRetryPolicy`, production substitutes `ChannelRetryPolicy`, and the job just consumes `RetryPolicy`.

---

### 2. One interface for both `maxAttemptsFor` and `nextDelay`, not two

```php
interface RetryPolicy
{
    public function maxAttemptsFor(Channel $channel): int;
    public function nextDelay(Channel $channel, AttemptNumber $failedAttemptNumber): DateInterval;
}
```

Both methods are consumed at the same call site (the job, after every transient failure) with the same `Channel`. They share the same configuration: the spec's §5.2 row for a channel binds max-attempts, base, max, and jitter together. A caller that has the policy for one always wants the policy for the other.

**Why not split into `RetryPolicy` + `Backoff`?** Splitting would introduce a second injection at one call site for no decoupling benefit. The two methods do not vary independently in any future I can foresee — there is no plausible "I want to swap the backoff curve but keep the max-attempts" or vice versa. Coupling them in a single interface is honest about how they are used.

**Why `failedAttemptNumber` rather than `nextAttemptNumber` as the second argument?** The aggregate has just recorded `failedAttempt`'s outcome when the policy is consulted; that is the natural label. The formula uses `failedAttempt - 1` as the exponent to make the *first* failure schedule a delay equal to `base` (matching the spec table's "base delay" column meaning "the delay after the first failure"). Naming the parameter for what we have rather than what it produces leaves the formula readable.

---

### 3. The production implementation reads the `§5.2` table from configuration

```
config/eventpulse.php
src/EventPulse/Infrastructure/Notification/Retry/ChannelRetryPolicy.php
src/EventPulse/Infrastructure/Notification/Retry/RetrySettings.php
```

The configuration file mirrors specification §5.2 row-for-row:

```php
'retry' => [
    'webhook' => ['max_attempts' => 6, 'base_delay_seconds' => 10, 'max_delay_seconds' => 3600, 'jitter_fraction' => 0.25],
    'email'   => ['max_attempts' => 4, 'base_delay_seconds' => 30, 'max_delay_seconds' => 1800, 'jitter_fraction' => 0.25],
    'sms'     => ['max_attempts' => 3, 'base_delay_seconds' => 15, 'max_delay_seconds' => 600,  'jitter_fraction' => 0.25],
],
```

Each row is parsed into a `RetrySettings` value object, whose constructor enforces:

- `maxAttempts ≥ 1` — zero attempts is incoherent (the first dispatch *is* attempt 1).
- `baseDelaySeconds ≥ 0` — negative delays would put the retry in the past.
- `baseDelaySeconds ≤ maxDelaySeconds` — otherwise the cap is unreachable and the formula degenerates silently.
- `jitterFraction ∈ [0, 1)` — `≥ 1.0` would let `(1 + jitter)` reach zero or go negative, retrying instantly or "before now."

The `ChannelRetryPolicy` constructor additionally enforces that every `Channel` case has a settings row: a misconfiguration fails at boot, not at the first dispatch on the missing channel. This is the same pattern `ChannelDispatcher` uses (ADR-0004 §6) and for the same reason.

**Why config rather than hard-coded constants?** A deployment may legitimately want to slow retries on a flaky receiver, or speed them up for an internal-only channel where the receiver is owned by the same team. An env-var override per field (`EVENTPULSE_RETRY_WEBHOOK_BASE_DELAY` etc.) makes that a deployment change rather than a code change.

---

### 4. Randomness is pluggable via `Random\Randomizer`, not a static `random_int`

The jitter calculation samples uniformly from `[-jitterFraction, +jitterFraction]`. Tests need this to be deterministic; production wants it to be unpredictable (see §5 below). PHP 8.4's `Random\Randomizer` solves both: production wires `new Randomizer(new Secure())`, tests wire `new Randomizer(new Mt19937($seed))`.

**Why this matters for tests:** without injection, every backoff test would be inherently flaky — the unit test that asserts "delay is in [75, 125]" passes because the production formula's range is `[base * (1 - 0.25), base * (1 + 0.25)]`, but the test that asserts "two policies with the same seed produce identical sequences" could not be written at all. With injection, both are clean.

**Alternative considered:** a `Backoff` trait (or static method) using `random_int` directly. Rejected because every retry-curve test would have to either tolerate variability or shell out to a locally-seeded fixture, and the asymmetric-information cost (production calls A, tests stub B) is exactly what dependency injection exists to avoid.

---

### 5. Production uses the cryptographic engine for jitter, not Mersenne Twister

```php
new Randomizer(new Random\Engine\Secure())
```

Cryptographic security is not strictly *necessary* for jitter — uniform distribution is the only mathematical property the formula requires. But `Secure` is the right *default*:

- It cannot be predicted by a co-tenant who learns one retry instant. This prevents a synchronisation attack where a malicious neighbour times its own retries to converge on ours and overwhelm a downstream receiver that is rate-limited per source IP.
- The performance cost is negligible at retry-event rates (a handful per second at worst, even under sustained outage).
- It removes an entire class of "the seed leaked, retries are predictable" failure modes.

**Alternative considered:** a seeded Mt19937 with a host-derived seed. Faster, but no realistic part of the system is so constrained that the difference is observable, and "fast PRNG" is rarely the right answer when "secure PRNG with the same interface" exists.

---

### 6. Re-enqueue on retry is triggered by post-persistence aggregate state, not a domain-event subscriber

After the aggregate's `recordFailure` and the worker's `save`, the orchestration layer inspects `$notification->status()`:

- `Queued` → the aggregate decided to retry; the orchestrator re-enqueues with `availableAt = now + nextDelay`.
- `DeadLettered` → the aggregate gave up; nothing more to do.
- `Dispatched` (the success path) → trivially, no re-enqueue.

**Why not a domain-event subscriber on `NotificationScheduledForRetry`?** A subscriber would be the textbook DDD answer, and ADR-0008 (Day 8's structured-log dispatcher) will introduce real subscribers for observability. But for the specific decision "should the queue be re-fed," reading the aggregate's status after `save` has two concrete advantages:

1. **The decision and the action live in the same transaction-ish boundary.** If the subscriber crashed after `save` but before re-enqueue, the notification would be `queued` in the database with no job in the queue — silently stuck. Doing the re-enqueue inline and *then* releasing events keeps the "retry was scheduled" fact and the "retry is actually scheduled" fact aligned.
2. **The orchestration layer already has the `retryAt` timestamp** (it computed it before calling `recordFailure`). Reading it back off the event would require the subscriber to inspect the pendingEvents collection before drainage, which inverts the dependency.

The trade-off: a future subscriber that wants to observe `NotificationScheduledForRetry` for audit or metrics still gets the event normally — the `pullPendingEvents` drainage is unchanged. We are not preventing event-driven observers; we are simply not relying on one for the re-enqueue itself.

**Alternative considered:** widening `NotificationDispatchQueue` with a separate `enqueueRetry()` method. Rejected because the only difference from the original `enqueue()` is the `availableAt` parameter; introducing a second method to encode "this enqueue happens to be a retry" would be naming-by-context rather than naming-by-shape, and the queue infrastructure does not actually do anything different for retries.

---

### 7. `NotificationDispatchQueue::enqueue` widens with an optional `availableAt` rather than gaining a sibling method

```php
public function enqueue(
    NotificationId $notificationId,
    CorrelationId $correlationId,
    Priority $priority,
    ?DateTimeImmutable $availableAt = null,    // ← Day 6 addition
): void;
```

The HTTP submission path keeps calling the three-argument form (default `null` = "available immediately"); the worker's retry path passes the absolute timestamp it just used as `retryAfter` on the aggregate.

**Why an absolute timestamp rather than a `DateInterval` delay?** The retry-after timestamp is also persisted into the `NotificationScheduledForRetry` domain event and (Day 8) into structured log entries. Computing it once at the application layer and passing the same value through both paths means the queue's "available at" and the event's "retry after" are guaranteed to agree to the second. A relative `DateInterval` would be re-resolved against a different "now" inside the adapter — a small drift that is hard to debug when retry timing matters.

**Why not a separate `enqueueDelayed` method?** Same reason as ADR-0005 §6 above: the only difference is the parameter, and one method with one optional parameter reads better than two methods with overlapping responsibilities.

---

### 8. `tries = 1` at the Laravel queue level — retries are domain-driven re-enqueues, not queue-driver re-throws

```php
public int $tries = 1;
```

The Laravel queue driver does not retry the job on its own. Each retry is a *new* enqueue triggered by the domain's decision after a transient failure. Each attempt arrives at the worker with `tries = 1` and decides its own fate from current persisted state.

**Why this matters:** if Laravel's queue retried at the driver level, every transient failure would produce two retry signals — one from the domain (correctly, with the policy's delay) and one from the driver (with whatever Laravel was configured to do). The two would race; the operator would see "max_attempts = 6" in config and "actual attempts = 12" in the database. Disabling driver-level retry forces the domain to be the single source of truth.

**Why `tries = 1` and not `tries = 0`?** Laravel reserves `0` for "infinite" in some queue drivers and treats it as a configuration error in others. `1` is unambiguous: one shot, no retry, fail-fast back to the supervisor.

---

### 9. Dead-lettering keeps living in the aggregate

The aggregate dead-letters when a transient failure leaves no retries remaining (`recordFailure`) or when an unrecoverable failure occurs at any point (`recordUnrecoverableFailure`). The orchestration layer does *not* dead-letter; it observes the status post-save and acts accordingly.

This is not a Day 6 decision — ADR-0002 already locked it in — but Day 6 is the moment it materialises in the dispatch path, so it is worth noting here for completeness. The reason it stays in the aggregate: the dead-letter precondition (invariant 5.1.5: at least one failed attempt) is a domain invariant, not an orchestration rule. Dead-lettering "from outside" would either duplicate the precondition check (bug-prone) or skip it (invariant violation).

The DLQ administration endpoints (inspection, replay, discard) are scoped for Day 8.

---

## Consequences

### Positive

- **Retry behaviour is fully observable.** Every transient failure logs the classification, the chosen `max_attempts`, the chosen `retry_after`. Day 8's structured-log dispatcher can attach correlation IDs to all of them; metrics and dashboards can surface "average attempts per channel" and "delay distribution" without the application changing.

- **The retry curve is unit-testable.** `ChannelRetryPolicyTest` asserts the doubling sequence point-by-point with zero jitter, the cap at the configured maximum, the jitter band with a seeded Randomizer, and reproducibility across same-seed instances. The production formula has zero hidden randomness.

- **Per-channel tuning is operator-tunable without a code change.** The retry table lives in `config/eventpulse.php`, every field has an env-var override, the spec values are the defaults. A flaky receiver can be slowed down, an alert channel can be sped up, a quiet channel can be tightened — all via deployment config.

- **The dispatcher and driver layers are unchanged.** `ChannelDispatcher`, the three drivers, and `DispatchOutcome` are exactly as Day 5 left them. Day 6 is purely an orchestration-layer change. This is the strategy pattern paying its rent: a substantial behaviour change with zero ripple into channel code.

- **Test doubles compose with the change.** `InMemoryNotificationDispatchQueue` and `EnqueuedDispatch` gained an optional `availableAt`; existing handler tests that don't pass it still pass. The new `StaticRetryPolicy` follows the same "real implementation, not a mock" philosophy as the rest of the test-double suite.

- **The contract is forward-compatible with per-tenant retries.** When a future requirement says "this customer wants webhook retries to use a 60-second base," the `RetryPolicy` interface is already the seam — we add a `TenantAwareRetryPolicy` decorator that consults the tenant id (carried on the notification's `apiKeyId`) before delegating to the channel-level policy.

### Negative

- **One more interface, one more singleton, one more config block.** A small project that just hard-codes `(maxAttempts, baseSeconds, jitter)` constants in the job would not need any of this. The cost is intentional: the seam exists where future per-tenant or per-destination customisation will need to plug in.

- **Test determinism depends on a seeded `Randomizer`.** A test that constructs `ChannelRetryPolicy` with `new Randomizer(new Secure())` will produce non-reproducible jitter assertions and will eventually fail. The `seededRandomizer()` helper in `ChannelRetryPolicyTest` is the canonical pattern; a code reviewer noticing a `Secure` engine in a test should treat it as a smell.

- **Receiver-controlled retry-after is not yet honored.** Spec §5.2 says webhook 408/429 should honor the `Retry-After` header. Day 6 ships only the formula; the spec gap is recorded in §"Triggers to revisit" below.

- **The re-enqueue is not transactional with the failure persistence.** If the worker process is killed *between* the `save` (status = `Queued`) and the queue dispatch (`enqueue` with `availableAt`), the notification is in the database as queued but no job exists. This is the standard at-least-once / at-most-once trade-off; our choice of "save first, re-enqueue second" means a stuck-queued notification is a known failure mode. Mitigated by: a periodic reconciliation job (out of scope for Day 6 — likely Day 8 with the DLQ admin endpoint, or filed as a follow-up) that finds notifications in `queued` with `attempts > 0` and the most recent attempt's `failed_at + retry_after < now()` and re-enqueues them. The size of this window in practice is "the gap between a `save` and the next instruction" — small, but not zero.

- **`tries = 1` means a worker-side fatal error (segfault, OOM, supervisor kill) does not retry the dispatch.** The supervisor will re-pick the job from the queue if it was not acknowledged, but a fatal between dispatch and acknowledgement loses the attempt. This is acceptable for a notification service; for a system where every attempt is precious it would not be. The trade-off is documented here so a future engineer who needs to revisit it has the reasoning.

---

## Triggers to revisit

- **Webhook receivers send `Retry-After` on 408 / 429** (specification §5.2). When this gap closes, `DispatchOutcome::failure` gains an optional `?int $retryAfterSeconds` (or `?DateTimeImmutable $retryAt`); the webhook driver populates it from the response header; the job prefers the receiver's value over the formula. The change is local: outcome shape, driver, and one preference line in the job. The unit-test pattern is "webhook returns 429 + `Retry-After: 120`; expected re-enqueue uses 120s, not the formula's value."

- **Per-tenant retry overrides are requested.** A `TenantAwareRetryPolicy` decorator wraps `ChannelRetryPolicy`, looking up tenant overrides via `apiKeyId`. The interface stays the same; only the binding in the service provider changes. This was a design goal, not an oversight.

- **Per-destination retry overrides for webhooks.** When `WebhookDestination` becomes a real aggregate (Day 9), a destination may want its own `(maxAttempts, baseDelay)`. Same pattern: a `DestinationAwareRetryPolicy` looks up the destination's override.

- **A "circuit breaker" requirement.** If too many consecutive failures across distinct notifications target the same destination, the policy may want to stop trying entirely until a probe succeeds. That is a *cross-notification* policy and does not fit `RetryPolicy`'s per-notification interface. It would arrive as a separate `CircuitBreaker` port consulted by the job before the dispatch, not as an extension of `RetryPolicy`.

- **Reconciliation for the "saved as queued, never re-enqueued" window** (consequence §"Negative"). If operations data shows this failure mode happening in practice, a periodic reconciliation pass becomes necessary. The natural home is alongside Day 8's DLQ admin scope.

- **The retry curve produces unacceptably long worst-case delays.** Spec puts webhook's max at 1 hour. If a real customer's SLA reveals that a single notification can sit in retry for an hour after the receiver comes back up (unlikely, given receivers usually recover much faster than the cap), the cap or the curve shape would need revisiting — not the architecture.
