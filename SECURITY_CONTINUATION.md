# Security and Privacy Hardening — Continuation Handoff

Last updated: 2026-08-20 (Asia/Manila)

## Stop point

Work was intentionally stopped at the user's request because the session budget was nearly exhausted. The shared tree is **not currently release-ready**. Three agents were interrupted while working, so inspect the files named below for partial edits before continuing.

The last clean independent gate, before the newest changes, was:

- 116 tests passed / 929 assertions
- production Vite build passed
- isolated fresh migration of 15 migrations passed
- Pint still had four formatting failures at that earlier point

Those results are stale. A complete clean gate has **not** run after the latest lifecycle, timezone, queue, migration, and form changes.

## Security work already implemented

- Admin authorization is rechecked per request; inactive/demoted accounts are rejected.
- Administrator MFA is default-on and required through middleware.
- Persistent admin login is disabled; sessions are shorter and production defaults require secure/encrypted cookies.
- Sensitive admin routes use recent-password step-up protection.
- Login, MFA, sensitive actions, and participant link requests have layered rate limits.
- Public participant access uses one-time, expiring magic links; raw tokens are not stored.
- Public bearer tokens and certificate verification codes use lookup hashes and encrypted values.
- Participant answers, email recipients/payloads/errors/subjects, certificate names/reasons, overrides, and MFA secrets are encrypted at rest.
- Participant erasure scrubs PII, removes private PDFs, clears delivery content, and avoids recreating a participant identifier in the erasure audit event.
- Certificate verification after erasure is unavailable by default. Enabling it is explicitly treated as linkable/pseudonymous retention.
- Webinar deletion now establishes an irreversible tombstone before cancelling work and deleting files.
- `retention_due_at` is committed and cannot be cleared or extended through ordinary edits.
- Certificate issuance has participant/webinar locks, a database issuance guard, private storage checks, and compensating cleanup reporting.
- Email delivery has a stable UUID idempotency key and Brevo's documented `headers.idempotencyKey` shape; provider errors are sanitized.
- CSP, HSTS, trusted-host checks, no-store responses, frame blocking, and browser isolation headers are implemented.
- Participant admin search uses POST plus encrypted session state so names/emails do not enter URLs and access logs.
- Production preflight validates many deployment requirements through `php artisan security:check --production`.
- Retention commands continue past a failed participant and alert on overdue records or missing deadlines.
- Case-normalized email identity migrations and fail-closed user defaults were added.
- Terminal email, failed-job, batch, access-token, audit, and privacy pruning schedules were added or tightened.

## Last root edits (not yet tested)

These edits were applied immediately before stopping:

- `app/Models/Webinar.php`
  - new webinars source their retention default from `config('webinar.default_retention_days')`, clamped to 1–3650 days.
- `app/Http/Controllers/Admin/WebinarController.php`
  - browser `datetime-local` values are interpreted in the submitted IANA timezone and converted to UTC.
  - DST-normalized nonexistent local times are rejected.
  - committed-deadline comparison now handles the converted datetime object.
- `resources/views/admin/webinars/form.blade.php`
  - stored UTC schedule values render in the webinar timezone.
  - retention default uses configuration rather than a hard-coded 7.
- `resources/views/admin/webinars/show.blade.php`
  - the displayed start time renders in the webinar timezone.
- `app/Services/PublicFormResolver.php`
  - public forms now require an exactly `published` webinar and a non-null, future retention deadline.
- `app/Models/Form.php`
  - `acceptsResponses()` now fails closed when the webinar has no committed retention deadline.

Expected consequence: older tests that directly create a published webinar without `ends_at` / `retention_due_at` will now fail and must be corrected to create a valid committed schedule. Do not weaken the fail-closed behavior merely to preserve those fixtures.

## Interrupted work — inspect before editing

### Form consistency agent

The agent was interrupted while implementing:

- authoritative transaction-time reload and revalidation of a public form definition;
- consistent webinar → form → child locks for admin field/question edits;
- bounded numeric input format/magnitude;
- form `opens_at` / `closes_at` timezone conversion and rendering;
- focused tests.

Inspect at least:

- `app/Http/Controllers/PublicFormController.php`
- `app/Http/Controllers/Admin/FormController.php`
- `resources/views/admin/forms/edit.blade.php`
- relevant tests and any new migrations

