# Free Render deployment

This is a fresh, empty demonstration deployment authorized on 22 October 2026.
It does not clear the production gates in `PRODUCTION_READINESS_REVIEW.md` or
authorize uploading the owner's existing database, participant files or secrets.

## Services and budget

- Render web service: **Free ($0)**, Docker, Singapore. No paid worker, cron,
  persistent disk, custom domain purchase, plan upgrade or payment method.
- Supabase organization/project: **Free ($0)**, Singapore. PostgreSQL and a
  private `webinar-private` bucket use the existing PostgreSQL/S3 application
  adapters. Supabase Auth is not used for application accounts.
- The dedicated Supabase project's Data API and automatic table exposure stay
  disabled. Do not expose Laravel tables through a second public API.
- Free quotas and pausing still apply. Render may sleep after 15 idle minutes;
  the combined web/queue/scheduler container stops then. Supabase may pause an
  inactive Free project. Never automate traffic just to evade these restrictions.
- Supabase currently includes 500 MB database and 1 GB file storage. Render Free
  has 512 MB RAM and 0.1 CPU. This profile uses one on-demand FPM child, one
  certificate worker and one account/certificate-email worker. Measure actual
  capacity; no concurrency, uptime or uninterrupted-delivery claim is made.
- Unfinished import uploads still use temporary local files. Complete preview,
  mapping and confirmation while the container is awake; after a restart the
  pending source may need re-uploading. Committed responses remain in PostgreSQL.
  This is another reason this Free profile is a demonstration, not production.

References: https://render.com/docs/free, https://render.com/docs/compute-plans,
https://supabase.com/pricing, https://supabase.com/docs/guides/storage/s3/authentication.

## Deployment

1. Publish only reviewed source, tests, templates and documentation to
   `jcyy2520-sudo/Certificate_Automation`. Local `.env*`, database/private files,
   generated PDFs, build/test artifacts and editor launch settings stay out.
2. Select the exact release branch/commit in Render. The Git repository root
   itself is the Laravel directory; leave Root Directory blank. Use `Dockerfile`,
   the Free compute plan, `/up`, and manual deploys. `render.free.yaml` records
   the intended service settings without provisioning a Render database.
3. Use the Supabase **session pooler**, port 5432, for persistent PDO sessions.
   Verify the actual hostname certificate with `verify-full` and the packaged
   public Supabase root at `deploy/render/prod-ca-2021.crt`. It was obtained from
   the certificate link in this project's Database Settings and verified as a
   self-signed CA; SHA-256 fingerprint:
   `807025AD50D4ED219D2C9C7D299C004F824EB00CF7F65AFEF607D07B72E6CAFA`.
   Keep the DB URL/password out of Git/logs. Do not downgrade TLS validation.
4. Create a private S3-compatible bucket. Keep S3 credentials on the server;
   generated S3 access keys bypass storage RLS, so never put them in Vite/public
   variables. Check private uploads, downloads and restart persistence.
5. `RENDER_EDGE_TLS=true` is only accepted when Render injects its platform marker
   and HTTPS external URL. `APP_URL` must match that URL. nginx declares HTTPS
   from that deployment boundary, never an untrusted forwarded header. Other
   hosts retain listener TLS behavior. Original client IP forwarding is not
   enabled by this profile; do not add wildcard trusted proxies.
6. `RUN_MIGRATIONS=true` applies forward migrations only to this new empty DB.
   Do not point the profile at an existing dataset. After the first successful
   release, switch it off; enable it again only for a reviewed migration.
7. Import the prepared `.env.render.runtime` through Render's `.env` file picker.
   The operator's `.env.render.deploy` holds provisioning inputs and must not be
   imported wholesale: its owner-level database password stays local. The runtime
   uses the separate `webinar_app` role with no superuser, database/role creation,
   replication or RLS-bypass permissions; it can use/create its own public-schema
   tables. Both local files are ignored by Git and Docker. Store a new
   deployment `APP_KEY` securely and retain it with future database/file backups.
8. For first administrator creation, the optional `ADMIN_BOOTSTRAP_JSON` value
   is JSON with `email`, `name`, and **password_hash**, never a plaintext password.
   Generate that hash with the application's configured hasher/work factor after
   checking the original passphrase has at least 15 characters. The entrypoint
   writes a private temporary file, unsets the payload for child processes, runs
   `admin:bootstrap`, then removes the temporary file. The command creates an
   approved account only when the users table is empty; it never changes an
   existing password, grants extra accounts or reactivates a suspended user.
9. After confirmed creation, remove `ADMIN_BOOTSTRAP_JSON` from Render and the
   local runtime file, set `RUN_MIGRATIONS=false`, and redeploy the same commit.
   Enroll MFA manually at first sign-in; keep authentication/step-up enabled.
10. The initial release uses log mail. Live provider setup and an explicitly
    controlled mailbox test follow separately; no existing Brevo secret is copied
    automatically and no participant mail is authorized by this demonstration.

The SQL cache/session profile intentionally differs from the production Redis
requirements. Keep `security:check --production` unchanged and report its failures
accurately. Free deployment and local tests do not establish production acceptance.

Before real participant use, verify migration/restore, storage ACLs, worker and
scheduler recovery, credentials/MFA, secure cookies/TLS, resource/load behavior,
provider delivery and backup retention under `DEPLOYMENT_CHECKLIST.md`.
