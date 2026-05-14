# README excerpt — Day 8

The text below is intended to be spliced into the existing `README.md`. Suggested placement:

- The **Dead-letter queue** section sits between **Idempotency and asynchronous dispatch** and **Testing**.
- The **Observability** section sits between **Dead-letter queue** and **Testing**.
- Update the **Roadmap** entry for Phase 1 to mark "DLQ admin (read-only)" and "Structured domain-event logging" as done.

---

## Dead-letter queue

A notification that exhausts its retry budget — or fails with an unrecoverable classification — is **dead-lettered**: its status moves to `dead_lettered`, a row is written to `dead_letter_marks`, and the dispatch flow stops. Dead-lettered notifications are visible to operators through two read-only endpoints:

```
GET /api/v1/dlq            # list, with filters and cursor pagination
GET /api/v1/dlq/{id}       # full inspection: notification + attempts + DL metadata
```

Both require an API key with the `dlq:read` scope. **Visibility is per-API-key**, always: even a future `admin` scope grants access to additional endpoints, not to other tenants' notifications. The reasoning is in [ADR-0006 §"DLQ visibility is tenant-scoped"](./docs/adr/0006-dlq-admin-and-structured-logging.md).

### Listing entries

```bash
curl https://your-host/api/v1/dlq \
  -H 'Authorization: Bearer ep_live_...' \
  -H 'Accept: application/json'
```

Filters (combine to narrow):

| Param            | Example                          | Notes                                              |
| ---------------- | -------------------------------- | -------------------------------------------------- |
| `reason`         | `?reason=max_retries_exceeded`   | One of `max_retries_exceeded`, `unrecoverable_error`, `manual` |
| `channel`        | `?channel=webhook`               | One of `email`, `sms`, `webhook`                   |
| `created_after`  | `?created_after=2026-04-27T00:00:00Z` | Inclusive lower bound on `dead_lettered_at`        |
| `created_before` | `?created_before=2026-04-28T00:00:00Z` | Exclusive upper bound on `dead_lettered_at`         |
| `limit`          | `?limit=50`                      | 1–100, default 25                                  |
| `cursor`         | `?cursor={opaque}`               | From a prior response's `pagination.next_cursor`   |

Response shape (`PaginatedDlqEntries` in the OpenAPI spec):

```json
{
  "data": [
    {
      "id": "...",
      "notification_id": "...",
      "reason": "max_retries_exceeded",
      "channel": "webhook",
      "final_attempt_at": "2026-04-27T10:05:13+00:00",
      "replayed_at": null,
      "replay_notification_id": null,
      "created_at": "2026-04-27T10:05:14+00:00"
    }
  ],
  "pagination": { "next_cursor": "2026-04-27T10:05:14+00:00|..." }
}
```

The pagination metadata is intentionally minimal. There is no `total_count`: a count would force a second `COUNT(*)` against the same predicate on every list call, doubling the cost for a number that's stale before the response renders. The `next_cursor` is opaque — clients pass it back verbatim on the next request and stop paginating when it is `null`.

### Inspecting a single entry

```bash
curl https://your-host/api/v1/dlq/{notification_id} \
  -H 'Authorization: Bearer ep_live_...'
```

Returns the full notification (channel, recipient, payload, every attempt with its outcome) plus the dead-letter metadata. Three failure modes return the same `404`:

- the notification doesn't exist,
- it belongs to another tenant,
- it exists but is not in the `dead_lettered` status.

This is deliberate — distinguishing them in the response would let an attacker enumerate cross-tenant ids by watching for the status change. The diagnostic stays in operator-facing logs via the `correlation_id`.

### Replay and discard

Currently deferred. The data model (the `replay_notification_id` and `replayed_at` columns on `dead_letter_marks`, plus the `replayedAt` field on the `DeadLetterMark` entity) supports replay, and the OpenAPI spec already documents the `POST /dlq/{id}/replay` endpoint, but the use-case handler is not in this release. See [ADR-0006 §"Triggers to revisit"](./docs/adr/0006-dlq-admin-and-structured-logging.md) for the intended shape.

---

## Observability

Every domain event released by an application service becomes one structured JSON log line with a stable shape. The dispatcher (`StructuredLogDomainEventDispatcher`) replaces the no-op binding used in earlier days; switching it on was a single edit to `EventPulseServiceProvider` because every collaborator depends on the `DomainEventDispatcher` port, not on a concrete logger.

### Log shape

```json
{
  "level": "info",
  "message": "notification_dispatched",
  "context": {
    "event": "notification_dispatched",
    "correlation_id": "550e8400-e29b-41d4-a716-446655440000",
    "occurred_at": "2026-04-27T10:05:13+00:00",
    "notification_id": "...",
    "succeeded_on_attempt": 2
  }
}
```

The `event` field uses snake_case names (`notification_requested`, `notification_dispatch_attempted`, `notification_dispatched`, `notification_dispatch_failed`, `notification_scheduled_for_retry`, `notification_dead_lettered`, `notification_replayed`). Operator dashboards filter on this column directly — class names would be noisy and would break when namespaces move.

The **anchor field is `correlation_id`**. It enters at the HTTP boundary, propagates into the notification aggregate, attaches to every event the aggregate raises, and lands in every log line this dispatcher emits. A single grep reconstructs one user-visible request's full lifecycle, across the synchronous HTTP path and the asynchronous worker path.

### Log levels

| Level     | Events                                                                    | Why                                              |
| --------- | ------------------------------------------------------------------------- | ------------------------------------------------ |
| `info`    | requested, attempted, dispatched, scheduled-for-retry, replayed           | Normal operations                                |
| `warning` | dispatch-failed                                                           | Degradation — watch a channel start to slip      |
| `error`   | dead-lettered                                                             | Surfaces on existing alerting rules              |

### Adding a new domain event

The dispatcher uses an exhaustive `match (true)` over `instanceof` with `default => throw new LogicException(...)`. Adding a new domain event without adding a render branch fails the first time the new event flows through a use case — preferable to silent drop. PHPStan/Psalm catch the same gap statically when the suite runs.

### What this dispatcher does *not* do

- It does not emit metrics. Metrics come from the same JSON stream via a log-to-metrics pipeline (LogQL, Vector) that aggregates `event_name` and `channel` directly. Doing it twice would double the storage cost and risk drift.
- It does not publish to an external event bus. That seam exists at the `DomainEventDispatcher` interface — a future `CompositeDomainEventDispatcher` can fan out to both this logger and a Kafka/NATS bus without changing any application service or the aggregate. (Phase 1 deliberately excludes external buses; see [ADR-0001](./docs/adr/0001-scope-and-exclusions.md).)

---

## Roadmap entry — splice-in for Phase 1 list

Replace the existing Phase 1 bullet list (or extend it) with:

- ✅ Core API: submit, idempotency, validation, error envelope
- ✅ Domain model with explicit aggregates, value objects, domain events
- ✅ Channel dispatch (strategy: email + webhook + SMS stub)
- ✅ Retry policy with exponential backoff + jitter, per-channel
- ✅ Dead-letter queue persistence + admin inspection (read-only)
- ✅ Structured domain-event logging with correlation IDs
- ⏳ DLQ replay and discard
- ⏳ OpenAPI hosted docs (`/api/docs`)
