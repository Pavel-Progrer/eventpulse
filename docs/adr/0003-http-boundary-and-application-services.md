# ADR-0003: HTTP Boundary, Application Services, and Command DTOs

- **Status:** Accepted
- **Date:** 2026-04-22
- **Deciders:** Pavel Rodin
- **Related:** ADR-0001 (scope and exclusions), ADR-0002 (domain model structure and aggregate boundaries)

---

## Context

Day 2 produced the domain layer: aggregates, value objects, enums, domain events. Day 3 was the first day where any of that became reachable from the outside world — the `POST /api/v1/notifications` endpoint had to land, with a feature test that exercises the full HTTP-to-persistence path.

Several decisions surfaced during implementation that the existing ADRs do not cover. The architecture rules in the project README (and the `claude.md` instructions backing it) say "controllers thin, business logic in application services, domain stays framework-free" — but those are slogans. The concrete questions that arose are:

- **Where does the application service live, and what is it called?** A method on the controller? A method on an aggregate? A separate handler class? Some flavour of command bus?
- **How does the controller pass data to the application service?** The validated `FormRequest`? An `array`? A typed DTO?
- **How much validation does the FormRequest do, and how much does the domain do?** The OpenAPI spec describes the API field shapes; the domain has its own self-defending invariants. Where is the line?
- **Does the API contract use the same field names as the domain?** The OpenAPI uses `body_text` / `body_html`; the domain uses `text` / `html`. Where does the rename happen?
- **What does the create response look like — a full Notification or a thin acceptance receipt?**
- **Where does the repository interface live?** Domain or application?
- **How is "now" injected so handlers stay testable?**

This ADR records the answers, the alternatives considered, and the reasoning. None of these decisions individually is large; the cumulative effect is the shape of every endpoint that follows in Phase 1, which is why the choices are worth recording at this stage rather than after they've been made eight times.

---

## Decisions

### 1. The application service is a single-method handler class, named after the use case

`SubmitNotificationHandler::__invoke(SubmitNotificationCommand): SubmitNotificationResult`. One class per use case, one public method (`__invoke`), invoked by the controller.

**Why not a method on the controller?** The controller is HTTP-coupled — it depends on `Illuminate\Http\Request`, on the FormRequest's validation pipeline, on Laravel's container. Putting the business logic there makes it untestable from any non-HTTP context (Artisan command, queue replay, internal call from another service component). The handler is the part of the use case that has a chance of being reused.

**Why not a method on the aggregate?** The aggregate already has `Notification::request()` — and that *is* the method that does the domain work. The handler exists for everything that surrounds the domain work: identifier generation, clock invocation, repository persistence, event drainage. None of those belong on the aggregate. The handler is the right place to coordinate them.

**Why not a command bus (Tactician, Symfony Messenger)?** Both add a layer of indirection — handlers are resolved by command class, dispatched through middleware, possibly queued. EventPulse has a small number of synchronous use cases (submit, replay, list, cancel) and one asynchronous one (the dispatch itself, which is already a Laravel queue job). A command bus's value is in cross-cutting middleware (logging, transactions, authorization) — and Laravel's HTTP middleware stack already provides those, applied at the route level. Adding a second middleware system would be redundant. A handler called directly by the controller is the simplest thing that correctly solves the problem.

**Why `__invoke` and not a named method?** Single-method classes are conventionally invokable in modern PHP. The convention saves a bikeshed about naming the method (`handle`? `execute`? `submit`?) and makes the call site read as `($this->handler)($command)`, which mirrors function semantics. The class name carries the intent — `SubmitNotificationHandler` — so the method name is redundant.

---

### 2. A typed Command DTO sits between FormRequest and Handler

`SubmitNotificationCommand` is a `final readonly class` with public properties for each input the use case needs. The controller constructs it from the validated FormRequest data; the handler accepts it directly.

**Why not pass the FormRequest into the handler?** The FormRequest is a Laravel class. Accepting it in the handler couples the handler to Laravel and to HTTP, which defeats the point of having a separate application layer.

