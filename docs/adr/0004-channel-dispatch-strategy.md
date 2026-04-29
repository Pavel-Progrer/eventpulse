# ADR-0004: Channel Dispatch via Strategy Pattern

- **Status:** Accepted
- **Date:** 2026-04-24
- **Deciders:** Pavel Rodin
- **Related:** ADR-0001 (scope and exclusions), ADR-0002 (domain model structure and aggregate boundaries), ADR-0003 (HTTP boundary and application services)

---

## Context

Day 5 lands the dispatch flow proper. Day 4 left `DispatchNotificationJob::handle()` as a placeholder that loaded the aggregate and logged that it had received the job; now the worker has to actually deliver the notification to its channel and feed the outcome back into the aggregate's lifecycle (`recordSuccess`, `recordFailure`, eventual dead-letter).

EventPulse supports three channels — email, webhook, SMS — and the specification commits to SMS even though Phase 1 will not wire a real SMS provider. The dispatch flow therefore has to handle three transport backends, with three different failure modes, three different timeout regimes, and three different "what does success even mean" definitions, while being clean enough to extend with future channels (Slack, push, etc.) without a central rewrite.

The questions surfaced by implementation:

- **How does the worker pick which transport to use?** A `match` over `Channel` inside the job? A separate routing class? A registry the workers consult?
- **What does a transport adapter return?** An exception on failure? A boolean? A typed result? Who decides whether a failure is transient, permanent, or unrecoverable?
- **How does the SMS channel ship?** With a real provider stubbed behind it? With a no-op implementation that returns success? With nothing — and a 500 if a customer ever sends one?
- **Where does webhook URL resolution live?** The aggregate carries a destination id, not a URL — so something in the dispatch path has to translate id → URL. Is that the driver's job, the dispatcher's, or a separate port?
- **How is the registration of channel drivers wired?** Laravel tagged bindings? A factory? An explicit closure?

The cumulative shape of these answers is the dispatch architecture for the rest of Phase 1 — and the foundation that Day 7's retry policy and Day 9's webhook-destination feature both build on. Worth recording at decision time.

---

## Decisions

### 1. Channel dispatch is implemented as the Strategy pattern

A single `ChannelDriver` interface (in the Application layer) is implemented once per channel (in Infrastructure). A `ChannelDispatcher` looks up the right driver by `Channel` enum value and delegates to it. The job orchestrates the lifecycle (begin attempt, persist, dispatch, record outcome, persist, release events) but contains zero channel-specific logic.

**Why not a `match` expression in the job?** It works for three channels. The first time someone adds Slack as a fourth channel, they touch:
- the job (add a case),
- the test that exercises every case of the match,
- any other helper that branches on channel.
The strategy pattern moves all of that to a single new file plus one line of registration. The cost is that `ChannelDriver` is one extra file today; the saving is every future channel addition.

**Why not a "channel handler" service registry that grows imperatively at runtime?** Because the wiring is static — every supported channel is known at boot. A startup-time registry with an exhaustiveness check (decision §6) makes "this channel has no driver" a deployment-blocking error instead of a 500 at the first customer dispatch.

**Alternative considered:** Rely on Laravel notifications and channels (`Notification::via(['mail', 'webhook'])`). Rejected because Laravel's notification abstraction is built around per-recipient classes (one notification *class* per type of message), which is a fan-out shape. EventPulse is the inverse: one canonical message, one channel, many recipients. Bending Laravel's abstraction to fit would have been more code than implementing the strategy directly.

---

### 2. `ChannelDriver` returns a structured `DispatchOutcome` rather than throwing

The contract is "always return; never throw on a delivery failure." Each driver translates whatever its underlying transport surfaces (Symfony Mailer exceptions, HTTP responses, SMS provider error codes) into a `DispatchOutcome::success()` or `DispatchOutcome::failure(classification, reason)`.

**Why a structured outcome rather than an exception?** Three reasons:

1. **Failures are normal**, not exceptional. A 503 from a webhook receiver is the system working correctly — we expected the receiver might be down, so we modelled retry. Treating it as an exception would force the orchestration layer to catch-and-translate at the call site, which is exactly the boilerplate the strategy is meant to eliminate.

2. **Classification is the driver's expertise.** Whether a 503 is retryable, whether a 410 is "stop forever," whether a Symfony Mailer "550 No such user" is permanent — these are channel-specific judgments. Wrapping them in driver-specific exception subtypes and asking the orchestrator to reason about them by `instanceof` puts the same channel knowledge in two places. A `FailureClassification` enum in the outcome is the same information in the form the rest of the system needs.

3. **The aggregate's `recordFailure()` already takes a classification + reason.** The outcome maps onto it directly. Translating from a thrown exception would be lossy or require yet another adapter layer.

Drivers *may* still throw on programmer-error conditions (recipient/channel mismatch, missing required field) because those are not domain failures and the worker should fail loudly so the operator sees the bug.

**Alternative considered:** A sealed hierarchy (`DispatchOutcome\Success`, `DispatchOutcome\Failure`). PHP 8.4 has no `sealed` keyword; emulating it with `abstract class` plus `match (true) { $o instanceof Success => ... }` adds two files for no meaningful safety gain over a discriminated union with a private constructor. The named-constructor approach (`success()`, `failure()`) makes the two shapes structurally distinct: you cannot construct an outcome with `succeeded === true` and a non-null `classification`.

---

### 3. The driver receives a focused `DispatchRequest` DTO, not the aggregate

`ChannelDispatcher::dispatch(Notification, Attempt)` projects the aggregate and the in-progress attempt into a `DispatchRequest` containing only the fields a transport adapter needs: notification id, channel, recipient, payload, correlation id, attempt number.

**Why not pass the aggregate directly?** Three reasons:

1. **Drivers cannot accidentally mutate domain state.** The DTO is `readonly`; the aggregate's mutators (`recordSuccess`, `recordFailure`, `beginAttempt`) are not reachable from this surface. The driver is physically prevented from blurring the line between "did the I/O" and "the aggregate now reflects that I/O" — a separation ADR-0002 §6 establishes and that the strategy pattern relies on.

2. **Drivers don't import aggregate types they don't need.** An email driver has no business knowing about `Attempt`, `DeadLetterMark`, `NotificationStatus`, or domain events. Restricting the surface keeps each driver narrowly scoped to its transport concern.

3. **The contract is explicit.** When Day 9 adds per-destination signing metadata, it appears as a new field on `DispatchRequest` and on the relevant driver's call site. There is no silent coupling through "the driver happens to read another property of the aggregate" — adding a field is a deliberate, named change.

