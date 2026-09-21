# Authoritative Production Deployment Runbook

This is the **only end-to-end production deployment path** for the Webinar Platform. It supersedes abbreviated deployment sequences elsewhere in this repository. [`deploy/README.md`](deploy/README.md) is only an index of repository templates and verification helpers.

This runbook is operational guidance, not authorization to change infrastructure during repository preparation. In particular, a local SQLite result never substitutes for the separate disposable PostgreSQL migration proof.

**Current release gate: PENDING DEPLOYMENT-ENVIRONMENT VALIDATION.** The disposable PostgreSQL migration proof remains pending because this local machine has no PostgreSQL server or container runtime. Complete that proof in the isolated deployment environment before any production migration.

## Intended architecture and release layout

```text
Internet -> Cloudflare -> EC2 Apache -> PHP 8.3-FPM -> Laravel -> PostgreSQL
                                                             -> Redis
```

Systemd manages the default certificate worker, transactional-email worker instances, and Laravel scheduler.

```text
/var/www/webinar-platform/
  releases/<release-id>/
  current -> releases/<release-id>
  shared/
    storage/

/etc/webinar-platform/runtime.env
```

Private storage is shared across releases; generated Laravel caches are release-specific. Every administrator is a full-system administrator, so account creation is a trust decision.

## PHASE 1 — RELEASE PREPARATION

1. Select one exact Git commit; record its full SHA, branch/ref, release ID, operator, and validation evidence.
2. Review `git status --short`, staged and unstaged diffs. Explicitly decide which dirty files belong in the release; exclude local logs, SQLite files, preview/test output, caches, and credentials.
3. Create a clean release candidate from that exact commit (clean checkout, archive, or CI artifact), never an accidental mixed working tree.
4. In that candidate, retain successful output from:

   ```bash
   composer validate --no-check-publish
   php artisan test
   vendor/bin/pint --test
   npm ci
   npm run build
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   git diff --check
   ```

5. Verify the Vite manifest maps every Blade `@vite` entry and ship the built `public/build` asset artifact. A Vite development server, PM2, and a permanent Node process are not production requirements.

## PHASE 2 — EC2 PREPARATION

Before making any change, verify the Ubuntu version, available disk/memory, existing applications, and existing Apache VirtualHosts. Back up Apache configuration.

Install or verify only the application prerequisites: Apache modules required by the supplied VirtualHost and PHP-FPM proxying; PHP 8.3-FPM with `pdo_pgsql`, `gd`, `mbstring`, `openssl`, `fileinfo`, `intl`, `bcmath`, and `zip`; the selected Redis client (`phpredis` or bundled `predis`); and Composer 2. Install Node **only** if assets will be compiled on the server; the preferred path builds in CI/the release-preparation host and ships `public/build`.

Set PHP-FPM `upload_max_filesize` to at least `8M` and `post_max_size` to at least `9M`. Do not modify unrelated sites or use broad permissions.

## PHASE 3 — POSTGRESQL (DOCUMENT ONLY; DO NOT EXECUTE DURING REPOSITORY VALIDATION)

Provision production PostgreSQL only through the approved infrastructure path:

1. Create the production database and restricted application user with restricted ingress.
2. Obtain the provider CA certificate; configure `DB_SSLMODE=verify-full` and `DB_SSLROOTCERT`.
3. Test authenticated TLS-verified connectivity without putting credentials in shell history or source control.
4. Enable documented encrypted backups and a restore policy.
5. Restore a recent backup to a disposable target, retain proof, and use `deploy/verify-restored-postgres.sh` only from a protected operations host.

**Required gate:** first pass a separate disposable PostgreSQL migration proof against the intended PostgreSQL version, including `php artisan migrate --force`, `php artisan migrate:status`, application connectivity, and retained output. Do not migrate production until this proof is complete.

## PHASE 4 — REDIS

Provide protected, authenticated Redis access limited to the application/worker network. Configure four isolated logical stores (separate databases or clusters):

| Purpose | Connection | Default database |
| --- | --- | --- |
| Certificate/default queue | `default` | `0` |
| Cache and cache locks | `cache` | `1` |
| Encrypted sessions | `session` | `2` |
| Transactional email queue | `queue` | `3` |

Use a connection-specific URL only when it includes its own database path. Otherwise configure individual host/port/database values in the external runtime environment. Test connectivity, session/cache/queue isolation, and distinct `REDIS_DEFAULT_QUEUE_CONNECTION` and `REDIS_EMAIL_QUEUE_CONNECTION` values.

## PHASE 5 — RELEASE INSTALLATION

Create a new immutable `releases/<release-id>/` directory; never edit the old `current` release in place. Populate approved source and pre-built assets, then run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Either ship the Phase 1 frontend artifact or, only when Node was deliberately installed for server-side compilation, run `npm ci` and `npm run build` in the new release before it becomes `current`.

Create `/var/www/webinar-platform/shared/storage` and link each release's `storage` to it. Link the ignored release `.env` to `/etc/webinar-platform/runtime.env`; never copy secrets into a release. Do **not** run `php artisan storage:link`: certificate PDFs and backgrounds remain private and use authorized routes.

