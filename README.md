# Configurable Webinar, Assessment & Certificate Platform

Laravel 12 foundation for managing multiple webinars or events, dynamic registration and evaluation forms, pre/post assessments, eligibility-based certificate issuance, queued transactional email, audit history, and privacy erasure.

Approved multi-user accounts, owner-isolated webinars, optional management
passwords and administrator oversight are documented in
[`MULTI_USER_PLATFORM.md`](MULTI_USER_PLATFORM.md). Its additive migration must be
reviewed and applied with the source release; it has not been applied to the
owner's real database during implementation. Account email workers now consume
`account-emails,emails` in that priority order.

## Included in this foundation

- Laravel 12, PHP 8.3+, server-rendered Blade, hand-written JavaScript, Tailwind CSS 4
- PostgreSQL production configuration (SQLite remains supported for local tests)
- Administrator accounts with encrypted TOTP and recovery-code columns
- Webinars with independent dates, status, forms, retention policy, and settings
- Dynamic form fields plus assessment questions and answer choices
- Passwordless participant identity and hashed, expiring access tokens
- Submissions, scores, evaluation responses, eligibility rules, and audited manual overrides
- Certificate templates, batches, verification codes, generated-file metadata, and public verification route
- Database-backed queue records and tracked email deliveries
- Provider-neutral transactional mail contract with Brevo HTTP API and local log implementations
- S3-compatible storage configuration suitable for Cloudflare R2
- Daily scheduled personal-data erasure while retaining non-personal certificate verification records
- Baseline security headers and rate-limited public verification

The application includes the administrator workflow, participant-access flow,
CSV review/import, certificate studio, server-rendered PDF generation, durable
email outbox, privacy lifecycle, and audit history. It is **not** a turnkey
production deployment: use `DEPLOYMENT_CHECKLIST.md` and complete its
PostgreSQL, Redis, worker, TLS, backup/restore, and controlled-live-send gates
before accepting real participant data.

## Local setup

Requirements: PHP 8.3+, Composer, Node.js 20+, and PostgreSQL 15+.

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Set the PostgreSQL values in `.env`, then run:

```bash
php artisan migrate --seed
npm install
npm run build
composer run dev
```

For a quick SQLite evaluation, change `DB_CONNECTION=sqlite`, remove the other `DB_*` values, create `database/database.sqlite`, and migrate.

## Administrator setup

Create the first administrator interactively; do not place an administrator
password in `.env`, a command line, source control, or a deployment log:

```bash
php artisan admin:create
```

## Email

Development defaults to `TRANSACTIONAL_EMAIL_PROVIDER=log`, so no message leaves the application. For Brevo's transactional HTTP API:

```dotenv
TRANSACTIONAL_EMAIL_PROVIDER=brevo
BREVO_API_KEY=your-key
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Webinar Platform"
```

Run separate workers for certificate work and transactional email. Their
visibility windows are intentionally different and must stay different:

```bash
php artisan queue:work redis --queue=default --tries=3 --timeout=1800
php artisan queue:work redis-emails --queue=emails --tries=3 --timeout=60
```

For local database-queue development, `composer run dev` starts equivalent
`database` and `database-emails` listeners. Credentials are never included in
source control. A successful provider API response means **accepted by Brevo**,
not proof that the recipient's inbox received or opened the message.

## Storage

Local development uses `CERTIFICATE_DISK=local`. For S3 or Cloudflare R2, set `FILESYSTEM_DISK=s3`, `CERTIFICATE_DISK=s3`, and the `AWS_*` variables documented in `.env.example`.

## Privacy scheduler

After a webinar's end time plus its `data_retention_days` (30 by default), the scheduler removes participant names, email addresses, organizations, response data, scores, access tokens, delivery payloads, pending CSV source files, and stored certificate recipient names. Certificate identifiers, webinar title, issue date, and validity state remain.

Preview or run erasure manually:

```bash
php artisan privacy:erase-expired-participants --dry-run
php artisan privacy:erase-expired-participants
```

Production must run Laravel's scheduler every minute and keep a queue worker alive.

## Verification

```bash
php artisan test
vendor/bin/pint --test
npm run build
```

Do not use `migrate:fresh` against a database containing participant data.

### Browser and JavaScript tests

Install the locked frontend dependencies, then run the standalone JavaScript
unit tests:

```bash
npm ci
npm run test:js
```

The Playwright suite drives Chromium against Laravel-generated preview
snapshots. On a new machine, install only that browser once for the locked
Playwright version, then run the suite (which regenerates the snapshots):

```bash
npx playwright install chromium
npm run test:e2e
```

Snapshot checks use a dedicated static server on port 8012, synthetic artwork
and generated HTML; PHP and application routes are refused. They do not reuse
the development server. Authenticated HTTP workflows use port 8011 and the
guarded `workflow-browser.sqlite` database with the log email provider.

Public certificate checks use `/certificates/verify/{verification-code}` and deliberately exclude participant contact information and test results.
