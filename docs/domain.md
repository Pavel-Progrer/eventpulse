# EventPulse — Domain Model

**Status:** Living document. Updated as the domain evolves.
**Last revised:** 2026-04-20

This document describes the domain of EventPulse: what the system is about, the concepts it operates on, how they relate, and the invariants that must hold. It is the companion to [ADR-0001](./adr/0001-scope-and-exclusions.md) (which defines the bounds of the domain) and to the technical specification (which defines the externally visible behavior).

The domain model is framework-agnostic. Laravel artifacts — Eloquent models, migrations, jobs — are implementations of concepts defined here, not the concepts themselves. A future rewrite in another language or framework should be able to use this document as-is.

---

## 1. What the domain is about

EventPulse exists to turn a caller's intent to notify someone into reliable, observable delivery across one of several channels. The domain is fundamentally about a single noun — a **notification** — moving through a small number of states while the system makes repeated, bounded attempts to hand it off to an external delivery mechanism.

Three properties define the domain:

**Delivery is external and unreliable.** Every actual delivery mechanism — an SMTP server, a webhook endpoint, an SMS provider — can fail transiently, fail permanently, or silently misbehave. The domain's job is to react sensibly to all three without losing the caller's intent.

**The caller's request and the eventual delivery are decoupled in time.** The API returns as soon as intent is durably recorded. Delivery may happen milliseconds later or hours later (when retries are involved). Callers must be able to discover the outcome without having held the connection open.

**Callers want guarantees, not mechanics.** The caller doesn't want to know about backoff curves or DLQ partitions. They want: "if I submit this once, you'll try hard to deliver it, you'll tell me when you succeed or give up, and you won't deliver it twice unless I asked you to."

Everything else in the domain follows from these three properties.

---

## 2. Ubiquitous language

Terms have precise meanings in this domain. Synonyms from adjacent domains (email, messaging, queueing) are rejected where they would blur boundaries.

- **Notification** — a request to deliver a single piece of content to a single recipient over a single channel, plus all the state the system accumulates in attempting that delivery. A notification is a request-and-history, not just the content.
- **Dispatch** — the act of handing a notification off to an external delivery mechanism. A notification is dispatched when the external mechanism has accepted responsibility for it. For email, dispatch means the SMTP `250` response to `DATA`. For webhooks, dispatch means the receiver returned a 2xx. For SMS, dispatch means the provider accepted the message for onward transmission.
- **Delivery** — what happens after dispatch, in the external system. EventPulse does not track delivery. This is an important boundary: "dispatched" does not mean "arrived in the inbox." Callers who need delivery signals need a separate feedback mechanism (bounces, webhook callbacks from the recipient), which is out of scope (see ADR-0001).
- **Attempt** — a single, concrete effort to dispatch a notification. A notification can have many attempts. Each attempt either succeeds, fails transiently (retry eligible), or fails permanently.
- **Channel** — a kind of external delivery mechanism. Email, webhook, and SMS are the three channels in v1.0.
- **Recipient** — the channel-specific address at which the notification should arrive. Its form is channel-dependent: an email address, a webhook destination id, a phone number.
- **Webhook destination** — a pre-registered URL plus secret, owned by an API key, to which webhook notifications can be addressed. A webhook destination is a distinct aggregate from a notification; a notification references a webhook destination by id, not by inline URL.
- **Dead-lettered** — the state of a notification that will not be retried further by normal flow. Dead-lettering is a decision the system makes and records; it is not an absence of action. A dead-lettered notification still exists, is still queryable, and can be replayed.
- **Replay** — the act of creating a new notification from a dead-lettered one, inheriting payload and recipient, linked back to the original by reference. Replay is not retry. Retry is internal; replay is operator-initiated and produces a new aggregate.
- **Idempotency key** — a caller-supplied string that, within a bounded time window and scoped to the caller, guarantees that repeated submissions of the same logical request produce exactly one notification and return the original response. The idempotency key is part of the caller's contract, not an internal implementation detail.
- **Correlation id** — a string that ties together all log lines, events, and derivative actions caused by a single caller request. Distinct from idempotency key: the correlation id changes on each submission; the idempotency key does not.
- **Canonical payload** *(Phase 3)* — the source-of-truth content for a multi-channel notification, from which per-channel variants are derived. Canonical payload is a Phase 3 concept; single-channel notifications do not have one.

Terms deliberately rejected:

- **Message** — too ambiguous; overloaded with queue terminology and with the content of a specific channel (e.g., an SMS message). A notification may contain a message, but a notification is not a message.
- **Event** — reserved for domain events (§6). A notification is not an event; a notification's state transitions raise events.
- **Job** — Laravel infrastructure term. A notification is dispatched by a job, but the notification is not the job.

