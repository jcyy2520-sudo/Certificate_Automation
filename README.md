# Configurable Webinar, Assessment & Certificate Platform

Laravel 12 foundation for managing multiple webinars or events, dynamic registration and evaluation forms, pre/post assessments, eligibility-based certificate issuance, queued transactional email, audit history, and privacy erasure.

## How access works

The system is private. Signing in is limited to administrator accounts you create, and there is no public browsing of any kind: no event listing, no participant accounts, no directory.

The only thing a person outside the system can open is:

| They hold | They see |
| --- | --- |
| A form's share link | In the default secure mode, an email-verification screen for that one form. A short-lived, one-time link unlocks every form for that webinar through a retention-capped pass. Trusted-mode forms open directly. |
| A certificate's verification code | That certificate's event, issue date, and validity. Never a name, email, or score. |
| Anything else | A sign-in page or a 404. |

`robots.txt` disallows every path, and participant-facing pages carry `noindex, nofollow, noarchive`.

## What the platform does

### Administrators
- Password sign-in with mandatory TOTP two-factor authentication, encrypted secrets, and single-use hashed recovery codes
- Create a webinar and get its four forms, a default eligibility rule, and a certificate template provisioned automatically
- Build fields and assessment questions (multiple choice, true/false, written) with points and correct-answer marking
- Copy each form's share link, see whether it is live or closed, and rotate it to retire the old URL instantly
- Configure which requirements gate a certificate, with optional minimum scores per assessment
- Design the certificate wording, accent colour, and signatory, and preview the PDF before issuing
- See who has completed which forms, filter to those who met every requirement, and export the list as CSV
- Record audited eligibility overrides, issue certificates individually or in bulk, and revoke with a reason

### Participants
- Open a share link, verify control of an email address once per webinar, fill the forms, submit, done — no account, no password, no portal
- Responses from the same email address are linked to one participant record across an event's forms
- Scores are shown on the thank-you page only when that form is set to reveal them

## Platform foundation

- Laravel 12, PHP 8.3+, Livewire 4, Blade, Tailwind CSS 4
- PostgreSQL production configuration (SQLite remains supported for local tests)
- Standalone forms addressed by an encrypted, rotatable bearer token, with rate-limited email ownership verification before submission
- Dynamic form fields plus assessment questions and answer choices
- Submissions, scores, evaluation responses, eligibility rules, and audited manual overrides
- Certificate templates, batches, verification codes, generated-file metadata, and QR-coded PDF output
- Database-backed queue records and tracked email deliveries
- Provider-neutral transactional mail contract with Brevo HTTP API and local log implementations
- S3-compatible storage configuration suitable for Cloudflare R2
- Daily scheduled personal-data erasure while retaining non-personal certificate verification records
- Enforced browser security headers, rate-limited authentication, and a bounded, pseudonymized audit log

## Local setup

Requirements: PHP 8.3+, Composer, Node.js 20+, and PostgreSQL 15+.

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Set the PostgreSQL values in `.env`, then run:

```bash
php artisan migrate
npm install
npm run build
composer run dev
```

For a quick SQLite evaluation, change `DB_CONNECTION=sqlite`, remove the other `DB_*` values, create `database/database.sqlite`, and migrate.

## Your administrator account

There is no sign-up and no landing page. The root URL goes straight to a sign-in form with no navigation, no marketing, and no links.

Create your account from the command line:

```bash
php artisan admin:create
```

It prompts for an email, display name, and a passphrase of at least 15 characters. The password is typed at a hidden prompt, so it never lands in shell history or `.env`. Run it again with the same email to reset that account's password. Environment-based password seeding is deliberately unsupported because a forgotten bootstrap value could later reset a hardened account.

## Email

Development defaults to `TRANSACTIONAL_EMAIL_PROVIDER=log`, so no message leaves the application. For Brevo's transactional HTTP API:

```dotenv
TRANSACTIONAL_EMAIL_PROVIDER=brevo
BREVO_API_KEY=your-key
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Webinar Platform"
```

Emails are queued on the `emails` queue; bulk certificate issuance uses the default queue. One worker can serve both, highest priority first:

```bash
php artisan queue:work --queue=emails,default --tries=3
```

Credentials are never included in source control. Keep `.env` out of version control and rotate any key that has been shared.

## Storage