The required invariant is that admin definition edits cannot commit between the participant's authoritative validation and answer inserts. Use one lock order or a persisted definition version; do not rely only on an early request validation.

### Delivery recovery agent

The agent was interrupted while implementing a durable email outbox relay so a crash after committing `EmailDelivery` but before queue dispatch cannot strand a magic link or certificate email.

Inspect at least:

- `app/Services/NotificationService.php`
- `app/Models/EmailDelivery.php`
- `app/Jobs/SendTransactionalEmail.php`
- `app/Console/Commands/`
- `routes/console.php`
- recent migrations and tests

The relay must be idempotent, concurrency-safe, and refuse expired, scrubbed, erased, archived, deleting, or overdue identities. Redis/SQS dispatch cannot be considered atomic with the SQL transaction.

### Red-team review

The read-only red-team agent was interrupted after reporting the remaining findings below. No final red-team recheck was performed.

## Highest-priority unfinished fixes

1. **Complete fail-closed lifecycle checks everywhere.**
   - `ParticipantMagicLinkService` still had several `retention_due_at IS NULL OR future` queries and `status != archived` checks. Require `status = published`, a non-null future deadline, and a freshly locked/rechecked form.
   - `CertificateService`, batch handling, and `SendTransactionalEmail` must reject a missing deadline as well as an expired one. Certificate issuance may allow only the explicitly approved event statuses.
   - Keep the global lock order consistent: webinar → form/participant → child records.

2. **Finish form-definition race protection.**
   - Public submit currently reloads some state, but every admin form/field/question mutation must serialize against the same form lock or advance an optimistic definition version checked in the submit transaction.
   - Bound numeric fields (for example, a fixed maximum digits/scale), and document PHP/web-server/edge body-size limits.

3. **Make certificate file creation crash-recoverable.**
   - A hard kill after `Storage::put()` but before the surrounding DB transaction commits can leave an untracked PII PDF.
   - Prefer a committed `processing` inventory row with a deterministic path before external I/O, then render/write outside that transaction and finalize in a second transaction.
   - Add a scheduled stale-processing/orphan reconciliation command and fault-injection tests.
   - After locks are acquired, discard stale eager-loaded eligibility/template relations and load rules, submissions, overrides, and the active template fresh.

4. **Finish the durable email outbox.**
   - A committed pending delivery must always be redispatched by a scheduled/continuous relay if the immediate callback is lost.
   - Keep terminal `sent`/`cancelled` states immutable and preserve provider idempotency behavior.

5. **Complete timezone correctness.**
   - Test webinar schedule storage/display in `Asia/Manila` and a DST timezone such as `America/New_York`.
   - Finish form open/close timezone handling.
   - Confirm UTC storage and local rendering for create, update, edit, show, retention calculation, magic-link acceptance, and scheduled erasure.

6. **Strengthen production preflight.**
   - Validate `APP_KEY` and every `APP_PREVIOUS_KEYS` entry by decoding `base64:` values and checking the exact configured cipher key length via Laravel's `Encrypter::supported`; reject placeholders without printing key material.
   - Require a shared durable rate-limit/cache store in production (Redis or an approved shared database store), never `array`, `null`, or per-node file cache.
   - Add an integration test proving forwarded HTTPS is trusted only from configured proxy IPs. Laravel's default global middleware includes `TrustProxies`; this tree currently applies the cached allowlist through `TrustProxies::at(...)` in `AppServiceProvider`. Verify the behavior instead of moving back to direct `env()` access.

7. **Fix operational safety gaps.**
   - `PruneEmailDeliveries --days` must reject zero, negative, non-numeric, or out-of-policy values instead of clamping them silently.
   - Add deterministic session pruning/invalidation. Production should use a dedicated Redis session connection; Redis TTL handles ordinary expiry, while an explicit all-session invalidation must target only that dedicated connection.
   - Forbid `system:reset` in production (or require a separately designed break-glass workflow); do not print retained admin emails.
   - Add safe `admin:disable` / session-revoke procedures with an audit event.
   - Couple sensitive mutations and their audit inserts transactionally where feasible.

8. **Plan key rotation, not only previous-key reads.**
   - Add a resumable/idempotent re-encryption command for all encrypted model attributes, with dry-run, counts, decryptability verification, backup/rollback instructions, and no secret/plaintext output.
   - Remove retired keys only after every row has been verified under the new key.

