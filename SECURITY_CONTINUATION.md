# Security & Deployment Status

Last updated: 2026-08-22 (Asia/Manila)

> This file replaces an earlier mid-work handoff that described interrupted
> agents and a "not release-ready" tree. That state is obsolete. The security
> hardening it listed as unfinished has since landed and been verified. The
> application security code is **complete and considered frozen** — see
> "Do not keep hardening" below. What remains before production is
> **environment/operations setup, not code.**

## Current gate (verified)

- **Tests:** 129 passed, 0 failures (`php artisan test`), on SQLite.
- **Build:** production Vite build passes (`npm run build`).
- **Dependencies:** 0 known advisories (`composer audit`, `npm audit`).
- **Preflight:** `php artisan security:check` → 24 pass, 8 fail, 2 warn. **All 8 failures are environment configuration** (HTTPS, secure cookies, Postgres, DB TLS, Redis sessions, removing `ADMIN_PASSWORD`), not code. See the checklist below and `DEPLOYMENT_CHECKLIST.md`.
- **Lint:** feature/changed files are Pint-clean. Three pre-existing files carry cosmetic formatting nits (`Admin/FormController`, `Admin/ParticipantController`, `Providers/AppServiceProvider`) — cosmetic only, no behavior impact.

## Security posture — implemented and in place

**Administrator authentication & session**
- Authorization re-checked on every request; inactive/demoted accounts rejected and logged out immediately.
- Mandatory TOTP MFA (encrypted secret, single-use time steps, hashed single-use recovery codes).
- Recent-password step-up on every sensitive mutation; persistent "remember me" disabled by default.
- Encrypted, short-lived sessions; session ID rotated at each trust boundary; `admin:disable` and `admin:revoke-sessions` commands exist for emergency response.
- Layered rate limits on login, MFA challenge, sensitive actions, and participant link requests.

**Participant / public surface**
- One-time, expiring email-ownership magic links; raw tokens never stored (only keyed hashes); token rides the URL `#fragment` so it never reaches server logs.
- Public form share tokens (~2^124 entropy) and certificate verification codes resolved by lookup hash; encrypted at rest.
- Backend-enforced access on every form (no URL bypass); one participant per (webinar, email); a public form cannot overwrite an existing identity.
- Generic, oracle-proof responses for unknown/erased/invalid identities; sanitized provider/error messages.

**Data protection & lifecycle**
- Participant answers, email recipients/payloads/subjects/errors, certificate names/reasons, eligibility overrides, and MFA secrets encrypted at rest.
- Privacy erasure scrubs PII, removes private PDFs, clears delivery content, and does not recreate an identifier in the audit event.
- `retention_due_at` committed once and cannot be cleared or extended through ordinary edits; webinar deletion writes an irreversible tombstone before cancelling work and deleting files.
- Certificate issuance: participant/webinar locks, a DB-level issuance guard, private-storage checks, `processing`-row-before-I/O crash recovery, and compensating orphan cleanup with audit on failure.

**Delivery & operations**
- Durable email outbox: `EmailOutboxRelay` + `security:relay-email-outbox` (scheduled every minute) redispatches any committed-but-unsent delivery, so a crash after commit cannot strand a magic link or certificate email.
- Brevo idempotency via a stable UUID `headers.idempotencyKey`; retries limited to transient failures so a `duplicate_parameter` response resolves as terminal success.
- Bounded, validated retention/prune commands (audit logs, participant access, email deliveries, failed jobs, batches) on a daily/hourly schedule.

**Transport & headers**
- CSP (enforced outside local), HSTS, exact trusted-host checks, no-store on dynamic responses, frame blocking, `nosniff`, request-nonce inline scripts.

**Concurrency (validated this session)**
- Submission path uses a shared form lock (parallel submitters) + per-participant exclusive lock; the webinar-wide lock was removed so the four forms of an event no longer serialize behind one row.
- Proven correct under real parallel load: 25 concurrent distinct-participant submissions all succeed on a real RDBMS; 25 concurrent same-participant attempts yield exactly one record (no double submission, no corruption). SQLite is the only environment that drops writes — which is why Postgres is a hard requirement.