---

## 3. Aggregates and boundaries

The domain has three aggregates. An aggregate is a cluster of objects treated as a unit for consistency; invariants within an aggregate are enforced synchronously, invariants between aggregates are eventually consistent.

### 3.1 Notification aggregate

**Root:** `Notification`.
**Constituents:** a sequence of `Attempt` entities, optionally a `DeadLetterMark`.
**Identity:** UUID, immutable.

The aggregate owns the full lifecycle of a single dispatch intent. Attempts are created and appended only through the aggregate root; no external code constructs an `Attempt` directly. This is what makes invariants like "attempt numbers are contiguous and start at 1" trivially enforceable.

The `DeadLetterMark` is an optional component of the aggregate, not a separate aggregate. Dead-lettering is a state of a notification, not an independent object. A dead-letter query endpoint exists for operator convenience, but what it returns is always a notification in the dead-lettered state.

### 3.2 WebhookDestination aggregate

**Root:** `WebhookDestination`.
**Identity:** UUID, immutable.

A webhook destination is referenced by notifications but has an independent lifecycle. It can be disabled (and a disabled destination rejects new notifications at submission time) while historical notifications that referenced it remain intact. This separation exists because the destination is a piece of operational configuration, not a piece of a specific notification's history.

### 3.3 ApiKey aggregate

**Root:** `ApiKey`.
**Identity:** UUID, immutable. Also has a human-readable identifier (`ep_live_...`) which is the public half used in requests.

An API key carries the authentication identity, authorization scopes, and rate-limit configuration for a caller. Its relationship to notifications is referential — notifications record which API key created them — but deleting or revoking an API key does not affect existing notifications. Revocation prevents new notifications and new webhook destinations from being created under the key.

### 3.4 Why these boundaries

The three aggregates correspond to three distinct questions the system must answer atomically:

- **"What is this notification's complete state right now?"** — answered by the Notification aggregate.
- **"Where should a webhook-channel notification be delivered, and with what secret should it be signed?"** — answered by the WebhookDestination aggregate.
- **"Is this caller permitted to do what they are asking, and have they exceeded their limits?"** — answered by the ApiKey aggregate.

Each question is answerable without traversing aggregate boundaries transactionally. Attempts to fold two aggregates into one — for example, putting webhook destinations inside the API key aggregate — would either create consistency obligations that don't exist in the real domain, or require partial loading that defeats the purpose of the aggregate.

---

## 4. Notification lifecycle

A notification exists in one of six states. Transitions are the core dynamics of the domain.

```
                              ┌──────────┐
                              │ queued   │◀────┐
                              └────┬─────┘     │  (scheduled_for
                                   │           │   reached, or
                         worker picks up       │   immediate)
                                   ▼           │
                              ┌──────────┐     │
                    ┌─────────│processing│─────┘
                    │         └────┬─────┘
                    │              │
           attempt succeeds        │
                    │              │  attempt fails
                    ▼              │  (transient, retries left)
              ┌──────────┐         │
              │dispatched│         │
              └──────────┘         │
                                   │
                              (retries exhausted,
                               or unrecoverable error)
                                   │
                                   ▼
                             ┌─────────────┐
                             │dead_lettered│
                             └──────┬──────┘
                                    │
                                    │  operator replays
                                    ▼
                                 (new notification, queued)
```

A seventh state — `scheduled` — is logically a sub-state of `queued` distinguished by a non-null `scheduled_for` timestamp in the future. The scheduler job advances scheduled notifications to the active queue when their time arrives. We do not model this as a separate state because no external observer cares about the distinction: a scheduled notification is a queued notification that hasn't started yet.

A `failed` state is listed in the specification for API completeness but represents a permanent terminal failure distinct from dead-lettering — reserved for cases where the notification cannot even be attempted (e.g., a webhook destination that was deleted between submission and worker pickup). In practice, such cases are rare and are treated similarly to dead-lettering operationally. The distinction is retained because dead-lettering implies "we tried and gave up," while failed implies "we never got to try."

### State transition rules

- **queued → processing** — a worker has claimed the notification. Claim is exclusive; no two workers process the same notification.
- **processing → dispatched** — the most recent attempt succeeded. Terminal.
- **processing → queued** — the most recent attempt failed transiently and retries remain. Requeued with a calculated delay.
- **processing → dead_lettered** — the most recent attempt failed, and either retries are exhausted or the failure is classified as unrecoverable.
- **processing → failed** — the attempt could not be made (e.g., dependency vanished). Terminal.
- **dead_lettered → (new notification, queued)** — operator replay. The original remains dead-lettered; a new notification is created with a reference back.

