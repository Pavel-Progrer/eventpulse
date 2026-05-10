# Day 9 README additions

This file describes the three changes to apply to `README.md` for Day 9.

---

## 1. Update the "What it does" bullet (line 25)

Existing line:
```
- Signs outbound webhooks (HMAC) and verifies inbound webhook callbacks
```

No change needed — this bullet already describes the completed feature. ✓

---

## 2. Add a "Webhook destinations" subsection after "Idempotency and asynchronous dispatch"

Insert the following block after the "Idempotency and asynchronous dispatch" section (before the "---" separator before "## Testing"):

```markdown
### Webhook destinations

Before a webhook notification can be dispatched, the target URL must be registered
as a **webhook destination**.

```bash
# Register a destination (returns the id and — once only — the plaintext secret).
curl -X POST http://localhost:8080/api/v1/webhook-destinations \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://your-service.example.com/hooks",
    "secret": "$(openssl rand -hex 32)",
    "name": "My service"
  }'
# → 201 {"id": "...", "url": "...", "status": "active", "secret": "...", ...}
#   Save the secret now — it is never returned again.
```

The returned `id` is what you pass as `recipient` in `POST /notifications` with `channel: webhook`.

```bash
# List destinations owned by the authenticated key.
curl http://localhost:8080/api/v1/webhook-destinations \
  -H "Authorization: Bearer $API_KEY"
# → 200 {"data": [...], "meta": {"next_cursor": null}}

# Disable a destination (soft delete — history is preserved).
curl -X DELETE http://localhost:8080/api/v1/webhook-destinations/$DESTINATION_ID \
  -H "Authorization: Bearer $API_KEY"
# → 204 No Content
```

#### Outbound signing

Every outbound webhook request carries two headers:

```
X-EventPulse-Timestamp: 1714298400
X-EventPulse-Signature: sha256=<hex-encoded HMAC-SHA256>
```

The signature is computed over `{timestamp}.{json_request_body}` using the shared secret.
Receiver verification (any language):

```python
import hashlib, hmac, time

def verify(secret: str, body: bytes, timestamp: str, signature: str) -> bool:
    # Reject stale timestamps (±5 minute window).
    if abs(time.time() - int(timestamp)) > 300:
        return False
    signed = f"{timestamp}.".encode() + body
    expected = "sha256=" + hmac.new(secret.encode(), signed, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

Full rationale in [ADR-0008](./docs/adr/0008-webhook-signature-verification.md).
```

---

## 3. Add Day 9 to the "Roadmap" progress note in "What it does" (no structural change)

The "What it does" section already lists HMAC signing as a completed feature (`-`). No roadmap row changes — Day 9 is still Phase 1 (`v0.1.0`).

---

## Full diff summary

| Section | Change |
|---|---|
| "What it does" bullet on HMAC | Already accurate — no edit. |
| After "Idempotency and asynchronous dispatch" | Add "Webhook destinations" subsection (curl examples + signing spec). |
| ADR list in "Documentation" | Add `[ADR-0008](./docs/adr/0008-webhook-signature-verification.md)` entry. |

### ADR-0008 entry to add to the Documentation list

```markdown
- **[ADR-0008 — Webhook signature verification](./docs/adr/0008-webhook-signature-verification.md)** — HMAC-SHA256 outbound signing scheme, secret storage, and replay-attack mitigation
```