**Why not pass a plain `array`?** An array provides no type safety, no IDE completion, no static-analysis surface. Future refactors that change the use case's input must touch every call site by trial and error. A typed DTO is roughly the same number of lines and makes refactor-safety free.

**Why a `readonly` class instead of a constructor with arguments?** Both are equivalent in shape, but `readonly` declares intent: this object is not mutated after construction, defensive copies are unnecessary, and the property accessors are the API. PHP 8.4 makes `final readonly class` a one-line declaration with property-promoted public fields, which is the smallest expression of "DTO" available in the language.

**The DTO already carries domain enums.** `SubmitNotificationCommand::$channel` is a `Channel`, not a `string`. The string-to-enum resolution happens in the controller, where the validated string is known to be one of the enum's cases. This shifts as much "is this input well-formed?" work to the boundary as possible, leaving the handler to deal only with semantic operations.

---

### 3. The FormRequest validates structure; the domain validates invariants — and the line is non-negotiable

The FormRequest enforces:
- Headers present and lexically valid (`Idempotency-Key` between 8 and 128 chars; `X-Correlation-ID` matching its pattern).
- Body field presence and primitive types (`channel` is one of three strings; `payload` is an array).
- Channel-conditional payload field rules (SMS payload has a `body`; email payload has a `subject` and at least one of `body_text` / `body_html`).

The domain enforces:
- Recipient format precision (RFC-5321 email, E.164 phone, UUID v4 destination id).
- SMS body length ≤ 1600 characters.
- Webhook payload non-emptiness.
- Recipient/channel consistency (the domain rejects an SMS recipient on an email notification, regardless of what the FormRequest accepted).
- Status transitions (not relevant on Day 3, but the same handler will route through `Notification::transitionTo()` on later days).

**The duplication looks worse than it is.** The FormRequest checks `'recipient' => 'string|min:1|max:320'`. The domain `EmailRecipient::fromString()` checks the same string with `filter_var(..., FILTER_VALIDATE_EMAIL)`. These are not the same check — they are two layers of an onion. The outer layer ("is this a string at all?") is the FormRequest's job because it can produce a structured error response with field-level details. The inner layer ("is this a valid email address?") is the domain's job because the aggregate must defend itself against incorrect construction from any path, not just the HTTP one.

**The exception handler unifies them at the HTTP boundary.** When the domain's `EmailRecipient::fromString()` throws `InvalidArgumentException`, the registered handler maps it to a 422 with code `VALIDATION_ERROR` and the exception's message. The caller sees a 422 in both cases — the structured `details.fields[]` payload differs slightly (the FormRequest's path-level errors vs the domain's single-message error), but both are 422s.

**Alternative considered:** Putting *all* validation in the FormRequest, including format precision. Rejected because it pushes domain knowledge into the HTTP layer. A future Artisan-command path that bypasses the FormRequest would silently allow malformed recipients; the bug would surface only when the dispatch worker tried to send the email. The aggregate must be self-defending (per ADR-0002 §5).

**Alternative considered:** Putting *all* validation in the domain, with no FormRequest at all. Rejected because Laravel's validator produces the field-level error response shape that the OpenAPI specifies. Recreating that from `try/catch` blocks in the controller would mean reimplementing what a well-vetted framework feature already does, badly.

---

### 4. The create response is a thin acceptance receipt — not a full Notification

`POST /api/v1/notifications` returns:

```json
{
  "id": "...",
  "status": "queued",
  "correlation_id": "...",
  "created_at": "...",
  "_links": { "self": "/api/v1/notifications/..." }
}
```

It does not return the recipient, the payload, the priority, or any attempt history. A separate endpoint (`GET /api/v1/notifications/{id}`, Day 4+) returns the full status view.

**Why the split?** The create response is a *receipt* — confirmation that the system accepted the request and an opaque handle the caller can use to inquire about it later. Receipts and status views have different lifecycles: the receipt's contents are knowable at acceptance time, the status changes asynchronously as workers pick the notification up. Mixing them encourages clients to treat the create response as authoritative about delivery state, which it is not. A 202 with `status: queued` is honest; a 202 with the full Notification (including an empty `attempts: []` array that clients might begin polling against) is misleading.