Disallowed transitions (enforced by the aggregate):

- dispatched → anything. Terminal.
- failed → anything. Terminal. Replay goes through dead-lettering.
- queued → dead_lettered directly. Dead-lettering requires at least one attempt.

---

## 5. Invariants

Invariants are statements that are always true about the domain. They are the obligations the aggregate roots enforce and the properties the tests should assert.

### 5.1 Notification invariants

1. **Identity is immutable.** A notification's UUID never changes once assigned.
2. **Attempt numbers are contiguous from 1.** If a notification has N attempts, they are numbered 1..N with no gaps. (Enforced by the aggregate; database is not trusted to maintain this.)
3. **Exactly one attempt is in progress at a time.** A notification cannot have two attempts with `completed_at` null simultaneously. This is what lets "state = processing" be a single clear thing.
4. **Attempts are append-only.** An attempt's record is written once, completed once, and never modified thereafter. Historical attempts are not rewritten.
5. **Dead-lettering requires at least one failed attempt.** The aggregate refuses to dead-letter a notification that has never been attempted.
6. **Terminal states are terminal.** A dispatched notification cannot transition to anything else. A failed notification cannot transition to anything else. Dead-lettered notifications are not terminal for the notification itself (a dead-letter mark is queryable and ackable), but they do not transition back to queued — replay creates a new aggregate.
7. **Replay is referenced, not copied.** A replay notification is a new aggregate with its own identity; the original's `dead_letter_mark.replay_notification_id` points to it. The original's state does not change to "replayed" — it remains dead-lettered. "Replayed" is a fact about the mark, not a state of the notification.
8. **Idempotency key is stable within a caller.** Two submissions with the same `(api_key_id, idempotency_key)` within the window must yield exactly one notification; the second submission returns the first's response. This is a cross-aggregate invariant enforced at the application layer, not inside the notification aggregate.
9. **Recipient form matches channel.** A notification with `channel = email` must have a recipient that is a valid email address. A notification with `channel = webhook` must have a recipient that is a valid, active webhook destination id owned by the same API key. A notification with `channel = sms` must have an E.164 phone number. This is validated at creation; the aggregate will refuse to be constructed otherwise.
10. **Payload shape matches channel.** See the channel payload schemas in the specification. Enforced at creation.

### 5.2 WebhookDestination invariants