Source/public assets must be readable but not writable by the web account. `shared/storage` and that release's `bootstrap/cache` must be writable by `www-data`; `/etc/webinar-platform/runtime.env` is `root:www-data`, mode `0640`. Do not share `bootstrap/cache` and never use `chmod -R 777`.

As `www-data`, build caches in the new release:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Confirm cached configuration before switching `current`.

## PHASE 6 — DATABASE MIGRATION

Before `php artisan migrate --force`, require all of the following:

- the exact release and commit are selected;
- the correct production `APP_KEY` is verified out of band;
- the target is confirmed as the correct production PostgreSQL database;
- a current backup completed;
- `php artisan migrate:status` was inspected;
- the Phase 3 disposable PostgreSQL migration proof passed and was recorded.

Only then run:

```bash
php artisan migrate --force
```

Inspect and retain migration status afterwards. Never use `migrate:fresh`, destructive rollback, or ad-hoc schema deletion as normal deployment/recovery procedure.

## PHASE 7 — SYSTEMD

Install only the reviewed units from `deploy/systemd/`:

- `webinar-platform-worker-default.service` — certificate/default queue;
- `webinar-platform-worker-emails@.service` — transactional email workers;
- `webinar-platform-scheduler.service` — Laravel scheduler;
- `webinar-platform-services.target` — reviewed group target.

On the server, after copying those intended units:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now webinar-platform-services.target
sudo systemctl status webinar-platform-worker-default.service
sudo systemctl status 'webinar-platform-worker-emails@*.service'
sudo systemctl status webinar-platform-scheduler.service
sudo journalctl -u webinar-platform-worker-default.service -u webinar-platform-scheduler.service --since '10 minutes ago'
```

After a later release switch, run `php artisan queue:restart` from the new `current` release so workers finish their current jobs and systemd restarts them against the new code. Restart the scheduler service because `schedule:work` is long-running. Inspect journals and wait for fresh scheduler/default/email heartbeats. Never run systemd commands during repository preparation.

## PHASE 8 — APACHE

Install only the new dedicated VirtualHost from `deploy/apache/webinar-platform.conf.example`; replace its hostname placeholder, leave unrelated sites untouched, and confirm its document root is `/var/www/webinar-platform/current/public` with PHP 8.3-FPM.

Before enabling/reloading anything:

```bash
sudo apache2ctl configtest
```

Enable the new site and perform a graceful Apache reload **only** when that configuration test passes. Do not change global ports, other VirtualHosts, or unrelated Apache configuration.

## PHASE 9 — DNS / TLS / CLOUDFLARE

Use this order:

1. Create a DNS-only subdomain pointing to the EC2 Elastic IP.
2. Confirm HTTP reaches only the new VirtualHost.
3. Obtain the Let's Encrypt certificate.
4. Verify origin HTTPS, hostname, and chain directly.
5. Configure exact trusted-proxy CIDRs in `APP_TRUSTED_PROXIES`.
6. Enable Cloudflare proxying.
7. Set SSL/TLS to **Full (strict)**.
8. Verify Laravel HTTPS detection and canonical URL generation.
9. Verify no redirect loop.
10. Verify secure host-only session cookies: `Secure`, `HttpOnly`, `SameSite=Strict`, and no broad `SESSION_DOMAIN`.

Never hard-code Cloudflare ranges as immutable repository values. At deployment, use Cloudflare's authoritative current published IP ranges, choose exact reviewed CIDRs, and retain the selection in the protected change record. Never use `*`, `REMOTE_ADDR`, or `/0`. Direct-TLS deployments leave proxy mode disabled and the allowlist empty.

## PHASE 10 — ADMIN INITIALIZATION

Create the administrator interactively with `php artisan admin:create`. Enter its password only at the prompt; do not place it in an environment file, command line, log, document, or repository. Through restricted access, enroll MFA, verify MFA/recovery-code handling, and verify sensitive-action password confirmation.

## PHASE 11 — PRODUCTION SECURITY GATE

With cached production configuration, migrated PostgreSQL, working Redis, and fresh service heartbeats:

```bash
php artisan security:check --production
```

Normal production use requires **zero failures**. Treat each failure as a gate; never weaken `SecurityCheck.php` or declare infrastructure facts merely to make it pass.

## PHASE 12 — CONTROLLED APPLICATION TESTING

Keep public traffic restricted while observing this sequence:

1. HTTPS, login, MFA, logout, and password confirmation.
2. CSV imports, participant normalization, eligibility, and an audited override.
3. Certificate design using short, long, and accented names.
4. **One** certificate generation and private certificate access.
5. **One** authorized Brevo email; record the provider message ID, then verify resend/retry behavior without duplicate delivery.
6. Application logs, worker journals, scheduler heartbeats, queue health, and backup/restore evidence.
7. A small selected certificate batch, observed to completion.

Only after successful observation may normal production operation begin. Capacity testing is isolated staging work described by `deploy/load/README.md`, never a production load test.

## Runtime variables supplied only by the secret/configuration system

Populate `/etc/webinar-platform/runtime.env` from the reviewed template, never source control. It supplies at minimum `APP_KEY`, database credentials/TLS path, Redis credentials/endpoints, mail sender identity, `BREVO_API_KEY`, optional private-object-storage credentials, exact proxy allowlists, and backup/restore evidence variables. `.env.example` documents variable names and safe defaults; secret values never belong in Git.
