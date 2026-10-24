# Approved accounts and webinar ownership

This is an extension of the existing Blade/controller/service application. It
uses the existing administrator/user roles and an assigned `webinar_manager` role, native Laravel policies and password
brokers, the existing `created_by` foreign key, and the existing transactional
email outbox. Platform accounts and webinar participants remain separate.

## Account access

Public requests collect name and normalized email only. The server assigns the
regular-user role and an unusable random password hash. Duplicate requests have
the same public response and do not replace the original name. A saved request appears immediately in User Management; no preapproval verification email is required. Invitation mail has an account-level cooldown plus email and IP request limits.

New public requests use `pending_approval` → `approved` → activation. Legacy `pending_verification` requests remain reviewable. Rejected, suspended and invitation-revoked accounts remain restricted. `password_setup_at` is separate
from approval. Only approved, active, onboarded accounts can sign in. Ordinary
accounts must also have verified their email. Existing administrators retain
their credentials and MFA; provisioning remains the interactive `admin:create`
command. There is no public administrator provisioning or role editor.

Verification requires an explicit POST, expires after the configured interval,
and consumes a SHA-256 token digest under an account lock. Setup/recovery uses
Laravel's existing password broker, with hashed, single-use, expiring tokens.
The previously removed broker table is restored by the new migration. All
account-link secrets travel in URL fragments and then POST bodies, keeping them
out of ordinary web-server URL logs and Referer headers. JavaScript is required
to copy the fragment into the confirmation form. Do not enable HTTP request-body
logging on these endpoints. Tokens and password fields are excluded from flashed
validation input; outbox content is encrypted and cleared after processing.

The sole administrator reviews saved requests in **User Management**. Admin-created invitations skip approval, accept only regular-user/Webinar Manager roles, and reuse the same expiring password setup link that verifies email on activation. Approval,
rejection, suspension, reactivation and invitation resend are password-gated and
audited. Queue handoff failure leaves the committed invitation recoverable by
the existing relay, with a visible pending-handoff message. Provider failures
leave approval intact. Resend is limited and replaces outstanding broker tokens.
The public forgot-password screen also supports expired setup invitations and
never announces whether an address exists.

Suspension preserves all owned content, rotates credentials/remember tokens,
invalidates password links and cancels pending account mail. Every protected
request reloads account status before Laravel's session-password check. On
reactivation the account receives a new recovery/setup link. Old credentials and
sessions remain revoked. Stable administrator-set locks protect the final active
administrator in both the web and disable-command paths.

Profile editing supports the display name and a current-password-verified password
change (22 October update). Changing a password signs the user out, invalidates
other password-bound sessions/remember tokens and reset links, and preserves MFA.
Email changes need a separately
verified workflow and are deliberately unavailable; contact the operator rather
than updating the database without verifying ownership of the new address.

## Webinar authorization

`WebinarPolicy::manage` checks active approved access plus ownership or platform
administrator authority. Management middleware covers every management route,
including unscoped certificate download/resend/revoke routes, and executes before
webinar password handling. Scoped child binding remains enabled. Cross-owner
requests return 404 without exposing the other webinar or its password screen.
Dashboard queries and lists are scoped before counts/pagination. Platform-wide
mail and user management remain administrator-only; owners use webinar mail logs.

An administrator opens another owner's webinar (or any password-protected
webinar) through an explicit, password-gated **Administrator access** screen.
The session authorization expires after an hour. Its grant and subsequent access
are audited by actor, webinar, operation and time, without participant content.
Administrators never retrieve or need to know a webinar password.

New webinars automatically belong to their creator, provision the existing four
forms/rules/template and default to no management password. The optional password
is hashed and never displayed. Owners can enable, replace or disable it in
Webinar settings. Successful unlocks are session-scoped, webinar-specific and
time-limited; lock-version rotation invalidates previous grants. Incorrect
attempts are rate-limited. Password edits require account reauthentication, and
new passwords are never stored in the sensitive-action replay session. If a
reauthentication interrupts a password edit, return to settings and enter the new
webinar password again after confirming the account password.