Local development uses `CERTIFICATE_DISK=local`. For S3 or Cloudflare R2, set `FILESYSTEM_DISK=s3`, `CERTIFICATE_DISK=s3`, and the `AWS_*` variables documented in `.env.example`.

## Security operations

Do not accept real participant information until the production gate in [SECURITY.md](SECURITY.md) is complete. It documents the enforced controls, data lifecycle, secret and encryption-key handling, migrations, administrator MFA, workers, scheduler, backups, incident response, and legal responsibilities.

The production preflight returns a failure status when a required runtime control is unsafe. Lifecycle commands support a non-mutating preview:

```bash
php artisan security:check --production
php artisan security:prune-participant-access --dry-run
php artisan privacy:erase-expired-participants --dry-run
php artisan security:prune-audit-logs --dry-run
```

## Privacy scheduler

After a webinar's end time plus its `data_retention_days` (seven by default), the scheduler removes participant names, email addresses, organizations, response data, scores, access tokens, delivery payloads, and stored certificate recipient names. Certificate identifiers, webinar title, issue date, and validity state remain.

Preview or run erasure manually:

```bash
php artisan privacy:erase-expired-participants --dry-run
php artisan privacy:erase-expired-participants
```

Production must run Laravel's scheduler every minute and keep a queue worker alive.

## Verification

```bash
composer run test
vendor/bin/pint --test
npm run build
```

The suite runs against an in-memory SQLite database configured in `phpunit.xml`, so it never touches a development or production database, and it forces the `log` mail provider so no message can leave the machine during a test run.

Public certificate checks use `/certificates/verify/{verification-code}` and deliberately exclude participant contact information and test results.

## Managing content

Every record an administrator works with can be created, read, updated, and deleted:

| | Create | Read | Update | Delete |
| --- | --- | --- | --- | --- |
| Webinar | ✓ | ✓ | ✓ | ✓ (type-to-confirm) or archive |
| Form | provisioned with the webinar | ✓ | ✓ | — the four stages are fixed |
| Field | ✓ | ✓ | ✓ | ✓ |
| Question | ✓ | ✓ | ✓ | ✓ |
| Participant | via the public form | ✓ | override / erase | ✓ |
| Certificate | ✓ single or bulk | ✓ | — | revoke, which keeps the audit trail |

Two deliberate restrictions:

- **A question that has been answered keeps its choices and type.** Recorded answers store the chosen choice id, so swapping the choices afterwards would silently reinterpret people's responses. Wording, points, and the required flag stay editable; to change the choices, delete the question and add a replacement.
- **Deleting a webinar asks you to type its title**, and warns how many issued certificates will stop verifying. Archiving is the non-destructive option and closes every share link.

Deleting a participant removes their responses but leaves any issued certificate verifiable, with the recipient name cleared.

## Performance

The admin pages are measured, not assumed. With 600 participants and 1,150 submissions on one webinar:

| Page | Queries | Time |
| --- | --- | --- |
| Dashboard | 10 | ~29ms |
| Webinars | 6 | ~27ms |
| Webinar | 9 | ~32ms |
| Participants | 16 | ~109ms |
| Certification | 11 | ~92ms |
| Form editor | 8 | ~31ms |
| Public form | 12 | ~25ms |

Three things keep it there:

- `EligibilityService::eligibleParticipantsQuery()` keeps completion counts and filters inside the database instead of materializing an ever-growing participant ID list in PHP. `evaluate()` handles the single-participant case and runs without queries when the relations are already loaded; `attachTo()` prepares one page in a fixed number of queries. `EligibilityConsistencyTest` pins the database query and per-participant evaluation to the same answer.
- Foreign keys that screens filter on carry explicit indexes. PostgreSQL does not index a foreign key column automatically.
- The empty JavaScript entry is not requested by any page. The interface is server-rendered Blade, and its small client behaviours stay inline on the pages that use them.

In production set `APP_ENV=production`, `APP_DEBUG=false`, and
`LOG_LEVEL=warning`, then run `composer run optimize` after each deploy. For a fast local
preview of the built application, `composer run serve:optimized` builds those
caches and starts Laravel without the development environment watcher. Use
`composer run dev` while changing code; it clears stale caches first and keeps
the queue worker and Vite running without the extra log-tail process. None of
the figures above assume caches are enabled.

