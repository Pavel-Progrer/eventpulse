# ADR-0008: Webhook signature verification approach

- **Status**: Accepted
- **Date**: 2026-04-28
- **Phase**: Phase 1 (`v0.1.0`)
- **Supersedes**: none
- **Related**: ADR-0002 (aggregate boundaries), ADR-0004 (channel dispatch strategy), ADR-0005 (retry policy), ADR-0007 (secrets management)

## Context

> **Numbering note.** The 28-day plan listed this as "ADR-005 — webhook signature verification" because it was written before the actual repo sequence diverged from the plan. The repo's accepted ADR sequence is the source of truth: 0001 → 0002 → 0003 (HTTP boundary) → 0004 (channel dispatch) → 0005 (retry policy) → 0006 (DLQ + structured logging) → 0007 (secrets management) → **0008 (this one)**. ADR-0006's context section documents the same offset.

Day 9 ships the `WebhookDestination` aggregate and its operator endpoints (`POST/GET/DELETE /api/v1/webhook-destinations`). With destinations persisted and resolvable, outbound webhook dispatch can now include HMAC signatures — the `UnconfiguredWebhookEndpointResolver` placeholder is retired.

There are two distinct problems in this space:

**Outbound signing**: EventPulse signs the HTTP request it sends to the receiver, so the receiver can verify the payload came from this system and was not tampered with in transit. This is the primary problem for Phase 1.

**Inbound verification**: A receiver who calls back to EventPulse (or any service that processes EventPulse-signed payloads) needs a reference implementation for how to verify the signature. This is documentation, not code, in Phase 1.

### Constraints

1. The signing secret must never appear in domain events, structured logs, aggregate state, or serialised representations.
2. The secret is stored encrypted at rest. Laravel's `Crypt` facade (AES-256-CBC using the app key) is the existing secret-management path (ADR-0007); this ADR extends it, not replace it.
3. The receiver may be any HTTPS endpoint — we cannot require the receiver to use any particular verification library. The scheme must be implementable in any language from the OpenAPI spec + a docstring.
4. The signing scheme must protect against **payload tampering** and **replay attacks**. It does not need to protect against a compromised app key (that is out of scope for Phase 1 and addressed by key rotation — ADR-0007 §"Rotation").

---

## Decision

### 1. HMAC-SHA256 over a timestamped signed payload

The outbound signature scheme is:

```
timestamp       = Unix epoch seconds (UTC), as a decimal string
signed_payload  = "{timestamp}.{json_encoded_request_body}"
raw_signature   = HMAC-SHA256(secret, signed_payload)
signature_value = "sha256=" + hex_encode(raw_signature)
```

Two headers are added to every outbound webhook request:

```
X-EventPulse-Timestamp: {timestamp}
X-EventPulse-Signature: sha256={hex_encoded_hmac}
```

The receiver's verification algorithm:

1. Extract `X-EventPulse-Timestamp` and `X-EventPulse-Signature`.
2. Reject if the timestamp is more than **±5 minutes** from the receiver's current clock. This is the replay window.
3. Construct `signed_payload = "{timestamp}.{raw_request_body}"` (raw bytes of the HTTP body as received).
4. Compute `expected = HMAC-SHA256(shared_secret, signed_payload)`, hex-encoded.
5. Compare `"sha256=" + expected` against `X-EventPulse-Signature` using a **constant-time comparison** function.
6. Accept the request if they match; reject (return `401`) if they do not.

### 2. The signing secret is held on `WebhookEndpoint`, not on the aggregate

The `WebhookDestination` aggregate never stores the plaintext secret. The flow is:

```
Operator → POST /webhook-destinations (secret in request body)
         → RegisterWebhookDestinationHandler
         → WebhookDestinationRepository::save(destination, plaintext_secret)
         → EloquentWebhookDestinationRepository::save()
         → Crypt::encryptString(secret) → stored in secret_encrypted column
```

At dispatch time:

```
Worker picks up DispatchNotificationJob
→ WebhookChannelDriver::dispatch(request)
→ EloquentWebhookEndpointResolver::resolve(recipient)
→ Crypt::decryptString(secret_encrypted) → WebhookEndpoint(url, signingSecret)
→ WebhookChannelDriver signs with WebhookEndpoint::signingSecret()
→ plaintext secret goes out of scope when DispatchRequest is GC'd
```

### 3. Signature is omitted when no secret is configured

`WebhookEndpoint::hasSigning()` returns `false` when the secret is `null`. The driver omits `X-EventPulse-Signature` and `X-EventPulse-Timestamp` in this case.

In practice this path only occurs in tests that use `InMemoryWebhookEndpointResolver` with unsigned endpoints. All production-path `EloquentWebhookEndpointResolver` resolutions include a secret (the column is non-nullable; a `DecryptException` is treated as `notFound()`, not as "no signing").

### 4. Secret minimum length: 16 characters, recommended: 32+

The OpenAPI spec enforces `minLength: 16`. This is the floor for meaningful HMAC entropy, not a security guarantee on its own. Operators are advised in the README to use at least 32 random characters (`openssl rand -hex 32`). The FormRequest applies the same `min:16, max:256` validation.