Public form publishing, participant verification, submissions, imports,
eligibility, certificate approvals, private files and delivery remain under their
existing lifecycle rules. Public participants need neither a platform account
nor a webinar management password. Account approval never issues certificates.
Certificate issuance and outgoing certificate jobs recheck owner availability.
Suspended owners' queued certificate work cannot continue sending.

## Email operations

Account messages use ACCOUNT_EMAIL_PROVIDER independently of the certificate and general participant transports, and reuse the sender identity, encrypted outbox,
idempotency keys and retry infrastructure as certificate messages. They use the
`account-emails` queue on the **existing short-visibility email connection**.
The default certificate connection and visibility windows are unchanged.

Email workers must now consume both queues in priority order:

```text
php artisan queue:work database-emails --queue=account-emails,emails --tries=3 --timeout=60
```

Use `redis-emails` in the production service. The reviewed systemd template and
Composer dev command include this priority order. If changing queue names in
configuration, change worker arguments accordingly. Do not start workers against
the owner's real database as a source-validation step.

Configuration (all examples are placeholders/budgets, not provider plan claims):

| Variable | Default | Meaning |
|---|---|---|
| `ACCOUNT_VERIFICATION_MINUTES` | 60 | Email-verification expiry |
| `ACCOUNT_RESEND_SECONDS` | 120 | Minimum interval between account emails |
| `ACCOUNT_EMAIL_QUEUE` | account-emails | Priority account queue |
| `WEBINAR_UNLOCK_MINUTES` | 120 | Owner management unlock duration |
| `EMAIL_SENDS_PER_MINUTE` | 30 | Shared attempted-send budget |
| `EMAIL_SENDS_PER_DAY` | 300 | Shared UTC calendar-day budget; the Free safety ceiling is 300, including when zero is configured |

Set budgets below the actual provider/account allowance with operational
headroom. Production needs the existing shared Redis/cache-lock requirements;
an in-process array cache cannot coordinate multiple workers.

Minute limits release unattempted work for later execution. Actual transport
attempts have a separate bounded count. HTTP 429 uses a shared cooldown and queue
release rather than sleeping past the job timeout. Permanent provider errors fail
without transport retry. Exhausted credits pause new sends for an hour and leave
failed deliveries visible for explicit retry after credits are restored. A local
daily-budget failure similarly requires retry after the UTC calendar-day budget resets.
Certificate resend remains explicitly confirmed; account invitation resend
generates a new token rather than reusing an expired setup email.

Transport retries preserve the original idempotency key. Attempted work cannot
automatically retry beyond a conservative 25-minute window, below Brevo's
documented 30-minute idempotency TTL. Unknown outcomes require provider-log
reconciliation before a certificate resend. New fields have defaults compatible
with previously serialized email jobs.

Status distinguishes queued/retry pending, sending attempts, failed, cancelled,
provider accepted and local capture. `sent` remains the legacy database value for
provider acceptance. **It is not confirmed inbox delivery.** No delivery webhook
was added and no confirmed-delivery claim is made.

## Migration and release boundary

`2026_10_21_000100_add_platform_accounts_and_webinar_protection.php` adds account
lifecycle/password-setup/verification fields, webinar password/version fields,
an ownership/status index, an account link and first-attempt timestamp on the
existing outbox, and the native broker table. It preserves existing owner IDs,
password hashes, MFA, participants, submissions, artwork and certificates.
Existing active administrators become approved/onboarded; inactive ones remain
restricted as suspended. Existing non-administrator records are never promoted.

The migration checks historical owner references **before** schema changes and
stops rather than guessing an owner. `created_by` already has a non-null foreign
key; no owner reassignment is needed for valid historical rows. Rollback removes
new account/lock metadata and outstanding broker tokens, retaining domain data.
Use backed-up release recovery, not an ad-hoc production rollback.

