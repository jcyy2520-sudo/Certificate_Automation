# Security, Privacy, and Production Operations

This document is the deployment gate and operating policy for installations that handle real participant information. The application includes security and privacy controls, but safe operation also depends on the hosting environment, administrators, vendors, backups, monitoring, and the rules that apply in the deployment jurisdiction.

Passing the automated checks is not a certification, penetration test, or guarantee of legal compliance. The deploying organization remains responsible for its privacy notice, lawful basis, contracts, retention decisions, data-subject procedures, incident notifications, and any sector-specific requirements. Have qualified security and legal reviewers assess the final production design.

## Security boundaries

- Administrators have access to participant identity, responses, scores, exports, and certificates. Give each administrator a separate account; never share accounts.
- A form share link identifies one form, not a participant. By default, the participant must prove control of the submitted email inbox through a short-lived, one-time magic link before the form is unlocked.
- An administrator can disable ownership verification for a specific trusted, low-stakes webinar. That mode still requires a registered email for later forms, but cannot stop someone who knows that address from impersonating its owner. Do not use it where identity assurance or consequential scoring matters.
- Email verification proves current control of an inbox. It does not prove a legal identity, attendance, age, or that the mailbox has not been compromised.
- The transactional-email provider and recipient mailbox are part of the authentication trust boundary: they necessarily receive the destination address and raw magic link. Provider and mailbox compromise can therefore expose participant access.
- Certificate verification is deliberately public to anyone holding a verification code. It returns only the event, issue date, and validity state—not participant contact details, scores, or answers.
- Application-layer encryption does not replace database access controls, encrypted disks and backups, TLS, network isolation, or vendor security.
- Application rate limits reduce automated abuse but do not replace edge rate limiting, denial-of-service protection, bot controls, or capacity planning.

## Controls enforced by the application

### Administrator access

- There is no self-service administrator registration. Accounts are provisioned interactively with `php artisan admin:create`; the password is entered at a hidden prompt and must contain at least 15 characters.
- Authentication and two-factor challenges are rate limited. Sessions are regenerated at authentication boundaries, and a password change invalidates sessions that still carry the old password hash.
- Every protected request rechecks that the account is active and still has the administrator role.
- TOTP multi-factor authentication is mandatory by default. A TOTP time step cannot be replayed, recovery codes are stored encrypted and hashed and are consumed once, and sensitive two-factor changes require the current password.
- Persistent administrator login is disabled by default. Previously issued remember tokens are invalidated by the hardening migration.
- Participant CSV export requires recent password confirmation by default. Exported spreadsheet cells are neutralized when their content could be interpreted as a formula.
- Manual participant creation and authoritative participant-name correction require recent password confirmation. High-frequency attendance toggles and cosmetic certificate-design changes remain ungated by deliberate decision; both actions are audited.
- Resource bindings are scoped to their parent webinar or form so an identifier from one resource cannot be substituted into another webinar's route.

### Participant access

- Form share tokens and certificate verification codes are encrypted at rest and have separate hashes for lookup.
- Participant magic-link credentials use high-entropy random values. Only a keyed digest is stored; the raw credential is placed in the URL fragment so it is not sent in the initial HTTP request or ordinary server access logs.
- Opening a magic link is non-mutating. A CSRF-protected POST consumes it, regenerates the session, binds access to the participant and webinar, and invalidates sibling links. Expired, used, malformed, or mismatched links fail closed.
- Consuming a link also issues an encrypted, HTTP-only webinar pass. Its lifetime defaults to 24 hours and is capped by the webinar retention deadline. Every use revalidates the participant, email fingerprint, webinar state, and retention deadline against the database; erasure, email correction, expiry, or archival therefore revokes access.
- Completing registration is separate from verifying a mailbox. Pre-test, post-test, and evaluation access requires the same participant to have completed registration first.
- Magic-link request and confirmation endpoints are rate limited. Token lifetime, participant-session lifetime, participant-pass lifetime, abandoned-record lifetime, and the bounded number of live links are configurable.
- Submission processing rechecks participant ownership and attempt limits while holding database locks, including when erasure or an administrative change races a submission.

### Browser, transport, and storage

