# ADR-0001: Scope and explicit exclusions

- **Status:** Accepted
- **Date:** 2026-04-20
- **Deciders:** Pavel Rodin

## Context

EventPulse is an event-driven notification dispatch service. The design space for a system like this is effectively unbounded: it could reasonably grow to include event sourcing for full audit history, multi-service decomposition for scale, Kafka for cross-system event streaming, Kubernetes for orchestration, a full admin frontend, OAuth2 for third-party integrations, and a long tail beyond that.

Most of those choices are defensible in isolation. Taken collectively, they produce a project that demonstrates breadth of tooling knowledge rather than depth of architectural judgment — and would take six months to reach a comparable state of completeness.

This ADR defines what EventPulse is, and — more importantly — what it deliberately is not, with the reasoning for each exclusion. Subsequent ADRs that propose reversing any exclusion here must explicitly reference and supersede the relevant section.

## Decision

### What EventPulse is

- A single Laravel service exposing a REST API for notification dispatch
- Queue-backed (Redis) with at-least-once delivery, idempotency, retry with backoff, and dead-letter handling
- Multi-channel (email, webhook, SMS) via a strategy-based dispatcher
- Production-hardened: containerized with a distroless final image, CI/CD with security scanning, structured logging, observability hooks
- AI-enhanced in specific, bounded ways: semantic search over event history, LLM-generated channel-appropriate notification variants

### What EventPulse explicitly is not

**No Kubernetes.** The service is a single process plus queue workers. Orchestration needs (rollouts, service discovery, autoscaling) are handled adequately by ECS/Fargate or a single host behind a load balancer at this scale. Kubernetes brings operational complexity that this project does not need to solve or demonstrate. *Would revisit if:* deploying more than a handful of services, or requiring sophisticated traffic management (service mesh, canary deployments, etc.).

**No Kafka.** The delivery guarantees required — at-least-once, retry with backoff, dead-letter handling — are fully covered by Redis-backed Laravel queues. Kafka's value lies in event replay from arbitrary offsets, multi-consumer fan-out to independent systems, and high-throughput streaming. None of these are part of this domain. *Would revisit if:* needing to replay from arbitrary offsets, multiple independent consumers of the same event stream, or throughput requirements above roughly 10k events/sec.

**No frontend.** EventPulse is infrastructure consumed by other services via API. OpenAPI documentation is the interface for developers. Operational concerns (inspecting the dead-letter queue, managing API keys) are handled via Artisan commands and a minimal internal endpoint set. A human-operable dashboard would add scope without serving the core value proposition. *Would revisit if:* non-technical operators need a UI rather than CLI/API access.

**No event sourcing.** Notification dispatch does not require historical state reconstruction. Once a notification has been dispatched or permanently failed, the outcome is the outcome. Event sourcing's cost — projection maintenance, snapshot strategy, event versioning, read model synchronization — dwarfs its benefit for this domain. Domain events are still modeled explicitly, but as integration events, not as the source of truth. *Would revisit if:* full audit history becomes a regulatory requirement, or the domain evolves to need temporal queries.

**No multi-service architecture.** A single Laravel service with clean internal boundaries (domain / application / infrastructure layers) is sufficient for this scope. Microservices would introduce distributed-systems concerns — inter-service authentication, distributed tracing, eventual consistency across stores — that this project neither needs to solve nor is the right vehicle to demonstrate. Internal architectural clarity is the senior-level skill on display here. *Would revisit if:* team structure or genuine independent-deployability requirements demand it.

**No OAuth2.** The authentication model in practice is service-to-service: API keys combined with HMAC request signing cover the real use case cleanly and securely. OAuth2 is designed for user-delegated authorization, which is not the model EventPulse serves. Implementing it would be demonstration for demonstration's sake. *Would revisit if:* third parties need delegated access on behalf of end-users.

## Consequences

### Positive

- The project remains shippable in 28 days at senior-grade quality rather than six months at intermediate quality
- Each excluded concept is one whose absence is a deliberate signal rather than an oversight
- The codebase stays readable to a new engineer in a day, not a week
- Operational surface area stays small — the service is honest about what it needs

### Negative

- Some readers may look for specific technologies (Kubernetes, Kafka) and not find them. This ADR exists partly to address that: the absences are explained, not apologized for.
- Extensions in any of the excluded directions are deferred and will require deliberate revisiting rather than happening by drift. This is treated as a feature.

## Notes

This ADR is the scope contract for the project. It will be reviewed at the completion of each phase (`v0.1.0`, `v0.2.0`, `v1.0.0`) and updated if any of the "would revisit if" conditions are met.