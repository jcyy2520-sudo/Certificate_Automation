# EC2 preparation for 22 October 2026

This is the selected local preparation profile. It supplements the numbered
phases of [the authoritative runbook](../../DEPLOYMENT_CHECKLIST.md); it does not
authorize AWS provisioning, live migration, real-data upload, workers or mail.
The [readiness review](../../PRODUCTION_READINESS_REVIEW.md) remains NO-GO until
the environment and restore gates pass.

## Budget and account gate — before Phase 2

- Out-of-pocket budget is **USD 0**. Use only an eligible account's existing
  verified credit. The owner reported Free plan, but the planned account does
  not exist yet; verify its actual plan, balance, expiry and service eligibility.
- AWS limits Free Tier credits to eligible new customers. Creating replacement
  accounts does not establish eligibility for another USD 100. Stop if the new
  account is ineligible; do not work around the eligibility rules.
- Stay on Free plan. Do not upgrade, join Organizations/Control Tower, buy
  Marketplace software/support/domains, or select services requiring Paid plan.
- AWS Budgets alerts can lag and are not an instant spending cap. The Free plan
  is the applicable account protection; credits still pay for eligible usage.
- Price the actual region, eligible EC2 size, AMI, encrypted gp3 disk, public IP,
  snapshots/backups and transfer before launch. Free-tier-eligible does not mean
  unlimited free runtime. No fixed number of months is promised.
- Review at USD 50 and USD 25 remaining. At USD 20 remaining, arrange a
  separately approved export/restore or shutdown plan while access is available.
  Do not wait for zero: Free plan expiry/depletion removes access to resources
  and data. Never use automatic termination as a substitute for protecting data.