The owner's real database has **not** been migrated or used for tests. Apply the
new migration only after reviewing the current backup and historical ownership
preflight under the owner's data-change approval. Deploy source and schema
together in a maintenance/release window: old unmigrated installations do not
yet have the columns required by this source.

Follow `DEPLOYMENT_CHECKLIST.md`, including disposable exact-version PostgreSQL
upgrade proof, backup/restore, canonical HTTPS, secure cookies, exact trusted
proxies, shared Redis, private storage, MFA, provider identity/credits, workers,
scheduler and zero-failure cached production security checks. Local SQLite and
synthetic Chromium verification do not certify any of those production gates.
No live email, real participant operation, commit, push or deployment is part of
this implementation verification.

## Verification recorded on 2026-10-21 (Asia/Manila)

- `composer run test`: **408 tests / 4,669 assertions passed**. This includes
  account lifecycle/security, a management-route isolation matrix, migration
  upgrade/rollback preservation and the established forms/certificate suites
  repeated with regular-owner authorization.
- `php vendor/bin/pint --test`: passed.
- `npm run build`: passed; the existing PDF.js chunk-size advisory remains.
- `npm run test:js`: **8 passed**.
- `npm run test:e2e`: **27 Chromium snapshot tests passed**.
- `npm run test:workflow`: **3 real-HTTP Chromium workflows passed**: account
  approval/setup/ownership/lock/suspension; Certificate Setup preview/save/sample
  mail; native and imported responses through eligibility/approval/Studio.
- The account browser workflow was rerun after the final account redirect change
  and passed. The final account-aware sample-email copy was checked by the
  Certificate Setup suite: **14 tests / 100 assertions passed**.
- Premium static UI audit: **0 findings**. User Management screenshots inspected
  at 1280px light and 375px dark; no page overflow or browser script errors.
- `git diff --check`: passed after documentation whitespace cleanup.

All data-bearing verification used SQLite `:memory:` or the guarded dedicated
`workflow-browser.sqlite` fixture with log/fake mail. No PostgreSQL executable or
container runtime was available for the required production-version rehearsal.
No live provider/inbox, production scheduler/worker, physical-device or assistive
technology acceptance is implied by these results.


## Assigned access, security and audit update (22 October)

The owner remains the only system Administrator; no account-management endpoint promotes another user. Create Account and Edit Access require the current administrator password and enrolled authenticator/recovery factor. Invitations are pending activation, expiring, single use, resendable and revocable. Existing identities, passwords and ownership are not backfilled. Managers and invited viewers see only assigned webinars. Explicit participant/certificate/email flags are checked at routes and again before queued issuance or delivery; issuance always requires send permission because it queues email. Existing regular-user ownership remains compatible.

The Audit Trail is administrator-only, paginated and filterable. Permanent actor IDs have no cascading foreign key; encrypted historical name/email snapshots survive rename/disable/removal. Anonymous failed logins are not attributed to a claimed identity. Ordinary model mutation/deletion is blocked; privileged retention/privacy operations remain narrow.

Administrator trusted devices are random hashed credentials in encrypted Secure HttpOnly host-only cookies, bound to account credentials, browser/OS and a validated public network prefix when available. Password is always required. Unrecognized context requires password plus the existing factor; QR enrollment is separate and per-account. Password/MFA/access changes and suspicious failures revoke trust. Render currently exposes private proxy addresses, so a public network-change signal is unavailable until a safe trusted-proxy boundary is verified; raw forwarding headers are never trusted.

New additive migrations 000200/000300 and eligibility 000400 require the backed-up release procedure. Schema checks, browser tests and synthetic transport tests are separate from hosted/inbox verification. See ELIGIBILITY_FEEDBACK.md for the participant workflow.