## Do not keep hardening (read this)

The application security is at a mature, well-tested state. The remaining candidate items buy small security gains for real regression risk in a security-critical, already-good codebase. **The correct engineering decision is to freeze the security code and not continue adding to it.** Further changes to these files should be driven by a *specific discovered defect*, not by a desire for more coverage.

### Genuinely open, but optional and non-blocking
- **Key-rotation re-encryption command.** There is no command to re-encrypt all encrypted attributes under a new `APP_KEY`. Only needed the day you actually rotate the key; not a deployment blocker. Build it (idempotent, dry-run, verify-decryptability, no plaintext output) *if and when* a rotation is planned.
- **Continued Postgres validation.** One real Postgres-portability bug was found and fixed this session (a migration using `SELECT * … GROUP BY`, which SQLite tolerates but Postgres rejects). Re-run the full migration set against the exact production Postgres version once provisioned.

## Trust domain / product boundary (unchanged, still true)

The system has **one global administrator trust domain**: any active administrator can access and mutate every webinar, participant, certificate, export, rule, and template. There is no tenant/organization ownership boundary or least-privilege role split.

- Safe to operate for **one organization with a small, mutually trusted admin group**, after the deployment gates below pass.
- **Not** to be offered as a multi-tenant service to unrelated clients/departments without adding organization IDs, tenant-scoped uniqueness and route binding, per-query/mutation policies, and roles.
- These are technical controls, not a statement of legal/regulatory compliance. Lawful basis, retention exceptions, data-subject procedures, breach response, processor agreements, and jurisdictional notices still need an authorized privacy/legal owner.

## Mandatory production blockers (environment/ops — the real gate)

Do not accept real participant data until all are complete. Each maps to a line in `DEPLOYMENT_CHECKLIST.md`.

- Remove `ADMIN_PASSWORD` from runtime `.env` (currently present); provision the admin privately, enroll MFA. Rotate the Brevo/mail credential; never copy it into docs, logs, or commits.
- Canonical HTTPS host, `SESSION_SECURE_COOKIE=true`, exact trusted-proxy IP/CIDR allowlist, valid TLS at every hop.
- Managed **PostgreSQL** with verified TLS (`DB_SSLMODE=verify-full`); dedicated Redis for sessions/cache/rate limits (`SESSION_CONNECTION=session`); async queue workers with separate email/batch visibility windows below their job timeouts.
- Run the scheduler continuously; run separate email and certificate workers.
- Back up and rehearse restore before any migration or key rotation.
- Build the release from a clean checkout: do not ship `storage/logs`, `storage/framework/sessions`, the local SQLite DB, caches, or test-generated certificates. Purge old local logs/sessions first.
- Run `php artisan security:check` against the **cached production config** and require zero failures.

## Historical deployment summary (not a runbook)

Use [`DEPLOYMENT_CHECKLIST.md`](DEPLOYMENT_CHECKLIST.md) as the sole
authoritative production path. It supersedes the abbreviated historical summary
below and requires the separate disposable PostgreSQL migration proof before
any production migration. It also defines the release, systemd, Apache,
Cloudflare, MFA, production-security-gate, and controlled-testing order.

1. `php artisan optimize:clear`
2. `vendor/bin/pint` · `php artisan test` · `npm run build` — all green.
3. Provision Postgres + Redis; fill production `.env` (see `DEPLOYMENT_CHECKLIST.md`).
4. `php artisan migrate --force` against a fresh Postgres (and rehearse an upgrade from a realistic snapshot).
5. `php artisan optimize` then `php artisan security:check` in a production-parity environment — zero failures.
6. Deploy; run the scheduler + workers; verify one real end-to-end registration and one concurrent-submission smoke test on the live stack.

## References

- Laravel authorization — https://laravel.com/docs/12.x/authorization
- OWASP Authentication — https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- OWASP Session Management — https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- OWASP Content Security Policy — https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html
- Brevo transactional email — https://developers.brevo.com/reference/send-transac-email
- Brevo idempotency — https://developers.brevo.com/docs/heterogenous-versions-batch-emails
