# EventPulse

> An event-driven notification dispatch service. Boring, reliable, observable.

[![Build Status](https://github.com/Pavel-Progrer/eventpulse/actions/workflows/ci.yml/badge.svg)](https://github.com/Pavel-Progrer/eventpulse/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.4-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](./LICENSE.md)

> 🚧 **Status:** In active development. First complete release is tagged [`v0.1.0`](#roadmap) (Phase 1, target ~May 1 2026). See the [Roadmap](#roadmap) for phase milestones.

EventPulse is a Laravel service for dispatching notifications across channels — email, webhook, SMS — with the production qualities these systems actually need: idempotency, retries with backoff, dead-letter handling, structured observability, and a minimal attack surface.

It is a portfolio project, built to the standards of a system I would put into production for a client tomorrow. Every non-obvious architectural decision is documented as an [Architecture Decision Record](./docs/adr/). The exclusions are as deliberate as the inclusions — see [ADR-0001](./docs/adr/0001-scope-and-exclusions.md) for what EventPulse is and isn't, and why.

---

## What it does

- Accepts notification dispatch requests via an authenticated REST API
- Dispatches through pluggable channels (email, webhook, SMS stub) via a strategy-based dispatcher
- Guarantees **at-least-once delivery** with idempotency keys ensuring a request is never processed twice
- Retries transient failures with **exponential backoff and jitter**
- Dead-letters permanent failures for inspection and replay
- Signs outbound webhooks (HMAC) and verifies inbound webhook callbacks
- *(Phase 3)* Provides **semantic search over event history** using pgvector
- *(Phase 3)* Generates **channel-appropriate notification variants** from a canonical payload via LLM, with caching and fallback

## What it deliberately doesn't do

No Kubernetes. No Kafka. No frontend. No event sourcing. No multi-service decomposition. No OAuth2.

Each omission is reasoned in [ADR-0001](./docs/adr/0001-scope-and-exclusions.md). The exclusions are the architectural thesis of the project — they are what the code does not contain by design, not what it hasn't gotten to yet.

---

## Architecture

*An up-to-date Mermaid diagram will appear here with the `v0.1.0` tag.*

Four layers, explicit boundaries:

- **HTTP boundary** — controllers, form requests, API resources. Thin; delegates to application services. No domain logic leaks through.
- **Application layer** — orchestrates domain operations and handles cross-cutting concerns (idempotency checks, correlation IDs, transaction boundaries).
- **Domain layer** — aggregates (`Notification`), value objects, domain events. Framework-agnostic; unit-tested without Laravel.
- **Infrastructure layer** — Laravel queues, Eloquent repositories, channel adapters, LLM clients, external service integrations.

Detailed design notes and per-decision reasoning live in [`docs/`](./docs/).

---

## Running locally

**Prerequisites:** Docker, Docker Compose.

```bash
git clone https://github.com/Pavel-Progrer/eventpulse.git
cd eventpulse
cp .env.example .env
docker-compose up -d
docker-compose exec php-fpm php artisan key:generate
docker-compose exec php-fpm php artisan migrate
```

The API will be available at `http://localhost:8080`. An OpenAPI spec is published at `/api/docs` from `v0.1.0` onward.

### Environment variables

Configuration is split across two files, both gitignored, both with committed `.example` counterparts:

- **`.env`** — local development. Copy from `.env.example` after cloning.
- **`.env.testing`** — overrides applied when `APP_ENV=testing` (set globally by `phpunit.xml`). Covered in [Setting up the test environment](#setting-up-the-test-environment) below.

Hostnames in both files refer to **Docker service names** (`postgres`, `redis`, `mailpit`) — these resolve inside the `eventpulse-net` bridge network defined by `docker-compose.yaml`. If you ever run `php artisan` from your host shell rather than through `docker-compose exec`, swap them for `127.0.0.1`.

| Variable | `.env` (dev) | `.env.testing` | Notes |
|---|---|---|---|
| `APP_ENV` | `local` | `testing` | Drives which env file Laravel loads. |
| `APP_KEY` | (generated) | (generated) | Encrypts `webhook_destinations.secret`; see [ADR-0007](./docs/adr/0007-secrets-management.md). |
| `DB_CONNECTION` | `pgsql` | `pgsql` | Required — migrations use `jsonb` and `TIMESTAMPTZ`. |
| `DB_HOST` | `postgres` | `postgres` | Compose service name. |
| `DB_DATABASE` | `eventpulse` | `eventpulse_test` | Separate databases prevent `RefreshDatabase` from erasing dev data. |
| `REDIS_HOST` | `redis` | `redis` | Queue backend + idempotency store + cache. |
| `QUEUE_CONNECTION` | `redis` | `sync` | Tests run jobs synchronously; nothing is enqueued. |
| `CACHE_STORE` | `redis` | `array` | Tests use in-memory cache; nothing leaks between runs. |
| `MAIL_MAILER` | `smtp` | `array` | Dev mail goes to Mailpit (UI at `http://localhost:8025`); tests collect mail in memory. |
| `ANTHROPIC_API_KEY` | empty until Phase 3 | empty | LLM provider; see ADR-0010. |
| `OPENAI_API_KEY` | empty until Phase 3 | empty | Fallback provider. |

Secrets handling follows [ADR-0007](./docs/adr/0007-secrets-management.md): no `.env` file is ever committed, production injects environment variables via the orchestrator's secret mechanism, and `gitleaks` runs in CI as defence-in-depth.

### Making a test request

```bash
curl -X POST http://localhost:8080/api/v1/notifications \
  -H "Authorization: Bearer $API_KEY" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "email",
    "recipient": "user@example.com",
    "payload": {
      "subject": "Hello from EventPulse",
      "body_text": "Plain text body."
    }
  }'
```

API keys are provisioned via Artisan command (no self-service signup; see [ADR-0001](./docs/adr/0001-scope-and-exclusions.md)).

### Idempotency and asynchronous dispatch

Every `POST /notifications` request must carry an `Idempotency-Key` header. The contract:

- **First submission** → `202 Accepted`; the notification is persisted, a `DispatchNotificationJob` is enqueued, and the response carries the assigned id.
- **Same key, identical body, same API key, within 24 h** → `200 OK`; the original response is returned, no second persistence, no second enqueue. The original `correlation_id` is preserved — tracing identity belongs to the first submission.
- **Same key, *different* body** → `409 Conflict`; the second request is rejected and the original notification remains unchanged.
- Keys are **scoped per API key**: two callers using the same key value do not collide.

Dedup is done by indexed lookup against the `notifications.idempotency_key` column (composite-unique with `api_key_id`), not by a Redis cache of the response. The trade-offs are documented in `SubmitNotificationHandler`'s docblock; the choice is revisitable if the dedup-replay rate ever dominates the endpoint's latency budget.

Dispatch is asynchronous from acceptance: the HTTP path persists the notification and enqueues a job; the worker picks it up, claims an attempt, and runs the channel-specific dispatch logic. Priority is mapped to a queue name (`notifications-high`, `notifications-default`, `notifications-low`) so workers can be scaled per priority class.

---

## Testing

### Setting up the test environment

The feature suite uses Laravel's `RefreshDatabase` trait, which re-runs migrations against the configured database between test classes. It must therefore point at a **separate database** — pointing it at `eventpulse` would erase your local development data.

A SQLite-in-memory fallback (a common Laravel shortcut) isn't viable here: the migrations rely on PostgreSQL features that SQLite doesn't implement (`jsonb`, `TIMESTAMPTZ`, `CHECK` constraints).

**One-time setup, after `docker-compose up -d`:**

```bash
# Create the dedicated test database on the same Postgres instance.
docker-compose exec postgres psql -U eventpulse -d eventpulse \
    -c "CREATE DATABASE eventpulse_test OWNER eventpulse;"

# Configure the test environment.
cp .env.testing.example .env.testing
docker-compose exec php-fpm php artisan key:generate --env=testing
```

`.env.testing` carries database credentials and test-shape settings (`QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `MAIL_MAILER=array`) that keep test runs hermetic — no Redis writes, no mail dispatched, no jobs queued.

`phpunit.xml` only contains constants that should be identical on every developer's machine (`APP_ENV=testing`, `BCRYPT_ROUNDS=4` for hash-comparison speed). Credentials live in `.env.testing` to keep secrets out of version control.

`RefreshDatabase` migrates the test database on its first run, so you do not need to run `php artisan migrate --env=testing` manually after creating the database.

### Running the suite

```bash
docker-compose exec php-fpm php artisan test                    # full suite
docker-compose exec php-fpm php artisan test --testsuite=Unit
docker-compose exec php-fpm php artisan test --testsuite=Feature
```

The suite is split deliberately:

- **Unit** — pure domain logic, no framework, no I/O. Fast.
- **Integration** — queue behavior, database repositories, third-party adapters (mocked at the HTTP boundary).
- **Feature** — full HTTP round-trips against the test database.

Testing philosophy and conventions are in [`docs/testing-strategy.md`](./docs/testing-strategy.md).

---

## Documentation

- **[Architecture Decision Records](./docs/adr/)** — the reasoning behind every non-obvious choice
- **[Domain model](./docs/domain.md)** — aggregates, events, channels, invariants
- **[Testing strategy](./docs/testing-strategy.md)** — pyramid layers, what's mocked where, and why
- **[Deployment runbook](./docs/DEPLOYMENT.md)** — environment setup, migration order, rollback procedure *(from v0.2.0)*
- **[Security model](./SECURITY.md)** — threat model, hardening measures, how to report issues *(from v0.2.0)*

---

## Technology choices

| Concern | Choice | Rationale |
|---------|--------|-----------|
| Runtime | PHP 8.4 / Laravel 12 | Mature ecosystem, excellent queue and testing tooling |
| Primary store | PostgreSQL 17 | ACID, JSON support, pgvector extension for Phase 3 |
| Queue / cache | Redis 7 | Queue backend, idempotency key store, cache layer |
| Container | Docker (distroless final) | ~80MB runtime image, minimal attack surface *(v0.2.0)* |
| CI/CD | GitHub Actions | Tests, Psalm, PHPStan, Trivy, composer audit *(v0.2.0)* |
| LLM | Anthropic + OpenAI | Fallback chain for reliability; see ADR-0010 *(v1.0.0)* |

Each choice has an accompanying ADR where the decision was non-obvious.

---

## Roadmap

| Phase | Tag      | Status      | Focus                                                                 |
|-------|----------|-------------|-----------------------------------------------------------------------|
| 1     | `v0.1.0` | In progress | Core API, queues, channels, retry, DLQ, tests, OpenAPI                |
| 2     | `v0.2.0` | Planned     | Docker multi-stage, CI/CD, security scanning, deployment runbook      |
| 3     | `v1.0.0` | Planned     | Semantic event search (pgvector), LLM-generated channel variants      |

Changelog is maintained in [`CHANGELOG.md`](./CHANGELOG.md) from the first tag onward.

---

## About this project

EventPulse is part of my public portfolio as a freelance backend engineer. I work with startups and agencies on Laravel and Symfony modernization, production hardening, and performance optimization.

If any decision here is relevant to a problem you're facing — or if you just want to argue about one of the exclusions in [ADR-0001](./docs/adr/0001-scope-and-exclusions.md) — I'm happy to talk.

- **LinkedIn:** [linkedin.com/in/pavel-rodin-2226b13b6](https://www.linkedin.com/in/pavel-rodin-2226b13b6) — fastest response
- **Upwork:** [upwork.com/freelancers/~01264bb28f414d773f](https://www.upwork.com/freelancers/~01264bb28f414d773f)
- **Email:** pavel.programer@gmail.com

Happy to have a no-pressure call to see if I'm a fit for what you're building — or to point you somewhere better if I'm not.

---

## License

MIT — see [LICENSE](./LICENSE.md).