- Dynamic responses are marked private and `no-store`. They also receive anti-framing, MIME-sniffing, referrer, robots, cross-origin, and permissions headers.
- The enforced Content Security Policy uses a fresh script nonce and forbids inline event handlers, frames, objects, and untrusted script execution. HSTS is sent on secure requests.
- Host validation trusts the canonical `APP_URL` host plus only the exact aliases explicitly listed in `APP_TRUSTED_HOSTS`.
- Sessions are encrypted and default to a 30-minute idle lifetime, expiration on browser close, HTTP-only cookies, and `SameSite=Strict`. Production requires HTTPS-only cookies.
- Certificate files use private storage and are served through authorized controllers; Laravel's generic local-storage serving route is disabled.
- Passwords use Laravel's password hasher. TOTP secrets, response values, delivery addresses and payloads, delivery errors, override reasons, certificate recipient names, and revocation reasons use application-layer encryption.
- Participant names, email addresses, and organizations remain queryable fields in the primary database. They depend on database, disk, backup, and access-control protections and on timely erasure.

### Audit and privacy controls

- Security and administrative actions are recorded. IP addresses and user-agent strings are retained as keyed fingerprints rather than raw values, and common personal or secret metadata fields are redacted.
- Audit logs are pruned on a bounded schedule. The default retention is 365 days and should be shortened if the documented operational and legal need permits.
- Participant erasure deletes response answers, access credentials, overrides, certificate files, and participant-linked personal delivery data. It clears identity, scores, response metadata, certificate recipient names, and identifying audit links.
- The erasure task fails closed if a certificate file cannot be deleted: it does not mark the database record erased while the file may remain.

## Data lifecycle

Retention is a governance decision, not merely a configuration value. Set the shortest period that supports the documented purpose and applicable obligations, publish it to participants, and review it periodically.

| Data | Normal lifetime | Erasure result |
| --- | --- | --- |
| Administrator name, email, password hash, MFA material, and status | Until the account lifecycle policy calls for removal | Not handled by participant erasure; deactivate access immediately when no longer needed and remove data under the organization's administrator-record policy |
| Participant name, email, organization, verification state | Webinar end time plus `data_retention_days`; default 7 days | Identity fields and access state are cleared; an anonymous participant tombstone may remain for referential integrity |
| Form submissions, answers, scores, metadata | Same webinar retention period | Answers are deleted; scores and submission metadata are cleared; non-identifying submission structure may remain |
| Abandoned, unverified participant records | Default 24 hours, configurable | Records with no submission or certificate are permanently deleted with their magic-link deliveries |
| Magic-link tokens | Until used or expired; default link lifetime 15 minutes | Used and expired rows are deleted by the hourly access-pruning task |
| Certificate PDF and recipient details | Same participant retention period | PDF is deleted; participant link, recipient name, file path, and revocation reason are cleared |
| Public certificate verification record | Retained after personal-data erasure | Verification identifier, webinar title, issue date, and validity state remain so an issued certificate can still be checked |
| Email delivery address, payload, provider ID, and error detail | Same participant retention period when linked to the participant or certificate | Personal delivery fields are cleared and relationships detached |
| Security audit records | Default 365 days | Participant and override links are severed during erasure; expired audit rows are later deleted |

The application cannot erase copies already delivered to a participant, downloaded CSV files, administrator devices, email-provider records, reverse-proxy or platform logs, analytics tools, support systems, or backups. Configure each processor and storage system with a matching purpose, access policy, and deletion schedule. Do not enable request-body logging or session, authorization, magic-link, or credential logging.

When a participant is deleted manually, the same privacy-erasure service removes linked personal data and then removes the participant tombstone. Deleting an entire webinar also removes its certificates and makes their public verification records unavailable; the interface requires explicit confirmation.

## Production deployment gate

Keep public traffic disabled, or restricted to the deployment team, until every step succeeds. This hardening release includes data-transforming migrations. Updated application code must not serve traffic against the old schema.

### 1. Contain and replace exposed secrets

- Revoke and rotate any database, transactional-email, object-storage, or other credential that has ever appeared in source control, chat, screenshots, logs, tickets, or an untrusted machine. Removing a credential from `.env` does not revoke it at the provider.
- Remove any legacy `ADMIN_PASSWORD` value. Environment-based administrator password provisioning is intentionally unsupported.
- Store production secrets in the hosting platform's secret manager with access limited to the application and designated operators. Use different credentials for development, staging, and production.
- Do not print secrets during validation or put them in command arguments, build logs, container images, frontend variables, support bundles, or database dumps.

### 2. Preserve the encryption key

For a brand-new empty installation, generate `APP_KEY` once and store a protected recovery copy. For an existing installation, retain the current key exactly.

Never run `php artisan key:generate` against an existing data set as a routine deployment step. Changing `APP_KEY` makes encrypted fields, sessions, two-factor secrets, form tokens, verification codes, and queued encrypted payloads unreadable. A legitimate rotation requires a tested, application-specific decrypt-and-re-encrypt migration, a verified backup, a maintenance window, rollback criteria, and protected retention of the old key for the lifetime of older backups.

### 3. Configure the production runtime

