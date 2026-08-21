# Production Deployment Checklist

Every item below maps to one failure or warning from the app's own preflight:

```bash
php artisan security:check
```

Deploy only when that command reports **0 failures**. Re-run it after each change.

---

## 1. Environment `.env` changes (the 8 preflight failures)

The hardened reference values already live in [`.env.example`](.env.example). Set the following on the **production** environment:

| # | Preflight failure | Fix in production `.env` |
|---|---|---|
| 1 | No bootstrap administrator password is retained | **Delete** the `ADMIN_EMAIL` and `ADMIN_PASSWORD` lines. They are not read by any code (the admin is created interactively — see §2); their mere presence is the risk. |
| 2 | APP_URL uses HTTPS | `APP_URL=https://your-domain.example` |
| 3 | APP_URL uses a deployment hostname | Same line — must not be `localhost`/`127.0.0.1`. Use the real canonical host. |
| 4 | Session cookies are HTTPS-only | `SESSION_SECURE_COOKIE=true` (defaults on once `APP_URL` is `https://`, but set it explicitly). |
| 5 | Production database is not SQLite | `DB_CONNECTION=pgsql` + `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` for your Postgres. |
| 6 | Database transport verifies the server | `DB_SSLMODE=verify-full` and `DB_SSLROOTCERT=/path/to/ca.crt`. |
| 7 | Production sessions use encrypted Redis payloads | `SESSION_DRIVER=redis` (database/file sessions retain raw IP + user-agent columns). |
| 8 | Session storage uses a dedicated connection | `SESSION_CONNECTION=session` + `REDIS_SESSION_DB=2`, so an emergency session flush cannot wipe queue/cache. |

Also confirm these are already correct (they were, on the last check):
`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set, `SECURITY_REQUIRE_ADMIN_TWO_FACTOR=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=strict`, `SECURITY_CSP_REPORT_ONLY=false`, `TRANSACTIONAL_EMAIL_PROVIDER=brevo` + a real `BREVO_API_KEY`.

### The two warnings (not blockers, but decide before scaling)
- **File/DB sessions are single-node** — resolved by items 7–8 (Redis).
- **Local certificate storage** — if you stay on `CERTIFICATE_DISK=local`, use encrypted disks + encrypted backups and keep **one** app node. To scale horizontally, move certificates to S3/R2 (the `AWS_*` block in `.env.example`).

---

## 2. First administrator (after §1, once `.env` is set)

```bash
php artisan admin:create --email=you@your-domain.example --name="Your Name"
```

It **prompts** for the password (never on the command line / shell history), then the admin must enrol TOTP two-factor on first sign-in before any data is reachable.

---

## 3. Build & cache (production)

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Run the queue worker (transactional email + certificate batches depend on it):

```bash
php artisan queue:work --queue=emails,default --sleep=1 --tries=3
```

---

## 4. Behind a TLS-terminating proxy / load balancer?

Only if applicable — never use `*`:

```
SECURITY_BEHIND_PROXY=true
APP_TRUSTED_PROXIES=10.0.0.4,10.0.0.5   # exact proxy IPs/CIDRs
APP_TRUSTED_HOSTS=                        # extra exact host aliases, if any
```

---

## 5. Final gate

```bash
php artisan security:check   # must print 0 failures
php artisan test             # must be all green (currently 125 passed)
```

Only deploy when both pass.
