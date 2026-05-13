# Testing strategy

EventPulse uses a three-layer testing pyramid that maps to the project's
architecture: domain-only unit tests at the base, application-layer integration
tests in the middle, and full HTTP round-trip feature tests at the top.

---

## Pyramid layers

### Unit tests — `tests/EventPulse/Unit/`

Pure PHP. No Laravel bootstrap. No database. No queue. Fast (<5ms per test).

The unit layer covers every domain invariant: state machine transitions,
value object validation, aggregate event collection, domain service logic. If
something can go wrong inside `src/EventPulse/Domain/` without involving
persistence or HTTP, there is a unit test for it.

**What is mocked here:** nothing — these tests run against real objects in
memory. `InMemoryNotificationRepository`, `InMemoryNotificationDispatchQueue`,
`FixedClock`, `NullDomainEventDispatcher`, and `NotificationMother` are the
test doubles; none is a mock.

**What is not tested here:** Eloquent queries, queue dispatch, HTTP
serialisation, middleware chains, third-party HTTP calls.

Run in isolation:
```
php artisan test --testsuite=Unit --parallel
```

### Feature tests — `tests/Feature/`

Full HTTP round-trips. Laravel boots completely. Migrations run against the
test database inside a transaction that is rolled back after each test
(`RefreshDatabase`). Queue jobs are intercepted with `Bus::fake()` so workers
never execute.

The feature layer covers every API endpoint: the happy path, every documented
error (401, 403, 404, 409, 422, 429), idempotency behaviour, tenant isolation,
and rate-limiting. One feature test per endpoint group (e.g.
`NotificationsReadTest`, `DlqReplayDiscardTest`).

**What is mocked here:** the queue (Bus::fake()), the mailer (log driver in
`.env.testing`), and webhook HTTP calls (no live outbound requests).

**What is not tested here:** exact timing, real queue processing, production
LLM responses.

Run in isolation:
```
php artisan test --testsuite=Feature --parallel
```

---

## Test doubles used

| Double | Location | Purpose |
|--------|----------|---------|
| `InMemoryNotificationRepository` | `tests/EventPulse/Unit/Application/Support/` | Hash-map repo for unit tests; no DB |
| `InMemoryNotificationDispatchQueue` | `tests/EventPulse/Unit/Application/Support/` | Records enqueued dispatch requests in memory |
| `InMemoryDeadLetteredNotificationsRepository` | `tests/EventPulse/Unit/Application/Support/` | DLQ read-model for unit tests |
| `InMemoryWebhookEndpointResolver` | `tests/EventPulse/Unit/Application/Support/` | Resolves webhook endpoints in memory |
| `InMemoryWebhookDestinationRepository` | `tests/EventPulse/Unit/Application/Support/` | Webhook destination store for unit tests |
| `FixedClock` | `tests/EventPulse/Unit/Application/Support/` | Returns a deterministic `DateTimeImmutable` |
| `NullDomainEventDispatcher` | `src/EventPulse/Application/Shared/` | No-op event dispatcher for tests that don't care about events |
| `FakeChannelDriver` | `tests/EventPulse/Unit/Application/Support/` | Returns a configurable `DispatchOutcome` without I/O |
| `RecordingLogger` | `tests/EventPulse/Unit/Application/Support/` | Captures log calls for assertion |
| `RecordingMailer` | `tests/EventPulse/Unit/Application/Support/` | Captures mail calls for assertion |
| `NotificationMother` | `tests/EventPulse/Unit/Domain/Notification/Support/` | Object Mother for domain aggregates |
| `NotificationFactory` | `tests/Support/Factories/` | Persists a notification through the real write path for feature tests |
| `DlqEntryBuilder` | `tests/Support/Factories/` | Fluent builder for dead-lettered notifications in feature tests |

**Rule:** mock at the boundary. Never mock domain objects or application
services. The test doubles above are all either in-memory implementations of
production interfaces or helpers that build real aggregates.

---

## Coverage expectations

| Layer | Target | Notes |
|-------|--------|-------|
| Domain (aggregates, VOs, events) | 100% meaningful | Every invariant has a failing-case test, not just line coverage |
| Application (handlers, query handlers) | Every use case | At minimum one happy-path and one failure-mode test |
| Infrastructure (channel adapters) | Key contract tests | Mocked at the HTTP boundary; no live external calls in CI |
| HTTP (controllers, middleware) | Feature tests cover all endpoints | Auth, scope, validation, idempotency per endpoint |

---

## Running the full suite

```bash
# All tests, parallel
php artisan test --parallel

# Unit tests only (fastest — no DB required)
php artisan test --testsuite=Unit --parallel

# Feature tests only (requires DB + Redis)
php artisan test --testsuite=Feature --parallel

# With coverage (requires pcov or xdebug)
php artisan test --coverage --min=80
```

---

## What is not in this test suite

- **Live external calls.** No test sends real email, calls a real webhook, or
  hits a real SMS provider. Adapters are tested with Laravel's `Mail::fake()`,
  `Http::fake()`, and stub implementations.
- **Load or performance tests.** Not in CI. The spec's p95/p99 targets are
  validated manually against a staging environment.
- **Contract tests.** The OpenAPI spec is the contract. Feature tests assert
  response shapes but do not run a formal contract test tool (Dredd, Schemathesis)
  in this phase. That is a Phase 2 addition.
- **Mutation testing.** Not in Phase 1. Pest's mutation plugin is the
  natural fit and is listed in the Phase 2 CI hardening backlog.
