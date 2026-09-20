# Private staging deployment

This is a test deployment only. It is suitable for anonymized or disposable
test data, never for real participant data. Keep it as a separately named
service, database, storage bucket, and secret set.

## What is ready in the repository

- `render.staging.yaml` creates an isolated Docker web service and PostgreSQL
  database on Render.
- The container starts the web process, certificate worker, mail worker, and
  scheduler together for one-instance staging.
- Staging mail is fixed to the application's `log` provider. No Brevo key is
  present or required.
- Certificate storage is explicitly private S3-compatible storage because a
  Render filesystem cannot retain a PDF after restart or deploy.

## One-time dashboard inputs

Import `render.staging.yaml` as a **new** Blueprint in the Render workspace.
Enter only these prompted secret values:

1. `APP_KEY`: generate once with `php artisan key:generate --show`; store it in
   the provider secret field and retain an offline recovery copy. Never rotate
   this staging key while its encrypted test data still matters.
2. `APP_URL`: the assigned HTTPS staging origin.
3. `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`,
   `AWS_DEFAULT_REGION`, and `AWS_ENDPOINT`: credentials and endpoint for a
   private bucket used only by staging.
4. `SECURITY_BEHIND_PROXY` and `APP_TRUSTED_PROXIES`: only after checking
   Render's current proxy guidance. Use exact documented CIDRs; never `*`.

Do not add `ADMIN_PASSWORD`, a live `BREVO_API_KEY`, production database URLs,
production Redis URLs, or production bucket credentials.

## First deploy verification

After Render marks the service healthy:

1. Confirm the service log shows migration, cache build, and all three worker
   processes starting.
2. Create the only initial administrator from a Render shell:

   ```sh
   php artisan admin:create --email=your-approved-test-email@example.com --name="Your Name"
   ```

   Enter the password only at the interactive prompt, then complete MFA.
3. Visit `/up`, sign in, create a disposable webinar, use public form links,
   and check the in-app Email Logs rather than an external inbox.
4. Generate a certificate, restart the service once, and confirm its private
   PDF is still downloadable through the authenticated route.
5. Confirm the default queue, email queue, and scheduler heartbeats are fresh
   in the application logs.

## Promotion boundary

This blueprint intentionally does **not** satisfy the production preflight:
it has a log mail provider and database-backed sessions/cache/queues. Before
accepting real data, deploy from `render.yaml` only after replacing these with
managed Redis, verified PostgreSQL TLS, a private restored-storage drill, a
fresh Brevo key, and a zero-failure result from:

```sh
php artisan security:check --production
```

The current Render Blueprint schema supports Docker services, `envVars`,
`fromDatabase.connectionString`, and `sync: false` secret prompts; validate the
file with Render before creating the service. See the official
[Blueprint reference](https://render.com/docs/blueprint-spec).