At minimum:

- Set `APP_ENV=production`, `APP_DEBUG=false`, a canonical HTTPS `APP_URL`, and only required exact host aliases in `APP_TRUSTED_HOSTS`.
- Terminate TLS with a valid certificate, redirect HTTP to HTTPS at the edge, pass the correct scheme only from trusted proxies, and set `SESSION_SECURE_COOKIE=true`.
- Keep `SESSION_ENCRYPT=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=strict`, `SESSION_LIFETIME` at 30 minutes or less, and `SESSION_EXPIRE_ON_CLOSE=true`.
- Keep CSP enforcement, mandatory administrator MFA, disabled remember-me login, and recent-password export confirmation enabled. Keep the per-webinar verification switch on unless the event has an explicitly accepted low-stakes impersonation risk; `security:check` warns when any webinar has it disabled. Report-only CSP is a rollout aid, not the production end state.
- Enable HSTS only after HTTPS works for every intended hostname. Enable `includeSubDomains` or preload only after reviewing all subdomains and the long-lived consequences.
- Expose only the application's `public` directory as the web root. Deny `.env`, source, storage, backup, database, vendor, and VCS files at the web server and hosting layer.

### 4. Configure managed data services

- Use managed PostgreSQL, not SQLite or a substitute engine. Require TLS with server identity verification, restrict ingress, use a least-privilege application account, and keep administrative credentials separate.
- Use encrypted Redis sessions and Redis cache with separate databases or clusters. A single `REDIS_URL` containing a database path overrides per-connection database numbers, so use connection-specific URLs when the provider supplies URLs.
- Use Redis queues; `sync` queues are not a production configuration. Isolate queue data from sessions/cache and use a real transactional-email provider over HTTPS.
- Store certificate files in a private, access-controlled bucket for multi-node deployments. If local storage is unavoidable, use one application node plus encrypted disks and encrypted backups.
- Keep application logs on daily rotation with restricted access. Forward them to a protected central store if required, while preserving their retention and redaction rules.

### 5. Build and verify the release

Build in a clean environment from a reviewed lockfile, run the test suite and dependency audits, and create frontend assets before promotion:

```bash
composer install --prefer-dist --no-interaction
npm ci
composer run test
vendor/bin/pint --test
npm run build
composer audit
npm audit
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Run tests and audits in CI before the final command removes development-only PHP packages from the production artifact. Review all audit findings; a command completing successfully does not mean an accepted risk is still acceptable.

### 6. Back up, then migrate

Take and verify a restorable, encrypted database backup and a consistent certificate-storage backup before applying the hardening migrations. Confirm the backup can be decrypted with the preserved application key. Then run:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan migrate:status
```

Stop on any migration error. Do not bypass or manually mark a migration complete. The migrations encrypt existing bearer identifiers and operational personal data, scrub legacy audit identifiers, add the TOTP replay guard and participant magic-link binding, and invalidate persistent administrator tokens.

### 7. Provision administrators and require MFA

Create each named administrator privately:

```bash
php artisan admin:create
```

Do not pass the password on a command line. Through temporarily restricted ingress, sign in as each administrator, enroll TOTP, save recovery codes in an approved password manager or offline secure location, sign out, and test a fresh password-plus-TOTP sign-in. At least one active administrator must have MFA enrolled before the production preflight can pass.

### 8. Start workers and the scheduler

Run the default and email queue workers under a service manager that restarts failed processes and restarts workers after each release. Their timeouts differ deliberately:

```bash
php artisan queue:work redis --queue=default --tries=3 --timeout=1800
php artisan queue:work redis-emails --queue=emails --tries=3 --timeout=60
php artisan schedule:work
```

Run exactly one scheduler service. The supplied `deploy/systemd` units are a hardened starting point. The application schedules:

| Schedule | Task |
| --- | --- |
| Hourly | Delete expired or used participant access credentials and abandoned unverified participants |
| Every minute | Record scheduler health and dispatch a heartbeat through each required queue worker |
| Daily at 02:00 | Erase participant data whose webinar retention deadline passed |
| Daily at 02:30 | Prune audit records beyond the configured retention period |

Time values follow the application's configured timezone. Monitor both scheduler execution and job failures; merely configuring a cron entry or worker process is not evidence that it remains healthy.

Before the blocking preflight, use the provider to restore the latest backup into a disposable managed PostgreSQL database. Run `deploy/verify-restored-postgres.sh`, save its output in a protected change record, verify the private certificate-file restore separately, and only then record the rehearsal timestamp/reference in production configuration.

### 9. Run the blocking preflight

Run this after configuration, migration, and MFA enrollment and before opening traffic:

```bash
php artisan security:check --production
```

Any failure blocks release. Resolve warnings deliberately: file sessions indicate a single-node deployment, and local certificate storage requires encrypted single-node operations. After a passing check:

```bash
composer run optimize
php artisan queue:restart
```

Smoke-test administrator sign-in and MFA, password confirmation before export, participant email delivery and link consumption, form submission, certificate authorization and public minimal verification, queue processing, and the `/up` health endpoint. Verify response security headers from outside the reverse proxy.

## Routine operations

Run the production preflight on every release and after security-related configuration changes. Preview lifecycle work when changing retention settings:

```bash
php artisan security:check --production
php artisan security:prune-participant-access --dry-run
php artisan privacy:erase-expired-participants --dry-run
php artisan security:prune-audit-logs --dry-run
```

Operational owners should also:

- Review active administrators at least quarterly and immediately deactivate departed or reassigned personnel. Use one account per person and protect hosting, database, email, storage, and observability consoles with separate MFA.
- Inspect failed jobs, scheduler freshness, email delivery failures, authentication and throttling anomalies, certificate deletion errors, disk capacity, backup success, TLS expiry, and dependency advisories.
- Treat CSV exports and certificate downloads as sensitive copies. Restrict export rights, use encrypted managed devices, avoid personal email or consumer file sharing, and delete working copies when their purpose ends.
- Test restoration at a defined interval. A successful backup job is not proof that the data, certificate files, and encryption key can be restored together.
- Re-run privacy erasure immediately after restoring an older backup and before accepting traffic, so data deleted since the backup is not silently reintroduced.
- Apply security updates through a tested release process. Record risk acceptance, owners, deadlines, and compensating controls for deferred findings.

## Backup policy

Back up the database, private certificate objects, and the minimum configuration metadata needed for recovery. Back up `APP_KEY` separately in a restricted secret-recovery system; do not include plaintext secrets in ordinary source or artifact archives.

Backups must be encrypted in transit and at rest, access logged, protected with separate credentials, and retained for a documented period. Prefer immutable or offline copies for ransomware resilience, but remember that immutability delays erasure: restrict access, expire backups on schedule, and document how a restored backup will be brought forward and re-erased before use. Define and test recovery-time and recovery-point objectives.

## Incident response

Maintain named security, privacy, legal, infrastructure, and communications contacts before an incident. For suspected compromise:

1. Preserve relevant evidence and record times, affected systems, and actions. Avoid copying personal data into tickets or chat.
2. Contain access: restrict ingress, deactivate affected administrators, invalidate affected sessions and magic links using an approved backend procedure, and stop suspect workers if needed.
3. Revoke and rotate exposed provider credentials immediately. Do not rotate `APP_KEY` reflexively; first determine whether encrypted data must remain recoverable and use a planned re-encryption procedure.
4. Determine which participants, data categories, records, backups, exports, providers, and time periods were affected. Use protected audit and infrastructure logs and preserve their integrity.
5. Remove the cause, deploy a reviewed fix, apply migrations, rerun tests and `security:check --production`, and restore service in stages with increased monitoring.
6. Work with qualified counsel and the responsible privacy officer to meet applicable notification and regulator deadlines. Document the decision whether or not notification is required.
7. Complete a blameless post-incident review with corrective owners and deadlines, then test the revised controls.

If an email-provider or other API credential is suspected to be exposed, revoke it at that provider even if it has already been removed from the local environment.

## Legal and governance checklist

Before collecting real participant information, the deploying organization must determine and document:

- Who is the data controller or equivalent responsible organization, and which vendors are processors or service providers.
- The purpose and lawful basis for every collected field. The form's consent acknowledgement records acceptance of the displayed notice; it does not by itself establish that consent is the correct or sufficient lawful basis.
- A clear participant privacy notice covering data categories, purposes, recipients, retention, the residual non-identifying certificate verification record, international transfers, rights, and a working privacy contact.
- Procedures to authenticate and fulfill access, correction, deletion, objection, restriction, portability, and consent-withdrawal requests where applicable, including copies held by vendors and administrators.
- Contracts and data-processing terms for hosting, database, email, storage, logging, backup, and support providers; transfer mechanisms and data-residency requirements; and limits on provider reuse.
- Rules for children, education, health, employment, government, accessibility, records retention, and breach notification that may apply to the audience or event.
- Who may export data, issue or revoke certificates, override eligibility, change retention, access backups, and approve exceptions. Review these decisions and audit evidence on a defined schedule.

The software cannot decide these legal and organizational questions. Obtain advice for every jurisdiction and use case in which it will operate; do not advertise the installation as compliant solely because these controls are enabled.