**Why a separate `NotificationAcceptedResource` class instead of a single `NotificationResource` with conditional fields?** The two response shapes belong to two different operations with two different consumers' expectations. Conditional resources end up as long classes with many `when()` guards; a separate class per shape is cleaner, name-checked, and easier to evolve.

**The wrapped object is `SubmitNotificationResult`, not the aggregate or an Eloquent row.** The result DTO is the application layer's output contract. Wrapping that in a JSON resource means the HTTP shape is decoupled from how the data is persisted (Eloquent column names) and from how the domain models the lifecycle (aggregate properties). Renames in any of those three places do not cascade through the others.

---

### 5. Repository interface in Domain, implementation in Infrastructure

`EventPulse\Domain\Notification\Repository\NotificationRepository` is the interface. `EventPulse\Infrastructure\Notification\Persistence\EloquentNotificationRepository` is the implementation.

**Why not put the interface in `Application`?** Because the interface is a *property of the aggregate*: it describes the contract by which the aggregate is persisted. The domain is the right place for it because the contract's shape is domain-driven (it accepts and returns aggregates, value objects, and identifiers). An interface in the Application layer would invert the dependency: the application would own the rule for how the domain is stored, which puts domain knowledge in the wrong place.

**Why not put it on the aggregate itself (e.g., `Notification::save()`)?** Active-record patterns conflate identity, behaviour, and persistence in the same object. The aggregate's job is to enforce invariants. Persistence is a separate concern that should be replaceable (Eloquent today; Doctrine, plain SQL, or a remote service tomorrow) without rewriting the aggregate.

**The implementation lives in Infrastructure** for the obvious reason: it depends on Eloquent. The container binding (`EventPulseServiceProvider::$bindings`) wires interface to implementation, which is the same pattern the project will follow for the channel dispatcher (Day 5), the LLM provider (Day 21+), and any other ports that have multiple potential adapters.

---

### 6. The `Clock` abstraction is in Application, not Domain

The aggregate accepts `DateTimeImmutable $now` as a parameter. Producing that value is the application layer's job. A `Clock` interface (`EventPulse\Application\Shared\Clock`) abstracts it; `SystemClock` is the production implementation; `FixedClock` is the test double.

**Why not let the aggregate call `new DateTimeImmutable('now')`?** Because the aggregate would then be non-deterministic, slow to test (each test would need a millisecond-tolerant assertion), and untestable for time-dependent behaviour. The pattern of the aggregate accepting time as a parameter and the application layer producing it is established by the existing domain code — this ADR formalises it for the application layer's side of the contract.

**Why not put the Clock interface in Domain?** The domain does not need a clock — it needs a timestamp. The interface for *producing* a timestamp is one level above the domain. Putting it in `Domain/Shared` would suggest the domain calls into it, which it does not.

**Why force UTC in `SystemClock::now()`?** Every persistence column is `TIMESTAMPTZ` (effectively UTC under the hood) and every log entry is UTC-formatted. A clock that returns wall-clock-with-server-timezone would silently shift values across the persistence boundary. Forcing UTC at the source makes the convention explicit at one point instead of relying on every call site to remember.

---

### 7. The `body_text` / `body_html` → `text` / `html` mapping happens in the controller

The OpenAPI request schema specifies `body_text` and `body_html` for email payloads; `NotificationPayload::validateEmail()` checks for `text` and `html`. The controller's `mapPayloadForDomain()` rewrites the keys before constructing the command.

**Why not align the domain with the API and rename `text`/`html` → `body_text`/`body_html`?** The domain's keys are already shorter and match the SMS payload's `body` convention internally — the API names disambiguate them at the public surface but at the cost of two-word keys everywhere. Both names are defensible; the choice is made on the principle that the public API contract and the internal domain are allowed to diverge, and the boundary is the right place for the translation.

