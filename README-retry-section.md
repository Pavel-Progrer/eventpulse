# README addition — splice point

Insert the section below into `README.md` immediately after the existing
**"Idempotency and asynchronous dispatch"** subsection (around line 123) and
before the `---` separator that introduces the **"Testing"** section.

The new section reuses the existing tone (declarative, link-out to ADRs, no
marketing): it documents the spec table, the formula, the override knobs, and
the deferred Retry-After honoring — without restating anything ADR-0005
already covers.

---

### Retry and dead-letter handling

A transient failure (HTTP 5xx, 408, 429, network timeout, SMTP 4xx queueable, etc.) is retried with **exponential backoff and jitter**. A permanent failure (HTTP 4xx other than 408/429, invalid recipient, destination disabled) goes straight to the dead-letter queue. An unrecoverable failure (destination missing, malformed configuration) also dead-letters immediately.

The retry curve and ceiling are per-channel:

| Channel | Max attempts | Base delay | Max delay | Jitter |
|---------|--------------|------------|-----------|--------|
| webhook | 6            | 10s        | 1 h       | ±25%   |
| email   | 4            | 30s        | 30 m      | ±25%   |
| sms     | 3            | 15s        | 10 m      | ±25%   |

Delay between failed attempt *N* and attempt *N+1* is

```
delay = min(base * 2^(N - 1), max) * (1 + j)    where j ∈ [-jitter, +jitter]
```

Jitter is sampled from a cryptographically-secure source so co-tenants cannot synchronise their retries against ours.

These values are the defaults; every field has an env-var override (`EVENTPULSE_RETRY_<CHANNEL>_<FIELD>`) so a deployment can tune retry behaviour without a code change. See [`config/eventpulse.php`](./config/eventpulse.php) for the full list.

After exhausting the channel's max attempts on transient failures, the notification is dead-lettered with `reason: max_retries_exceeded`. The DLQ admin endpoints (inspection, replay) ship in the next milestone — see the [Roadmap](#roadmap).

The architectural reasoning — why `RetryPolicy` is an Application port, why re-enqueue on retry is triggered by aggregate state rather than a domain-event subscriber, why `tries = 1` at the queue level, what is *not* implemented today (webhook `Retry-After` header honouring) — is in [ADR-0005](./docs/adr/0005-retry-policy-and-dead-letter-strategy.md).
