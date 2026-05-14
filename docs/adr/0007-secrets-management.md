# ADR-0007: Secrets management approach

- **Status:** Accepted
- **Date:** 2026-05-06
- **Deciders:** [author]
- **Related:** ADR-0001 (scope and exclusions), ADR-0005 (webhook signature verification)

## Context

EventPulse handles several categories of sensitive values:

- **Application secrets** — Laravel application key, database password, Redis password, API keys for external services (Anthropic, OpenAI, SMTP provider).
- **Tenant-provided secrets at rest** — webhook destination secrets supplied by API clients, stored in `webhook_destinations.secret`. These must be recoverable in plaintext at dispatch time to compute outbound HMAC signatures.
- **Tenant-provided secrets never stored** — API key secrets. The raw secret is shown once at key creation, then only its Argon2id hash is persisted. Signature verification uses the hash-comparison path, not a stored-plaintext comparison.

Two separable questions fall under "secrets management":

1. **How does the running process obtain its application secrets?** (Where does `DATABASE_PASSWORD` come from when Laravel boots?)
2. **How are tenant-provided secrets protected at rest?** (What protects `webhook_destinations.secret` if the database is compromised?)

These are often conflated in discussions of "secret management" but have different threat models, different failure modes, and different reasonable answers at different maturity stages. This ADR decides both, and deliberately defers a third question — dynamic credential issuance and rotation — to a later revision.

## Decision

### Application secrets: environment variables via a pluggable provider interface

At runtime, all application secrets are read from environment variables through a `SecretsProvider` abstraction. The v1.0 implementation is `EnvSecretsProvider`, which reads directly from `$_ENV`. No secrets are read from files in the repository, from the database, or from third-party services in v1.0.

```php
interface SecretsProvider
{
    public function get(string $key): string;
    public function has(string $key): bool;
    public function rotate(string $key): void;  // no-op for env-based provider
}
```

The provider is bound in a service provider and resolved via the container everywhere a secret is needed. Direct `env()` calls are confined to `config/*.php` files and the provider implementation itself; application code never reads environment variables directly.

### Tenant secrets at rest: Laravel encrypter with `APP_KEY`

Webhook destination secrets are encrypted at rest using Laravel's built-in encrypter (AES-256-CBC with HMAC, keyed from `APP_KEY`). The encrypter is the correct primitive here: the service legitimately needs the plaintext at dispatch time to sign outbound webhooks, so a hash would not work, and building a custom AEAD scheme would be strictly worse than using the framework's well-vetted implementation.

API key secrets, by contrast, are stored as Argon2id hashes and never retrieved in plaintext. Signature verification computes the HMAC on the server side using the constant-time hash equality path (see ADR-0005).

### Operational handling

- `.env.example` is checked in; `.env` is not, and is listed in `.gitignore`.
- Pre-commit hook runs `gitleaks` over the diff; any detected secret blocks the commit.
- CI runs `gitleaks` over the full history on every push as a secondary defense.
- Production deployments are expected to inject environment variables via the orchestrator's native secret mechanism (ECS task definition secrets, Kubernetes secrets, Fly.io secrets). EventPulse does not prescribe which.
- The Docker image is built with `--secret` mounts for any build-time credentials (e.g., private package registries); no secrets are baked into image layers. This is verified by CI via `dive` and `trivy config` on the built image.

### Explicitly deferred: Vault-backed dynamic credentials and automated rotation

HashiCorp Vault (or an equivalent secrets-management platform) with dynamic database credentials and automated rotation is **not** implemented in v1.0. This is a deliberate exclusion, not an oversight.

The `SecretsProvider` interface is designed so that a future `VaultSecretsProvider` can be introduced without touching application code. `rotate()` is included in the interface specifically to make this extension point explicit — in the env-based implementation it is a documented no-op, but the method signature exists so that consumers may be written to tolerate rotation-capable providers.

## Rationale

### Why environment variables for v1.0

Environment-variable-based secret loading is the standard that every modern orchestration platform (ECS, Kubernetes, Nomad, Fly.io, Render, Railway) natively supports. For the deployment models this service is realistically deployed into, the orchestrator already solves the "get a secret into a running process without putting it on disk" problem. Adding Vault as an application-level dependency would duplicate work the deployment platform already does, and would constrain deployability to environments that run Vault — a significant reduction in portability for no net security gain in the common case.

Environment variables have a known downside: they are visible via `/proc/{pid}/environ` to any process running as the same user. This is a real concern in multi-tenant container hosts or on shared-user systems. For EventPulse's deployment model (dedicated container per service instance, one user per container), the exposure is equivalent to any other file-based secret approach.

### Why defer Vault

Adding Vault to v1.0 would fail the architectural thesis established in ADR-0001: inclusions must earn their place against the simplest thing that correctly solves the problem. Vault solves three problems that v1.0 does not have:

