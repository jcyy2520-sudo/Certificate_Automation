# Configurable Webinar, Assessment & Certificate Platform

Laravel 12 foundation for managing multiple webinars or events, dynamic registration and evaluation forms, pre/post assessments, eligibility-based certificate issuance, queued transactional email, audit history, and privacy erasure.

## Included in this foundation

- Laravel 12, PHP 8.3+, Livewire 4, Blade, Tailwind CSS 4
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

This milestone intentionally provides the tested application/domain foundation. Admin screens, participant flows, certificate rendering UI, and production authentication screens are the next implementation modules.

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

## Administrator seed

The seeder creates no known default password. Set these before `php artisan db:seed`:

```dotenv
ADMIN_NAME="Platform Administrator"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD="replace-with-a-long-random-password"
```

## Email

Development defaults to `TRANSACTIONAL_EMAIL_PROVIDER=log`, so no message leaves the application. For Brevo's transactional HTTP API:

```dotenv
TRANSACTIONAL_EMAIL_PROVIDER=brevo
BREVO_API_KEY=your-key
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Webinar Platform"
```

Run the dedicated queue:

```bash
php artisan queue:work --queue=emails --tries=3
```

Credentials are never included in source control.

## Storage

Local development uses `CERTIFICATE_DISK=local`. For S3 or Cloudflare R2, set `FILESYSTEM_DISK=s3`, `CERTIFICATE_DISK=s3`, and the `AWS_*` variables documented in `.env.example`.

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
php artisan migrate:fresh --env=testing
php artisan test
vendor/bin/pint --test
npm run build
```

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

Public certificate checks use `/certificates/verify/{verification-code}` and deliberately exclude participant contact information and test results.
