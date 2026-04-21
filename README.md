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
docker-compose exec app php artisan migrate
docker-compose exec app php artisan key:generate
```

The API will be available at `http://localhost:8080`. An OpenAPI spec is published at `/api/docs` from `v0.1.0` onward.

### Making a test request

```bash
curl -X POST http://localhost:8080/api/notifications \
  -H "Authorization: Bearer $API_KEY" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "webhook",
    "recipient": "https://httpbin.org/post",
    "payload": {"message": "hello from EventPulse"}
  }'
```

---

## Testing

```bash
docker-compose exec app php artisan test                    # full suite
docker-compose exec app php artisan test --testsuite=Unit
docker-compose exec app php artisan test --testsuite=Feature
```

The suite is split deliberately:

- **Unit** — pure domain logic, no framework, no I/O. Fast.
- **Integration** — queue behavior, database repositories, third-party adapters (mocked at the HTTP boundary).
- **Feature** — full HTTP round-trips against a test database.

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
| Runtime | PHP 8.3 / Laravel 11 | Mature ecosystem, excellent queue and testing tooling |
| Primary store | PostgreSQL 16 | ACID, JSON support, pgvector extension for Phase 3 |
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

MIT — see [LICENSE](./LICENSE).