1. **Dynamic credential issuance.** Useful when many services share a database and per-service credentials with least-privilege access are desirable. EventPulse has a single service connecting to its own database; no dynamic issuance is needed.
2. **Automated rotation.** Useful when compliance frameworks require demonstrable rotation cadence (SOC 2 CC6.1, PCI DSS 8.6), or when credential compromise blast radius is large. Neither applies to a portfolio reference implementation.
3. **Centralized audit of secret access.** Useful in multi-team environments where knowing who read what secret when is a compliance requirement. Not a concern for a single-team service.

Implementing Vault without any of these driving concerns would be demonstration for its own sake — the kind of addition that looks like résumé-driven design rather than judgment. The cost is real: Vault adds an operational dependency that must itself be highly available (or the service cannot boot), requires its own backup and disaster-recovery story, and introduces a second authentication system (Vault tokens or AppRole credentials) whose compromise is catastrophic.

### Why the provider interface exists anyway

Deferring Vault without preparing for it would be short-sighted. The `SecretsProvider` interface costs approximately 30 lines of code and zero runtime overhead, and it changes the story of adoption from "refactor the service to use Vault" to "add a new provider class and a bootstrap config entry." This is the same pattern that applies elsewhere in the codebase — the LLM provider chain (ADR-0010), the channel dispatcher, the rate-limiter backend. Consistency of that pattern is itself a senior signal.

### Why the Laravel encrypter for tenant secrets

The alternatives considered for encrypting `webhook_destinations.secret` at rest:

- **PostgreSQL `pgcrypto`** — moves the encryption boundary into the database, which means a database compromise exposes both the ciphertext and (if configured poorly) the key. Also couples the encryption choice to the storage engine.
- **Application-level with a custom AEAD scheme** — strictly worse than using the framework's; the failure modes of hand-rolled crypto are well-documented and always rediscovered.
- **Laravel encrypter (chosen)** — AES-256-CBC with HMAC, well-vetted, rotatable via `APP_KEY` rotation (Laravel supports `APP_PREVIOUS_KEYS` for decryption fallback during rotation). Keys the encryption at the application layer, so a database-only compromise yields ciphertext without the key.

`APP_KEY` rotation is supported by Laravel natively and is a realistic operational task that could be scripted against the env-based provider today. It does not require Vault.

## Consequences

### Positive

- The service runs on any platform that supports environment-variable injection, which is every major orchestration platform.
- No operational dependency on an external secrets-management service; the service has one fewer thing that can be down.
- The provider interface makes future Vault adoption a localized change, not a refactor.
- The decision is defensible under the architectural thesis of ADR-0001 — simplicity earns its place by being consistent with every other exclusion.
- Tenant secrets are protected at rest by a well-vetted primitive, with a realistic rotation story.

### Negative

- No automated rotation of application secrets. For the deployment model assumed, rotation is a manual operational procedure: update the orchestrator's secret store, redeploy. Environments with strict rotation SLAs would need to automate this, or adopt Vault.
- No centralized audit of which secrets were read when. For a single-service deployment this is not a practical concern; for a fleet it would be.
- `APP_KEY` compromise exposes all encrypted tenant secrets. This is mitigated by the same operational controls that protect any single-key encryption: restrict read access, rotate on suspicion of compromise, use separate keys per environment. Acceptable at this scale; unacceptable at multi-tenant scale where blast radius would motivate per-tenant keys or a KMS-backed approach.

### Triggers to revisit this decision

This ADR will be superseded (not amended) when any of the following become true:

- **Compliance driver:** pursuit of SOC 2 Type II, PCI DSS, HIPAA, or ISO 27001 certification where automated rotation and access audit are controls being claimed.
- **Scale driver:** deployment of EventPulse as a multi-tenant service where blast radius from a single `APP_KEY` compromise would span multiple customers.
- **Integration driver:** a client engagement that specifies Vault, AWS Secrets Manager, or GCP Secret Manager as the mandated secrets backend. In that case, a concrete provider implementation replaces the env-based one; the interface was designed for this.
- **Operational driver:** rotation cadence requirements that exceed what manual procedures can reliably satisfy (typically < 90 days with an audit trail requirement).

The expected implementation path when revisited: add a `VaultSecretsProvider` (or equivalent) that implements the existing interface, move `rotate()` from no-op to a real rotation operation, wire it via a configuration flag, and deploy gradually — one secret category at a time, starting with the database password (highest rotation value, lowest coupling to application code).

## Notes

The shape of this ADR — deciding the immediate question, naming what's deferred, designing for the deferral to be cheap — is the pattern to follow for other infrastructure capabilities that may be tempting to add early: observability backends (OTLP exporter interface with a no-op default), circuit breakers (policy interface with pass-through default), feature flags, and so on. Each deserves its own ADR at the point the interface is introduced.