## Interface

The administrator side runs on a dark navigation rail that expands and collapses. The toggle sits at the bottom of the nav (`Collapse`), and the choice is stored in `localStorage` and applied before first paint, so it never flashes at the wrong width. Collapsed it is 76px of icons; expanded it is 248px with labels.

Every page is built from two shared pieces:

- `<x-page-header>` — breadcrumb, page title, optional `meta` and `actions` slots
- `<x-icon name="...">` — the line-icon set in `resources/views/components/icon.blade.php`

The visual language is deliberately flat: hairline `slate-200` borders instead of drop shadows, `rounded-xl` surfaces, `rounded-lg` controls, tabular figures for anything numeric, and one accent colour used only for primary actions and counts.

## Theming

The accent colour is defined once, as a token scale in `resources/css/app.css`:

```css
@theme {
    --color-accent-600: #1d4ed8;   /* and 50 through 900 */
}
```

Every button, link, badge, radio, and progress indicator reads from that scale, so changing these values re-themes the whole product. Two places carry the colour outside CSS and should be changed alongside it: the transactional email templates in `resources/views/emails/` (inline styles, required by mail clients) and the certificate template's `accent`, which is per-webinar and editable under **Certification**.

Participant forms follow a survey layout — centred title with the final word highlighted, a progress stepper that fills as questions are answered, numbered questions, and large radio targets.

## Sharing a form

Open a webinar, click a form, and use the **Share link** panel. Set the form's status to `published` so the link accepts responses, then copy the URL and post it wherever you like.

- With **Require email verification (recommended)** enabled in webinar settings, the link opens an email-verification screen and nothing else. A short-lived, one-time link proves inbox control and issues an encrypted webinar pass (24 hours by default, never beyond retention), so the participant does not re-verify for pre-test, post-test, or evaluation. Every pass use is rechecked against current participant and webinar records.
- Later forms require a completed registration for the same webinar in both modes. The Facebook/social post can therefore carry the registration link, with the pre-test, post-test, and evaluation links shared separately when needed.
- Turning verification off is intended only for trusted, low-stakes audiences. Registration submits immediately and later forms accept a matching registered email without sending an ownership link. Anyone who knows another participant's email can impersonate them in this mode; the settings screen and `security:check` warn about this explicitly.
- **Generate a new link** retires the current URL immediately; anyone still holding it gets a 404.
- Set `closes_at`, or move the status off `published`, to stop accepting responses. Visitors then see a closed notice instead of the form.
- Responses are limited by the form's *maximum attempts* setting, counted per email address.

## Tracking completion

**Participants** on a webinar shows a tick per form for every respondent, their scores, and whether they meet every configured requirement. Filter to `Completed all requirements`, or use **Download CSV** for the full list with scores and a `Meets all requirements` column — that is the list to work from when sending certificates by hand.

## Two-factor authentication

### Where to scan the QR code

1. Sign in at `/admin/login`.
2. Click **Security** in the header, or go to `/admin/security/two-factor`. The dashboard also shows a prompt until it is switched on.
3. Scan the QR code with Google Authenticator, 1Password, Aegis, or any TOTP app. If the camera will not read it, type the key printed beside it instead.
4. Enter the six-digit code your app shows and press **Enable**.
5. Save the ten recovery codes on the next screen. They are displayed once and stored hashed.

Each account scans its own code, and the code is regenerated whenever the setup page is opened, so only scan the one currently on your screen.

Production requires every administrator to enroll a second factor before accessing webinar or participant data. After enrollment, the password alone never establishes a session: sign-in diverts to `/admin/two-factor-challenge` until a code or recovery code is accepted.

Disabling it requires the current password. Regenerating recovery codes invalidates the previous set.

## Certificates

Requirements, design, and issuance live together at **Certification** on each webinar. Requirements are drawn from the four journey stages; each assessment requirement can carry a minimum score. Overrides recorded on a participant always take precedence over the automatic checks.

Certificate PDFs are rendered with DomPDF and carry a QR code linking to their public verification page. `Preview` renders the current design with sample data and never persists a record. Bulk issuance queues an `IssueCertificateBatch` job, so a queue worker must be running:

```bash
php artisan queue:work
```

Participants who still miss a requirement are counted as skipped on the batch, not as failures.