9. **Review migrations on the production database engine.**
   - The test suite uses SQLite, which does not exercise PostgreSQL row-lock behavior or all DDL/backfill semantics.
   - Test the exact PostgreSQL major version, Redis/database queues, and private object storage.
   - Migrations that add encryption/hash/lifecycle columns must be restart-safe. The active-certificate guard intentionally fails if legacy duplicate valid certificates or unfinished processing rows exist; remediate those with an explicit audited policy rather than silently choosing one.

## Security ruling / product boundary

This application currently has **one global administrator trust domain**. Any active administrator can access and mutate every webinar, participant, certificate, export, rule, and template. There is no organization/tenant ownership boundary and no least-privilege role split.

Therefore:

- It may be operated for **one organization with a small, mutually trusted administrator group**, after the deployment gates below pass.
- It must **not** be offered to unrelated clients, departments, or partner organizations as a multi-tenant service yet. That requires organization IDs, tenant-scoped uniqueness and route binding, policies on every query/mutation, and roles such as viewer, data steward, certificate issuer, and security administrator.
- These controls reduce technical risk; they are not a declaration of legal or regulatory compliance. Data purpose, lawful basis/consent, retention exceptions, data-subject procedures, breach response, processor agreements, and jurisdiction-specific notices still require an authorized privacy/legal owner.

## Mandatory production deployment blockers

Do not accept real participant data until all are complete:

- Revoke/rotate the live-looking Brevo/mail credential found in the ignored local `.env`. Never copy it into documentation, logs, tickets, or commits.
- Replace the weak bootstrap administrator password, provision the administrator privately, enroll MFA, then remove `ADMIN_PASSWORD` from runtime configuration.
- Use a canonical HTTPS hostname, secure cookies, exact trusted proxy IP/CIDR allowlists, and valid TLS at every hop.
- Use managed PostgreSQL with verified TLS, dedicated Redis sessions/cache/rate limits, asynchronous queue workers with separate batch/email visibility settings, and private encrypted certificate/object storage.
- Run the scheduler continuously and run separate email and certificate workers with timeouts below their configured visibility windows.
- Back up and rehearse restore before data migrations or key rotation.
- Build release artifacts from a clean checkout. Do not ship local `storage/logs`, `storage/framework/sessions`, the local SQLite database, caches, or test-generated certificate files.
- Purge the existing legacy local log/session files before sharing or deployment; earlier count-only inspection found email-like data in old logs and hundreds of stale file sessions.
- Run `php artisan security:check --production` against the **cached production configuration** and require zero failures.

## Safe continuation sequence

1. Inspect recent file timestamps/diffs and the interrupted-agent scopes above.
2. Finish the lifecycle, form consistency, outbox, and certificate crash-recovery changes.
3. Add/update focused tests, especially race/fault, missing-deadline, timezone/DST, proxy, invalid-key/cache, prune-option, and outbox recovery tests.
4. Clear local cached configuration before tests:

   ```text
   php artisan optimize:clear
   ```

5. Run formatting and the complete test/build gate:

   ```text
   vendor/bin/pint
   php artisan test
   npm run build
   ```

6. Test a fresh migration and an upgrade from a realistic legacy PostgreSQL snapshot. Do not run production migration until backups and duplicate/integrity checks pass.
7. Compile production caches and rerun tests/preflight in a clean, production-parity environment:

   ```text
   php artisan optimize
   php artisan security:check --production
   ```

8. Perform a final read-only red-team pass, dependency audits, secret scan (names/locations only), route/authz scan, storage exposure check, and browser security-header test.
9. Only after all gates pass, run the approved production migration/deployment runbook and rotate/invalidate sessions as planned.

## Useful official references already used

- Laravel authorization: https://laravel.com/docs/12.x/authorization
- Laravel middleware API: https://api.laravel.com/docs/12.x/Illuminate/Foundation/Configuration/Middleware.html
- OWASP Authentication Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- OWASP Session Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- OWASP Content Security Policy Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html
- Brevo transactional email endpoint: https://developers.brevo.com/reference/send-transac-email
- Brevo idempotency guidance: https://developers.brevo.com/docs/heterogenous-versions-batch-emails

