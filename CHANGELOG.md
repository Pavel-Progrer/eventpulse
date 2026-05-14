# Changelog

All notable changes to EventPulse are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Narrative release notes (architecture commentary, migration guidance, rationale) live in [`docs/release-notes/`](./docs/release-notes/) — this file is the machine-friendly per-version diff.

---

## [Unreleased]

### Planned for v0.2.0 (Phase 2)

- Multi-stage Dockerfile targeting a distroless final image (~80MB)
- `docker-compose.yml` matching the production image
- GitHub Actions CI/CD: Psalm, PHPStan, Pint, Roave Security Advisories, Trivy, gitleaks
- ADR-0006 (distroless rationale) — *will be appended as ADR-0010 in repo sequence*
- ADR-0008 (secrets management for production) — *expanded scope*
- `docs/DEPLOYMENT.md` runbook
- `SECURITY.md` threat model

---

## [0.1.0] — 2026-05-14

First complete release. Phase 1 of the 28-day plan delivered.

### Added

#### Public API surface (versioned under `/api/v1/`)

- `POST /notifications` — submit a notification (async accept, returns `202`).
  - Idempotency-Key header required; duplicates within 24h return the original response (`200`) or `409` on body mismatch.
  - Correlation-Id header passes through to logs; auto-generated if absent.
  - Per-API-key rate limiting with separate write/read buckets.
- `GET /notifications` — cursor-paginated list, filterable by `status` and `channel`.
- `GET /notifications/{id}` — full notification + attempt history.
- `GET /dlq` — list dead-lettered notifications, filterable by `reason`, `channel`, `created_after`, `created_before`. Cursor-paginated (next-cursor only — no total count).
- `GET /dlq/{id}` — detailed dead-letter entry with attempt history and last error.
- `POST /dlq/{id}/replay` — idempotent replay; creates a new notification linked back via `replay_of_id`. `409 ALREADY_REPLAYED` on second replay.
- `DELETE /dlq/{id}` — idempotent discard; removes from DLQ listing, notification preserved for audit.
- `POST /webhook-destinations` — register a destination, returns plaintext secret exactly once.
- `GET /webhook-destinations` — list destinations for the authenticated key.
- `DELETE /webhook-destinations/{id}` — soft-disable a destination (history preserved).
- `GET /health` — liveness probe (no auth).
- `GET /health/detailed` — readiness probe with DB + Redis checks (no auth, IP-throttled at 60/min).
- Standardized error envelope (`{ error: { code, message, details } }`) on every failure path.

#### Domain model

- `Notification` aggregate with the full lifecycle: `queued` → `processing` → `dispatched` / `failed` / `dead_lettered`, where a transient failure from `processing` returns to `queued` for retry.
- `Notification::transitionTo()` enforces transitions via `NotificationStatus::canTransitionTo()`. Dead-lettering bypasses the enum check because it carries a domain invariant (≥1 failed attempt) the enum cannot know.
- `Notification::request()` named constructor for new aggregates; `Notification::reconstitute()` for hydration from persistence (no events raised).
- Pending domain events collected in `$pendingEvents`, released by the application layer via `pullPendingEvents()` — never dispatched inline.
- `WebhookDestination` aggregate with `register` / `disable` lifecycle; secret is encrypted at rest, never returned after registration.
- `ApiKey` aggregate with Argon2id-hashed secret and scope-based authorization.
- Value objects: `NotificationId`, `CorrelationId`, `IdempotencyKey`, `AttemptNumber`, `EmailRecipient`, `WebhookRecipient`, `SmsRecipient`, `WebhookEndpoint`, `WebhookDestinationId`.
- Enums with behavior: `Channel`, `NotificationStatus`, `Priority`, `FailureClassification`, `WebhookDestinationStatus`.
- Domain events: `NotificationRequested`, `NotificationDispatchAttempted`, `NotificationDispatched`, `NotificationDispatchFailed`, `NotificationScheduledForRetry`, `NotificationDeadLettered`, `NotificationReplayed`, `WebhookDestinationRegistered`, `WebhookDestinationDisabled`.

#### Channel dispatch (strategy pattern)

- `ChannelDispatcher` selects driver by `Channel` enum; contains zero channel-specific logic.
- `EmailChannelDriver` — real, via Laravel's `Mailer` interface.
- `WebhookChannelDriver` — real, with HMAC-SHA256 outbound signing.
- `SmsChannelDriver` — stub with a clear contract for Phase-3+ replacement.
- `DispatchOutcome` value object: `success`, `transient_failure`, `permanent_failure` with optional `Retry-After` honoring.

#### Retry and dead-lettering

- Exponential backoff with full jitter, configurable per channel (`config/eventpulse.php`).
- Status-aware failure classification: 4xx (except 408/429) → permanent; 5xx → transient; network/timeout → transient; webhook `Retry-After` honored.
- DLQ landing on max-attempts exhaustion or explicit permanent classification.

