# Production operations

These files implement the application-side portion of the production gate. Provider-side provisioning still has to be completed and evidenced by the infrastructure owner.

## Server prerequisites

PHP 8.3 with the extensions the runtime needs: `pdo_pgsql`, `gd` (certificate QR codes), `mbstring`, `openssl`, `fileinfo`, `intl`, `bcmath`, and `zip`.

Redis needs a client library as well. `REDIS_CLIENT=phpredis` requires the `redis` PHP extension, which is **not** installed on most base images and is **not** a Composer dependency, so `composer install` cannot catch its absence:

```bash
sudo pecl install redis && echo "extension=redis.so" | sudo tee /etc/php/8.3/mods-available/redis.ini
```

If installing the extension is not an option, set `REDIS_CLIENT=predis` instead — `predis/predis` ships with the application and needs no extension. `php artisan security:check --production` fails when the selected client is not loadable, so this is caught at the gate rather than on the first request.

## Always-on services

The `systemd` units assume the release is at `/var/www/webinar-platform/current`, PHP is `/usr/bin/php`, the service account is `www-data`, and production secrets are in `/etc/webinar-platform/runtime.env` with restrictive permissions. Adjust those four deployment-specific values before installation.

Install the units in `/etc/systemd/system`, then run:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now webinar-platform-services.target
sudo systemctl status webinar-platform-worker-default.service
sudo systemctl status 'webinar-platform-worker-emails@*.service'
sudo systemctl status webinar-platform-scheduler.service
```

The target starts four email-worker instances so confirmation messages for different participants can be delivered concurrently. Increase or decrease the numbered instances only after measuring queue delay, database connections, memory, and provider responses. The scheduler dispatches one probe to each queue every minute. `php artisan security:check --production` fails if the scheduler, default worker, or email worker heartbeat is stale.

## Managed PostgreSQL migration proof

Provision a managed PostgreSQL database and application user, restrict ingress, install the provider CA bundle, and configure `DB_SSLMODE=verify-full` plus `DB_SSLROOTCERT`. With public traffic still disabled, run:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan migrate:status
```

Keep the command output with the release record. `security:check` establishes a real database connection while checking the installed schema, so a pass also proves that the runtime can reach the migrated database.

## HTTPS and trusted proxies

Provision the canonical domain and a valid certificate at the edge, redirect port 80 to HTTPS, and set `APP_URL`, `SESSION_SECURE_COOKIE=true`, and exact `APP_TRUSTED_PROXIES` IPs/CIDRs. Set `SECURITY_BEHIND_PROXY=true` only when a proxy is present. Direct-TLS deployments must leave the proxy list empty. Never use `*`, `REMOTE_ADDR`, or a public catch-all CIDR.

Verify from outside the edge that HTTP redirects to HTTPS, the certificate chain and hostname validate, the session cookie has `Secure`, `HttpOnly`, and `SameSite=Strict`, and forged forwarded headers from an untrusted source do not alter the generated origin or client IP.

## Backup and restore rehearsal

Enable encrypted managed PostgreSQL point-in-time backups and private certificate-storage versioning/backups. Restore the latest database backup into a new disposable managed database named with the `restore_drill_` prefix. Configure two protected libpq service entries—one read-only production connection and one restore connection—without putting credentials in shell arguments.

Run the non-mutating verifier from a protected operations host:

```bash
SOURCE_PGSERVICE=webinar_production_readonly \
RESTORE_PGSERVICE=webinar_restore_drill \
RESTORE_CONFIRM_DATABASE=restore_drill_YYYYMMDD \
RESTORE_REFERENCE=change-or-incident-ticket-id \
PGSSLROOTCERT=/etc/ssl/private/provider-postgres-ca.pem \
bash deploy/verify-restored-postgres.sh
```

The verifier requires verified TLS, prevents accidentally targeting the same database, checks critical table row counts, and compares the full migration ledger and public schema fingerprint. Run it while public traffic is still disabled so the source does not change during comparison. Save its output in the protected change record. Only after the database and certificate-file restore are both verified should the operator set `BACKUPS_ENABLED=true`, `BACKUP_LAST_RESTORE_AT`, and `BACKUP_RESTORE_REFERENCE` in the production secret/config store.

Delete the disposable restored database through the provider console after evidence has been retained. Do not use production credentials for the restore target.

## Final release gate

Create the named administrator using `php artisan admin:create`, enroll and test MFA through restricted ingress, rotate and revoke the old Brevo key in Brevo, inject the replacement through the secret manager, start the services, wait up to two minutes for heartbeats, then run:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan security:check --production
```

Do not open public traffic unless the command reports zero failures.