**Why not put the mapping in the Command DTO's constructor?** The Command DTO is a passive carrier — it holds the data and types the data, but does not transform it. The controller is where HTTP-shaped input becomes domain-shaped input, and the mapping function is a one-line method whose existence is itself documentation that the rename is intentional.

**Implication:** Adding a new field to either side requires adding the mapping. This is acceptable: the email payload schema is small, stable, and changes infrequently. A future channel with a more complex shape might justify a dedicated mapper class — at this size the inline method is the right cost.

---

### 8. The `wasIdempotentReplay` flag is in the Result on Day 3, even though dedup ships on Day 4

`SubmitNotificationResult::$wasIdempotentReplay` exists and is always `false` in Day 3's handler. The controller already checks it and returns 200 vs 202 accordingly.

**Why pre-wire?** Day 4 will add the dedup check (`$repository->findByIdempotencyKey(...)`) at the top of the handler. With the field already in place, the change is a single block of code in the handler — no controller, no resource, no test changes. The 200/202 split lives on the HTTP side already, where it belongs.

This is a small example of a recurring pattern: code that is one-line dead today is acceptable when it lets a future feature land cleanly without touching three layers. Code that adds dependencies, branches, or runtime overhead for a future feature is not (see ADR-0001 on extension via deliberate revisiting).

---

## Consequences

### Positive

- **Use cases are runnable from any context.** A future Artisan `notifications:submit` command, a queue replay, or an integration test can call `SubmitNotificationHandler` directly. The HTTP layer is one of several callers.
- **Validation has two clean layers, both tested.** The FormRequest is exercised by feature tests; the domain is exercised by unit tests. A failure can be localised quickly.
- **The aggregate stays self-defending.** Day 3 added one path *into* the aggregate (`Notification::request()` from the handler) without weakening any of its invariants.
- **The HTTP response shape is decoupled from persistence and from domain modelling.** All three can evolve independently.
- **The wiring is uniform across the project.** Channel dispatch (Day 5), LLM provider chain (Day 21+), and any other port-and-adapter pair will follow the same pattern: interface in domain or application, implementation in infrastructure, binding in `EventPulseServiceProvider`.

### Negative

- **Five concepts were introduced for one endpoint.** Command, Result, Handler, Resource, Mapping function. For an engineer skimming the codebase quickly, the layers can feel like over-engineering for a single POST. The mitigation is consistency: every endpoint after this one will have the same five concepts, so the cost is amortised across the full Phase 1 surface.
- **The `body_text`/`body_html` mapping is an explicit step that future maintainers must remember.** Adding a field to the OpenAPI schema and the FormRequest is not enough — the mapper has to be updated too. This is a known sharp edge; a comment in the mapping method points to it.
- **The exception handler's mapping table grows linearly with new domain exception types.** Day 4 will add idempotency conflict handling (409 from `DuplicateIdempotencyKeyException`); Day 5 will add channel dispatch errors. This is acceptable — exception handling is the place where mappings are *supposed* to be enumerated — but the file will grow.
- **`wasIdempotentReplay` is dead code on Day 3.** It is intentional, documented, and removed within a week, but a strict "no dead code" reading would flag it.

---

## Triggers to revisit

- **More than ~6 application handlers.** At that point a command bus's middleware-pipeline value (transactions, logging, authorization filters all in one declaration) starts to outweigh its indirection cost. Until then, direct controller-to-handler calls are simpler.
- **The FormRequest / domain validation overlap visibly drifts.** If a field's rule starts diverging across the two layers (FormRequest says one thing, domain says another), either the boundary is wrong or one side has a bug. This is the canary that the layers need re-examining.
- **A non-HTTP entry point fails because it bypassed shape validation.** This is a signal that the FormRequest carried more than shape rules — it had domain rules that should have been pushed down. Either restore the rule to the domain, or accept that the non-HTTP path needs its own validation step.
- **The thin-receipt response stops fitting some client's needs.** If clients consistently call create + immediately call get, that's a hint that one round trip would serve them better. Phase 3's multi-channel endpoint (`POST /notifications/multi`) already returns more — that response shape is the precedent if the single-channel one is ever expanded.