#### Idempotency

- Composite unique index on `(api_key_id, idempotency_key)`; first writer wins.
- Duplicate same-body → `200` with original response. Duplicate different-body → `409 IDEMPOTENCY_CONFLICT`.
- Same key across different API keys does not collide.

#### Webhook signing

- HMAC-SHA256 over `{timestamp}.{json_body}`; headers `X-EventPulse-Timestamp` and `X-EventPulse-Signature: sha256=...`.
- 5-minute timestamp window for replay protection.
- Reference verification snippet in README and OpenAPI spec.

#### Persistence

- PostgreSQL 17 schema: `notifications`, `attempts`, `dead_letter_marks`, `api_keys`, `webhook_destinations`.
- `notifications.idempotency_key` composite-unique with `api_key_id`.
- `webhook_destinations.secret` encrypted at rest via Laravel `Crypt`.
- `api_keys.secret_hash` Argon2id; plaintext never persisted.
- All migrations idempotent; no destructive operations.
- `EloquentNotificationRepository::save()` wraps the notification + attempts + dead-letter-mark write in a single transaction.

#### Observability

- Structured JSON logging across the dispatch flow with correlation IDs threaded from HTTP request to terminal disposition.
- `StructuredLogDomainEventDispatcher` writes every domain event with full context (notification id, channel, attempt count, last error).
- Dedicated `eventpulse` log channel configured in `config/logging.php`.

#### Authentication and authorization

- Bearer-token API keys via `AuthenticateApiKey` middleware.
- Per-key scopes enforced by `RequireScope` middleware; missing scope returns `403`, missing auth returns `401`.
- Scopes: `notifications:write`, `notifications:read`, `dlq:read`, `dlq:replay`.

#### Rate limiting

- `ThrottleApiRequests` middleware: per-API-key, separate write and read buckets; write-side override-per-key via `api_keys.rate_limit_per_minute` column (null = system default).
- `ThrottleIpRequests` middleware: IP-bucketed for unauthenticated endpoints (60/min).
- Default limits: 100 writes/min, 600 reads/min.

#### Testing infrastructure

- Three-layer pyramid: `tests/EventPulse/Unit/` (framework-free), `tests/Feature/` (full HTTP round-trips), integration coverage for adapters.
- Test doubles inventory documented in `docs/testing-strategy.md`.
- `NotificationMother`, `NotificationFactory`, `DlqEntryBuilder` for fluent test setup.
- `InMemory*` repositories and queue implementations for unit tests; never mock domain objects.
- Parallel test execution; coverage via pcov on CI.

#### Documentation

- `README.md` — full project overview with Mermaid architecture diagrams.
- `docs/domain.md` — domain model reference (ubiquitous language, aggregates, lifecycle, invariants, events).
- `docs/architecture.md` — layered architecture overview and request flow.
- `docs/testing-strategy.md` — pyramid layers, test doubles, coverage targets.
- `docs/release-notes/v0.1.0.md` — narrative release notes for this tag.
- `openapi.yaml` — complete OpenAPI 3.1 specification served at `/api/docs`.
- Architecture Decision Records 0001 through 0009.

### Tooling

- PHP 8.4 with strict types declared in every file.
- Laravel 12.x.
- Laravel Pint for code style.
- PHPUnit attributes (`#[Test]`, `#[DataProvider]`) over annotations.
- Docker Compose for local development parity (Postgres 17, Redis 7, Mailpit).
- GitHub Actions CI skeleton running unit + feature suites (`.github/workflows/ci.yml`).

### Known limitations

These are not bugs — they are deliberate scope boundaries for Phase 1, all reasoned in [ADR-0001](./docs/adr/0001-scope-and-exclusions.md) or the relevant downstream ADR.

- No production Dockerfile yet — Phase 2 deliverable.
- No Psalm or PHPStan configuration in CI yet — skeletons are in place, baselines ship with Phase 2.
- SMS channel is a stub. The driver contract is real; replacement with a live provider is a single class change.
- Inbound webhook verification (callbacks **to** EventPulse) is documented in ADR-0008 but not implemented — there are no inbound webhooks in the Phase 1 surface.
- No semantic search or LLM features — Phase 3.

### Security

- Argon2id hashing for API key secrets.
- Webhook destination secrets encrypted at rest via Laravel `Crypt` (AES-256-CBC with the application key).
- HMAC-SHA256 signing on outbound webhooks with replay-resistant timestamp window.
- Standardized error envelope leaks no internal state on `5xx` responses.
- Rate limiting on every authenticated endpoint plus IP throttling on public probes.
- Test coverage explicitly asserts that error envelopes do not contain stack traces, file paths, or secret material.

---

[Unreleased]: https://github.com/Pavel-Progrer/eventpulse/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Pavel-Progrer/eventpulse/releases/tag/v0.1.0