---

## Rationale

### Why HMAC-SHA256 and not an asymmetric scheme (RSA, ECDSA)?

HMAC-SHA256 is the dominant industry practice for webhook signing: GitHub, Stripe, Slack, and Shopify all use it. An asymmetric scheme would let receivers verify without sharing a secret, which sounds attractive but introduces key management complexity (public key distribution, rotation, certificate chains) that is out of scope for Phase 1. The receiver population for EventPulse is internal services and known operator endpoints — the shared-secret model is appropriate.

### Why include the timestamp in the signed payload (not just as a separate header)?

Including the timestamp in `signed_payload` binds the signature to this specific delivery at this specific time. If the timestamp were a separate header unbound to the signature, an attacker who captures a valid `(body, signature)` pair could replay it with a fresh timestamp and the receiver's replay-window check would accept it. With the timestamp inside the signed payload, the received body and the HMAC together prove both the content and the time it was signed.

### Why `{timestamp}.{body}` and not a JSON envelope?

A JSON envelope (e.g. `{"ts": 1714298400, "data": {...}}`) wraps the original payload and breaks receivers who depend on the raw body shape. A string prefix `{timestamp}.{body}` keeps the HTTP body identical to the original payload, so existing receivers that do not verify signatures are unaffected and signature-enabled receivers apply the prefix construction to verify. This is the scheme Stripe uses (they call it `v1`).

### Why `sha256=` prefix on the signature value?

The prefix makes the algorithm explicit in the header value and provides forwards compatibility — a future `sha512=` variant can coexist in the same header without changing the header name. GitHub and Stripe both use this convention.

### Why HTTPS-only for webhook destinations?

The signing guarantee is meaningless without transport-layer encryption: HMAC proves the payload wasn't tampered with, but only if the attacker cannot see the ciphertext and therefore cannot forge a signature with the captured payload. An HTTP destination exposes both the payload and the signature in cleartext, defeating the scheme entirely. `https://` is enforced at domain invariant level (domain.md §5.2.2), FormRequest validation, and `WebhookEndpoint` construction.

### Alternatives considered

**Symmetric AES-CBC payload encryption**: Provides confidentiality, not just integrity. Rejected because receivers would need to decrypt the payload before processing it — adding latency and implementation complexity. EventPulse sends notifications; confidentiality of the payload content is the caller's concern, not EventPulse's. Integrity (origin + tamper detection) is what matters here.

**WebSub (HMAC + shared secret in Link header)**: Standardised but adds subscription management overhead that is out of scope. HMAC-SHA256 as described above is functionally equivalent for the point-to-point case.

**OIDC / signed JWTs**: Appropriate for federated identity, not for webhook origin proof. Would require the receiver to perform a JWKS fetch and validate claims — an unnecessary dependency for this use case.

---

## Consequences

### Positive

- Receivers can verify EventPulse payloads with a few lines of standard-library code in any language.
- The scheme is observable in logs without exposing the secret (the signature header is logged in structured output; the secret is not).
- The `WebhookEndpoint` widening (adding `signingSecret`) required no changes to `WebhookChannelDriver`'s dispatch, retry, or classification logic.
- Inbound signing verification (if EventPulse later receives callbacks) can reuse the exact same algorithm.

### Negative

- The ±5-minute replay window is not configurable per destination. A destination with very strict replay requirements would need a separate mitigation (e.g. an idempotency check on their side). This is an acceptable tradeoff for Phase 1 and is documented.
- The secret is AES-256-CBC encrypted with the Laravel app key. If the app key is rotated without re-encrypting `secret_encrypted` rows, all webhook signatures will break until rows are re-encrypted. This is a known operational risk documented in ADR-0007 §"Triggers to revisit." A re-encryption command is a Phase 2 item.
- There is no built-in mechanism to rotate the webhook signing secret per-destination. An operator who suspects a secret is compromised must disable the destination and create a new one. Destination-level secret rotation is deferred to Phase 2.

---

## Triggers to revisit

- **Secret rotation per destination**: When operators ask for in-place rotation without destination recreation, add a `POST /webhook-destinations/{id}/rotate-secret` endpoint that re-encrypts the column and returns the new plaintext secret once.
- **App key rotation re-encryption**: When the app key is rotated, a `php artisan eventpulse:reencrypt-webhook-secrets` command should re-encrypt all `secret_encrypted` rows with the new key. Trigger: the first time an ops rotation actually happens.
- **Configurable replay window**: If a destination owner requests a shorter window (< 5 minutes) or a longer one, promote the tolerance to a per-destination column. Trigger: a real operator requirement.
- **`Retry-After` honouring**: When a webhook receiver returns `429 Too Many Requests` with a `Retry-After` header, the retry should respect that delay instead of using the channel's default backoff. The `DispatchNotificationJob` already has a comment noting this; the blocker was not having a real resolver. Now that destinations exist, this can be added as a small enhancement to the job's failure handler.