1. **Identity is immutable.**
2. **URL scheme is https.** `http://` destinations are rejected at creation. This is a domain rule because the system signs webhook deliveries and trusts the destination to handle a signed HTTPS payload; downgrading to cleartext defeats the signing guarantee.
3. **Secret is write-once in the sense that it is never returned after creation.** The operator who creates the destination must capture the secret at that moment. Subsequent reads (of the aggregate) omit it.
4. **Disabled destinations cannot be used for new notifications.** An attempt to submit a webhook notification against a disabled destination fails at the submission boundary with a validation error.
5. **Historical notifications are unaffected by disable.** Disabling a destination does not invalidate notifications that reference it. Those notifications retain their history; if somehow still in-flight they may still dispatch successfully (the HTTP signing uses the secret that was current at submission, captured into the notification's context).

### 5.3 ApiKey invariants

1. **Identity is immutable.**
2. **The secret is never stored in plaintext.** Only the Argon2id hash is persisted. See ADR-0007.
3. **Revoked keys cannot create new notifications or destinations.** A revoked key can still be authenticated against for read operations during a grace window, at operator discretion — this is so that a key's notifications can be inspected after revocation without needing admin access. This rule is deliberately configurable.
4. **Scope is required to act.** Every sensitive operation checks that the authenticated key carries the required scope; missing scope returns 403, not 401.

### 5.4 Cross-aggregate invariants

These invariants span aggregates and are therefore enforced at the application layer, not within any single aggregate:

- **A notification references only webhook destinations owned by the same API key.** Prevents one caller from abusing another caller's registered destinations.
- **LLM budget debits match LLM calls.** Every LLM provider call that incurs token cost produces a ledger entry. This is eventual consistency with idempotency guarantees: if the ledger write fails after the call succeeds, the call is logged as an unaccounted-for cost and surfaced in the admin stats endpoint.
- **Idempotency records are consistent with notifications.** If an idempotency record exists pointing to notification `N`, notification `N` must exist. If it does not, the idempotency record is treated as corrupt and discarded.

---

## 6. Domain events

Domain events represent things that have happened in the domain and are worth naming. They are distinct from notifications (the noun) and from Laravel events (the framework mechanism). In v1.0, domain events are surfaced through structured logging and the admin stats aggregations; they are not published to an external event bus. The infrastructure to publish them to an external consumer would be a small addition (see ADR-0001's "would revisit if" for multi-service scenarios).

Each event carries at minimum: `occurred_at`, `correlation_id`, and the aggregate root id it pertains to.

### 6.1 Notification lifecycle events

- **`NotificationRequested`** — a caller submitted a notification and it was accepted (created, persisted, queued). Raised exactly once per notification.
- **`NotificationDispatchAttempted`** — a worker began an attempt. Raised once per attempt.
- **`NotificationDispatched`** — an attempt succeeded. Raised exactly once per notification, at the first (and only) successful attempt.
- **`NotificationDispatchFailed`** — an attempt failed. Raised once per failed attempt, regardless of transient vs. permanent classification. Includes the classification in its payload.
- **`NotificationScheduledForRetry`** — after a transient failure, a retry has been scheduled. Distinct from `NotificationDispatchFailed` because the decision to retry is itself a domain decision worth recording.
- **`NotificationDeadLettered`** — the system gave up. Raised exactly once per notification that reaches this state.
- **`NotificationReplayed`** — a dead-lettered notification produced a replay. Raised on the original's dead-letter mark; the replay notification separately raises `NotificationRequested`.

### 6.2 Webhook destination events

- **`WebhookDestinationRegistered`**
- **`WebhookDestinationDisabled`**

### 6.3 API key events

- **`ApiKeyIssued`**
- **`ApiKeyUsed`** — surfaced only for the purpose of updating `last_used_at`; not every use raises a logged event to keep volume sane.
- **`ApiKeyRevoked`**
- **`ApiKeyRotated`**

### 6.4 Phase 3 events

- **`NotificationEmbedded`** — the low-priority embedding job completed for a notification.
- **`LlmVariantGenerated`** — an LLM call produced a channel variant. Includes provider, tokens, and cache status.
- **`LlmBudgetExceeded`** — a caller's budget was exhausted. Significant enough to warrant its own event separate from logging.

---

## 7. Value objects

Value objects have no identity; they are defined by their attributes. Equality is structural.

- **`Recipient`** — typed hierarchy: `EmailRecipient`, `WebhookRecipient` (wraps a `WebhookDestinationId`), `SmsRecipient`. Each validates its content on construction. `Recipient` is channel-polymorphic; the concrete type is bound to the channel of the notification.
- **`Channel`** — enumeration: email, webhook, sms.
- **`Priority`** — enumeration: low, normal, high. Used to select the queue partition.
- **`IdempotencyKey`** — a validated caller-supplied string.
- **`CorrelationId`** — a validated string, either supplied or generated.
- **`AttemptNumber`** — an integer ≥ 1.
- **`Backoff`** — a value representing the calculated delay for the next attempt, derived from attempt number and the channel's retry policy.
- **`CanonicalPayload`** *(Phase 3)* — the typed wrapper around multi-channel source content.

Value objects are immutable. "Changing" one produces a new one.

---

## 8. What is *not* in the domain

Things that could plausibly be domain concepts but have been deliberately pushed out:

- **Templates.** Notification content is rendered before submission. EventPulse receives final content. A template engine would be a separate bounded context with its own model.
- **Recipient lists / subscribers.** Callers manage their own recipient identity. There is no concept of a user or subscriber in the EventPulse domain.
- **Suppression / unsubscribe.** Out of scope; callers enforce their own.
- **Delivery confirmation.** See §1 — dispatched ≠ delivered. Delivery confirmation is a separate feedback domain.
- **Analytics.** Admin stats provide operational counters, not marketing analytics. Open rates, click rates, and the like are out of scope.
- **User-facing accounts or organizations.** The API key is the caller's identity. There is no hierarchy above it.

These exclusions are as deliberate as those in ADR-0001 and for the same reason: keeping the domain small enough to model cleanly is more valuable than completeness.

---

## 9. Modeling decisions worth their own ADRs

Points in this document where a real decision was made that merits its own Architecture Decision Record:

- The choice to model dead-lettering as a state of the notification rather than a separate aggregate — captured in the aggregate discussion (§3.1), worth an ADR if it becomes contentious.
- The choice to use `Idempotency-Key` as a caller-supplied external key rather than a request-body hash — captured in the terminology (§2).
- The choice to model replay as a new notification rather than a state transition on the original — captured in the invariants (§5.1.7).
- The choice to classify attempt failures at the domain layer (transient vs. permanent vs. unrecoverable) rather than letting infrastructure bubble up raw errors — implicit in §4 and §6.

Each of these is a call where a different team could reasonably have made a different choice. They are the seams at which this domain model would diverge from a superficially similar one.