**Why include `attemptNumber` in the DTO?** Some transports want it on the wire (the spec's `X-EventPulse-Attempt` header). Day 5 emits that header; even if it didn't, building a DTO without it would force a widening change later.

---

### 4. Failure classification lives in the driver

Each driver maps its transport's failure modes to one of `Transient`, `Permanent`, `Unrecoverable`. The mapping is documented in the driver's docblock and inline at each rule.

**Why the driver and not a separate classifier class?** The mapping is channel-specific and small. Extracting it into a `WebhookFailureClassifier` plus an `EmailFailureClassifier` plus an `SmsFailureClassifier` would add three files for three fewer methods. Each driver's classifier-equivalent is a single private method (`classifyMailerException`, `classifyHttpStatus`) — short enough to read inline, focused enough to test inline.

**Why classify in the driver and not in the application layer (where retry policy already lives)?** Because classification depends on the transport's own error vocabulary. A 410 only means "stop" in HTTP-land; the SMTP equivalent is a 550 5.1.1; the SMS equivalent is whatever your provider's "destination doesn't exist" error code is. The application layer can decide *what to do* with a classification (retry? dead-letter immediately?), but it cannot produce the classification without re-implementing the driver's expertise.

**Webhook classification table** (specification §6.1, encoded in `WebhookChannelDriver::classifyHttpStatus`):
- `2xx`: success.
- `408`, `429`: transient (the receiver is asking us to back off, not telling us our request is wrong).
- `410`: permanent (the spec calls this out — receiver has explicitly told us to stop).
- Other `4xx`: permanent (consistent rejection; retry won't help).
- `5xx`, plus the unlikely `1xx`/`3xx`: transient (receiver-side issue may resolve).
- Connection/DNS/TLS errors: transient.

**Email classification table** (`EmailChannelDriver::classifyMailerException`):
- 5xx codes that signal permanent rejection (`550`, `551`, `553`, `554`) and substring signals like "invalid address", "mailbox unavailable", "no such user": permanent.
- Anything else: transient. (Conservative default — better to retry an unknown failure than silently lose a notification.)

The substring-based matching for SMTP is acknowledged as fragile; it exists because Laravel's `Mailer` contract does not type its exceptions, and individual transport implementations don't expose structured SMTP response codes. The class docblock notes this explicitly.

---

### 5. Webhook URL resolution is a separate Application port

`WebhookEndpointResolver` is an interface in `EventPulse\Application\Notification\Channel`. It takes a `WebhookRecipient` (which carries a destination id) and returns a `WebhookEndpoint` (which carries a URL today; URL + signing secret from Day 9).

**Why a separate port instead of the driver loading the destination itself?** Webhook destinations are a different aggregate (ADR-0002 §1) with their own identity, lifecycle, and access rules. Having the driver reach into a destination repository would couple two aggregates at the infrastructure layer in a way the domain explicitly forbids.

**Why an Application port and not a Domain port?** The resolver's only consumer is the driver — an infrastructure adapter. A domain port would be appropriate if a domain service needed to resolve URLs; none does. Recipients in the domain are by-id references; URL resolution is a transport concern.

**Why an interface ahead of a real implementation?** Day 5 ships the channel-strategy machinery; webhook destinations as an aggregate (with HMAC signing, encrypted secrets, operator endpoints) land on Day 9. The interface is defined now so `WebhookChannelDriver` can be written against its final shape. Day 9 substitutes a real `EloquentWebhookEndpointResolver` for the placeholder `UnconfiguredWebhookEndpointResolver` without the driver, the dispatcher, or any of the test scaffolding changing. This is the same pattern ADR-0007 (secrets management) establishes: introduce the port, ship a deliberately-limited default, replace it when the underlying capability is in scope.

**Resolution failures are pre-classified.** `WebhookEndpointResolutionException` carries a `FailureClassification` along with its message. The driver translates the exception into a `DispatchOutcome` directly; the classification is centralised on the exception's static factories (`notFound()` → Unrecoverable, `disabled()` → Permanent). A future change to "we want to treat disabled destinations as transient for X seconds" is a one-file edit.

---

### 6. `ChannelDispatcher` validates exhaustiveness at construction

The dispatcher's constructor accepts an `iterable<ChannelDriver>` and:
1. Indexes drivers by `Channel->value`.
2. Throws `LogicException` if two drivers claim the same channel.
3. Throws `LogicException` if any case of `Channel::cases()` has no driver.

**Why a boot-time check?** Because the alternative is a runtime `NoDriverForChannelException` at the moment a customer first uses the unhandled channel — exactly the kind of bug that ships to production unnoticed (we'd catch it for one channel in tests; we wouldn't notice it for a *different* channel that wasn't covered). Linear-scanning `Channel::cases()` once at boot turns the bug into a deployment failure.

**Why a `LogicException` and not a custom type?** The error is a programming/configuration mistake, not a runtime condition the application can recover from. `LogicException` is PHP's idiomatic signal for "you broke a contract that should never break in production"; promoting it to a custom class would imply this is a normal failure mode.

---

### 7. SMS ships as an honest-fail stub

`SmsChannelDriver` always returns `DispatchOutcome::failure(Permanent, ...)` with a reason that names the class and tells the operator how to replace it.

**Why a stub that fails rather than a stub that silently succeeds?** A success-stub would let SMS notifications progress through the system as if delivered, with no operator-visible signal that real customers receive nothing. A failure-stub fails predictably, surfaces in logs and the DLQ exactly as any other configuration problem would, and tells the operator the one thing they need to know: "to enable SMS, replace this driver."

**Why `Permanent` and not `Unrecoverable`?** `Unrecoverable` is the spec's classification for "the dependency is catastrophically gone" (a deleted destination). The SMS driver is in a known, intentional unconfigured state — equivalent to a 4xx from a real provider rejecting requests. `Permanent` dead-letters cleanly after the channel's max-attempt count, giving the operator the same failure shape as any other persistent provider rejection.

**Why ship at all?** Three reasons:
1. The `ChannelDispatcher` exhaustiveness check (decision §6) requires a driver for every `Channel` case. Removing SMS from the channel enum to skip it would be a much larger architectural backtrack.
2. The driver code itself is the integration point: a future Twilio (or MessageBird, or AWS SNS) integration replaces this single class. Having the file exist makes "where does the SMS provider go?" answerable by reading the codebase.
3. A failing SMS dispatch is fully end-to-end testable — the same persistence, logging, and DLQ paths exercise as a real provider rejection. Phase-1 confidence does not depend on a real SMS account.

This is not "stubbed code committed to main" — it is a deliberate, no-op-with-clear-intent implementation, which the project's "no dead code" rule explicitly permits as long as it is interface-conformant and documented.

---

### 8. The job orchestrates; the dispatcher delegates; the driver does I/O

`DispatchNotificationJob::handle()` is the lifecycle:

```
load aggregate
  → beginAttempt → save (claim before I/O)
  → channelDispatcher->dispatch (the I/O)
  → recordSuccess OR recordFailure → save
  → release pending events
```

The job knows about the aggregate and the persistence boundary. The dispatcher knows about the strategy lookup. The driver knows about the transport. No layer reaches across two hops.

**Why save *before* the dispatch?** So that if the worker crashes mid-I/O, the in-progress attempt is visible in the database. Operator triage is then a query, not a log archaeology exercise. The cost is one extra write per dispatch; the value is "the persistent state always tells the truth about what happened."

**Why `Day 5 placeholder` constants for `maxAttempts` and `retryAfter`?** Day 7 introduces `RetryPolicy` and `Backoff` as proper services with channel-specific rules (specification §5.2). Day 5 hard-codes `maxAttempts = 1` (any failure dead-letters immediately) and `retryAfter = now + 60s` (effectively unused at `maxAttempts = 1`). The interim is acceptable because:
- Failure outcomes are still correctly classified and observable.
- The hard-coded values are constants in the job, named with the `_DAY_5` suffix so the temporary nature is loud.
- Day 7 is a well-scoped change to one method body; no signature changes propagate.

---

### 9. Dispatcher registration is an explicit closure, not Laravel tagged bindings

`EventPulseServiceProvider::registerChannelDispatcher()` builds the dispatcher with an explicit list of three driver classes:

```php
$drivers = [
    $app->make(EmailChannelDriver::class),
    $app->make(WebhookChannelDriver::class),
    $app->make(SmsChannelDriver::class),
];
return new ChannelDispatcher($drivers);
```

**Why not Laravel tagged bindings (`$app->tag([...], 'eventpulse.channel-drivers')`)?** Tagged bindings work, but reading `$app->tagged('eventpulse.channel-drivers')` at the construction site does not tell you *which* drivers will be resolved — you have to grep for `->tag()` calls. An explicit closure lists all drivers in one place; the registration is self-documenting and the constructor's exhaustiveness check fires at the same source location it is specified.

**Why singletons for the drivers?** `Mailer` and `HttpFactory` are stateful (connection pools, default options) and benefit from reuse across dispatches in the same worker process. A new driver per dispatch would be wasteful; a global singleton makes lifecycle predictable.

---

## Consequences

### Positive

- **Adding a channel is mechanical.** A new `Channel` case, a new `ChannelDriver` implementation, one line in `registerChannelDispatcher()`. No central switch statement grows; no existing test class needs updating.

- **Drivers are independently testable.** Each driver's unit/integration tests fake one transport dependency (`Mailer`, `Http::fake()`) and verify the outcome shape. There is no need to bring the aggregate, the repository, or the queue into a driver test.

- **Failure classification is centralised per channel.** Each driver has one method that maps its transport's errors. Tweaking "treat 410 as transient instead" is a one-line edit with a single failing test to update.

- **Misconfiguration fails at boot.** A `Channel` case with no driver crashes startup with a clear error, not in production at the first dispatch.

- **The SMS driver is a real integration point, not a TODO.** A future SMS provider integration is a single-class replacement; no architectural rework, no schema change.

- **Day 7 (retry policy) and Day 9 (webhook destinations) plug in cleanly.** Day 7 replaces two constants in `DispatchNotificationJob`. Day 9 swaps the resolver binding and widens `WebhookEndpoint`. Neither touches the dispatcher, the driver interface, or the test scaffolding.

### Negative

- **Eight files for one capability.** `ChannelDriver`, `ChannelDispatcher`, `DispatchRequest`, `DispatchOutcome`, `WebhookEndpoint`, `WebhookEndpointResolver`, plus three drivers and an unconfigured-resolver placeholder. A small project with "send an email" as its only requirement could do this in two files. The cost is intentional: it makes every subsequent channel addition cheap, and it puts the application/infrastructure boundary in the right place from the start.

- **Adding a new channel takes two repository hops.** A new `Channel` enum case (Domain) and a new driver (Infrastructure) must land together. PR review can catch a missing case; the dispatcher's exhaustiveness check catches it at boot if review misses it.

- **Driver dependencies are wider than a "send email" function call.** Each driver injects its transport, a logger, and any channel-specific configuration. The constructor signatures are short, but they are not zero. The trade-off is testability: a driver with no constructor injection is a driver that is hard to fake.

- **Substring-based SMTP classification is fragile.** Mail-transport exception messages vary between Symfony Mailer transports and even between Symfony Mailer versions. A regression in classification would manifest as "permanent rejections being retried" or "transient failures being dead-lettered immediately." Mitigated by: explicit logging of `classification` on every failure (a regression appears in metrics before it appears in customer reports), and by the conservative default (unknown → transient → eventual dead-letter at the channel's max-attempt ceiling).

---

## Triggers to revisit

- **A fourth channel arrives.** Reassess whether the strategy is still the simplest correct shape, or whether per-channel feature flags / different routing rules / per-tenant channel configurations argue for a different abstraction.

- **The `Mailer` contract is replaced by a typed-exception transport** (e.g., a Postmark or SES-specific driver). At that point, the substring classification in `EmailChannelDriver` should be replaced by an exception-type-based dispatch, and this ADR should be updated to reflect the new mapping.

- **A second consumer of `ChannelDispatcher` appears** (e.g., a synchronous-dispatch endpoint for high-priority notifications). The current contract assumes a worker context; if it doesn't fit synchronous use, the interface may need to widen (e.g., a per-call timeout override).

- **Webhook receivers start using non-standard status codes** to signal retry semantics (e.g., a custom 599 for "back off"). The classification table is the lever; widen it with named constants and document the source.

- **The retry policy (Day 7) introduces channel-specific outcomes** that the driver should know about (e.g., "this channel does not support transient retries; classify all failures as permanent"). At that point, classification may need to move to a strategy-aware classifier rather than a per-driver method.