Verified sources on 21 October 2026: [AWS plan behavior](https://docs.aws.amazon.com/awsaccountbilling/latest/aboutv2/free-tier-plans.html),
[eligibility terms](https://aws.amazon.com/free/terms/),
[EC2 eligibility](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ec2-free-tier-usage.html),
[budget delays](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-managing-costs.html).
Recheck account-specific eligibility tomorrow before incurring usage.

## Selected topology — Phases 2–5

One Ubuntu 24.04 LTS EC2 node with Apache, PHP 8.3-FPM/CLI, local PostgreSQL,
local authenticated Redis, private persistent storage and systemd. Select a
plain eligible Ubuntu image without Marketplace charges. Do not create RDS,
ElastiCache, NAT gateways, load balancers, extra nodes or paid email services.

Start evaluation with one eligible 2 GiB class instance such as t3.small if the
account/region offers it. This is a staging candidate, not proven production
sizing. The application, DB, Redis, FPM and workers must pass measured RAM/CPU/
disk/queue-drain tests. If eligible sizing cannot meet the workload within the
credit-only constraint, stop and revise the plan rather than weakening gates.

This intentionally has one failure domain. Off-node recoverable backup and a
restore drill remain required. Snapshot creation consumes usage/credits; price
it and preserve the matching database, private files and encryption keys.

Inbound security group: SSH only from the operator's current public IP; HTTP
and HTTPS for the dedicated host. No public PostgreSQL/Redis/PHP-FPM ports.
Bind PostgreSQL and Redis to loopback and authenticate them. Local DB transport
is allowed by the existing self-managed security profile; remote DB requires
the managed `verify-full` TLS profile instead.

Use the normal immutable releases/current/shared layout. Mount/preserve private
storage across release replacement; source/public files are not web-writable.
There is no public `storage:link`. Build assets locally; no permanent Node/Vite
process is needed on EC2.

## Files prepared

| File | Purpose |
| --- | --- |
| `runtime.env.example` | Secret-free local PostgreSQL/Redis profile; backups unattested; mail disabled |
| `webinar-platform-services.target` | Selects one default worker, one email worker and the scheduler |
| `php-fpm-pool.conf.example` | Small-host candidate with two on-demand FPM children; load-test before acceptance |
| `redis-local.conf.example` | Local-only Redis, private authentication guidance, AOF and no silent queue/session eviction |
| `inspect-server.sh` | Read-only executable/CLI/FPM-extension/resource inspection |
| `../prepare-ec2-package.py` | Creates and independently verifies a data-free current-source ZIP |

The small-host target has the **same destination filename** as the standard
target. During Phase 7 install this profile's target in place of the four-email-
worker target; retain the existing worker and scheduler unit files. Never enable
both sets or start duplicate processes. Timeouts/visibility windows are unchanged:
default timeout 1800/visibility 2100; email timeout 60/visibility 120.

The existing systemd units consume the external runtime file. Before enabling,
verify its queue names/connections match the reviewed units. The email unit
prioritizes `account-emails,emails`. Raising worker count is a measured staging
decision, not a way to bypass the provider budget.

`php-fpm-pool.conf.example` is an overlay for the intended PHP pool, not a
replacement for every pool on the machine. Check CLI and FPM extensions
independently. `inspect-server.sh` does not prove DB/Redis connectivity, TLS,
backup integrity or application security; it never bootstraps Laravel.

Use Redis AOF persistence and `noeviction` for this dedicated instance. Never
silently evict sessions, locks or queued jobs to make a small host appear healthy.
The candidate memory cap must be measured; include persistence/disk errors and
pending queue recovery in the restart/restore drill. The shared outbox in
PostgreSQL is not a backup of every in-flight certificate-generation queue item.

During Phase 8 replace the **entire** Apache template `ServerName` with a DNS
hostname you control, matching APP_URL. Do not assume the example domain belongs
to the new account and do not purchase a domain under this zero-cash plan.

## Package locally — Phase 1

From the Laravel app directory, after the test/style/build gates:

```powershell
python deploy/prepare-ec2-package.py
python deploy/prepare-ec2-package.py --verify <path-to-created-zip>
python tests/deployment/rehearse_ec2_package.py <path-to-created-zip>
```

Default output is `../deployment-preparation/`, outside the app directory.
The optional local rehearsal extracts to a new OS temporary directory, installs
locked production Composer dependencies and verifies cached guest HTTP routes
with explicit SQLite-memory/log-mail isolation. It never connects to the actual
database or calls a provider; its output remains separate from the ZIP. The
rehearsal tool belongs to the local repo tests and is not a server installer.
The ZIP and adjacent SHA-256/inventory identify the exact dirty working snapshot.
They are **preparation artifacts**, not an approved release commit. Select and
review source into a clean immutable release before production; never replace
these files with an archive of old HEAD or an unfiltered workspace ZIP.

The archive includes app/config/migrations/resources/routes, private-storage
directory placeholders, locked Composer manifests, built public assets/fonts,
deployment templates and this guide. It excludes `.env`, credentials, database
files, uploads, certificate PDFs, logs, sessions/caches, previews, test results,
local vendor/node_modules and editor/Git metadata. No actual data is read or
copied. Do not add real data, secrets or keys into the package later.

The server must install locked production Composer dependencies before use.
Do not include the local development vendor directory. Package discovery has an
empty writable `bootstrap/cache`; caches are built from the server's reviewed
runtime configuration, not copied from local development.

## Runtime and safe staging — Phases 3–6

Use `runtime.env.example` as reference only. Fill actual secret values privately
in `/etc/webinar-platform/runtime.env` after server/account authorization.
Keep file permissions `root:www-data 0640`. No passwords in command arguments,
Git, ZIP, logs or chat.

- Existing encrypted data requires its **existing** `APP_KEY` and any necessary
  previous keys. Never run key generation to replace it. No real-data transfer
  is authorized now. An empty isolated staging database uses a separate key.
- The template deliberately uses `TRANSACTIONAL_EMAIL_PROVIDER=log`, blank
  credentials and a one-message/day ceiling. It cannot pass production mail
  acceptance. Do not flip provider/start workers with real data without approval.
- Never set backup flags/dates just to pass checks. Complete the restore drill
  first, then record factual evidence. Off-node files/key backups must survive
  loss of this EC2 account/instance.
- Rehearse forward/upgrade migrations in a disposable PostgreSQL database first.
  Actual DB migration/restore/import remains separately approval-gated.
- Account email/participant access shares the provider allowance. Reconcile the
  daily cap with actual free allowance before authorizing any 100-person run.

## Tomorrow's order

1. Verify eligible Free plan and credit-only usage; choose region/size from an
   actual priced estimate. Do not launch if Paid plan or eligibility is unclear.
2. Provision only the approved single node and encrypted storage. Retain
   account MFA/recovery; do not paste root credentials or private keys here.
3. Inspect prerequisites; install only needed packages under the main runbook.
4. Upload the code-only ZIP, verify SHA-256, install locked production deps in
   the new release, and configure isolated synthetic staging first.
5. Prove PostgreSQL migrations, Redis isolation, private persistence, restore,
   HTTPS, workers/scheduler and staging workload. Record resource/budget use.
6. Require zero cached production preflight failures and complete controlled
   role/form/upload/download smoke tests.
7. Only with fresh explicit authorization transfer/migrate real data or enable
   one controlled provider test and later real issuance. Keep old recovery assets.

No AWS resource, application data, worker, scheduler or live email was operated
when creating this preparation profile.
