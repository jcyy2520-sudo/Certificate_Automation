# Production readiness review — 21 October 2026

**Recommendation: NO-GO for production. This session completes preparation and isolated review, not deployment approval.**

**22 October hosting update:** AWS credit eligibility was unavailable. The owner
now authorizes committing/pushing this repository and a fresh, empty deployment
using only free services. Render Free plus Supabase Free is being prepared under
[`deploy/render/FREE_DEPLOYMENT.md`](deploy/render/FREE_DEPLOYMENT.md). This does
not authorize publishing the existing participant database or private files, and
does not clear the production acceptance gates below. See the deployment profile
for sleeping workers, temporary pending-import files and SQL session/cache limits.

**Subsequent preparation update, 21 October:** The owner selected EC2 planning
for 22 October, with USD 0 out of pocket and verified Free plan credits only.
See [the EC2 preparation profile](deploy/ec2/PREPARATION.md). A code-only package
is prepared separately; no account/instance exists for this plan yet and every
production environment/restore/data authorization gate below remains open.

The application has substantial automated coverage and passes the 100-recipient synthetic pipeline. This review also reproduced and corrected real recovery, recipient-identity, operations and container defects. A compatible production host is not selected. PostgreSQL/Redis concurrency, deployed configuration, persistent storage, restore and authorized provider/inbox verification remain open gates.

## Protected real data and release identity

The owner explicitly identified existing data as real and prohibited sending or changing it. This session did not read, migrate, import, clean, erase or change the actual participant database. It did not generate certificates for real participants, contact real mailboxes, start real queue/scheduler services, rotate credentials, provision infrastructure, commit, push or deploy. Actual credential values were not inspected.

PHP tests used SQLite `:memory:` or newly created temporary SQLite files. Browser workflows used the explicitly guarded `storage/app/private/workflow-browser.sqlite`, synthetic identities and the log provider. The opt-in workload used a UUID-named fake certificate disk, `example.test` recipients and HTTP-faked Brevo with stray requests prohibited. QA output under `storage/framework/testing/` is synthetic local evidence, not production data or release content.

The pre-existing dirty worktree was preserved. The fixes below supplement a large existing uncommitted implementation; they are not everything shown by `git diff`. `HEAD` alone does not identify the tested application. `production-review-evidence.json` records source/build checksums without including private data.

## Critical gates before deployment

| Gate | Impact | Required next evidence | Status |
| --- | --- | --- | --- |
| Compatible hosting and budget | Static-only or sleeping hosting cannot preserve PHP, workers, scheduler and private files | Choose a PHP 8.3+ host with supervised CLI workers, PostgreSQL, Redis, persistent private storage and recovery support | **Open**; free-only requirement, no approved target |
| PostgreSQL migration rehearsal | SQLite cannot prove PostgreSQL types, constraints, locking or upgrade behavior | Fresh and upgrade rehearsals on disposable PostgreSQL, including legacy owners, approvals and encrypted import issues; retain migration ledger and workflow results | **Open**; no local PostgreSQL/container runtime |
| Backup and restoration | Database-only recovery without matching key/private files loses usable history and PDFs | Restore DB + `APP_KEY`/previous keys + private storage to isolation; verify decryption, representative answers, authorization and PDF checksums; record recovery time/point | **Open**; no actual backup/restore evidence inspected |
| Production configuration/security | TLS, proxies, debug, session stores or credentials can defeat local safeguards | Cached-config validation, HTTPS/proxy/cookies, secrets, administrator MFA and zero `security:check --production` failures on the intended stack | **Open**; production config neither asserted nor changed |
| Workers/scheduler/restart | Certificates, email, outbox recovery and retention can stop | Separate visibility windows; CLI PCNTL; supervision, fresh heartbeats, queue drain, stop grace and host-restart recovery | **Open**; handler tests do not prove daemon operations |
| Production-parity capacity | Simultaneous activity can exhaust locks, DB connections, RAM or provider allowance | Staging public-form load and concurrent workers against PostgreSQL/Redis; p95/p99, failure rate, queue age, lock waits, RAM/CPU/disk and failure recovery | **Open**; local workload processes one job at a time |
| Controlled integration smoke | Queueing/API acceptance does not prove inbox receipt | Separately authorize one controlled mailbox, retain provider ID, verify inbox/attachment/retry, then a small observed batch and post-release role/form/download smoke | **Open; prohibited in this preparation session** |
| Immutable release artifact | Older commit/mixed dirty tree can omit tested code or migrations | Select reviewed changes into a clean release, lock dependencies, include built assets, exclude data/secrets/test output, record checksum | **Open**; no release published |

Use [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) for the authoritative operational sequence. This document is not authorization to execute it against existing data.

## Confirmed issues corrected

Priorities here reflect functional/release impact. The managed security scan separately calibrates exploit severity.

| Priority / defect | Issue and impact | Implemented solution | Verification and limit |
| --- | --- | --- | --- |
| **P1 — email acceptance followed by worker death** | Attempts/first timestamp were in the provider transaction. A killed process rolled them back, so late retry could bypass the idempotency deadline after possible acceptance | Commit encrypted unknown-outcome attempt envelope before transport; reacquire lifecycle locks/revalidate before sending; retain deadline and count attempts once | Real subprocess exits immediately after fake acceptance; independent DB connection sees committed marker; 26-minute-late retry cannot send |
| **P1 — queue expiry/post-acceptance DB failure** | Worker can call `failed()` before `handle()`. Generic failure could erase uncertainty and permit a new resend key | Preserve unknown outcome through queue expiry and unclassified failures; keep classified permanent/rate-limit refusals distinct | Actual crash plus `MaxAttemptsExceededException`, DB finalization failure and clean-refusal regressions pass; reconciliation required |
| **P1 — selected certificate failure retained unique claim** | Raw update bypassed model event; replacement raised `certificates.issuance_key` uniqueness violation | Explicitly clear claim on terminal processing failure; preserve file inventory and issued certificates | Fail-before reproduction; replacement job finalizes with one active claim and one delivery |
| **P2 — broker refusal stranded processing work** | Preparation committed before dispatch; caught refusal left an unqueued processing row | Fail only the prepared processing certificate/clear claim; expose protected confirmed Retry generation using saved placement | Broker exception regression checks failed state, Studio action and replacement preparation |
| **P2 — interrupted bulk progress** | Progress saved only at end; retry reset totals to remaining people; outcome write could be absent after PDF commit | Reconcile committed outcomes, preserve totals, save each recipient's progress, update existing outcomes; keep recovered/candidate sets disjoint | Interrupted and revoke-before-resume datasets pass; two people remain two outcomes |
| **P2 — wrong recipient in delayed correction UI** | Form URL targeted A but mutable preview/new dialog targeted B; late success/error could alter B's UI | Pin ID/action at dialog open; bind success/error/cleanup to modal session | Browser changes preview before submit, overlaps newer pending dialog, checks late failures and reloads identities |
| **P2 — pagination after async Studio entry** | Competing document listeners could refresh roster and replace page, losing designs | Studio-owned click listener on stable Studio element | Real-HTTP browser page 2/page 1 regression retains editor element/design |
| **P2 — hanging recipient request** | Mutation could hold busy dialog indefinitely; roster polling could hang | 30s roster and 120s mutation deadlines through response body; restore inputs/controls, explain uncertain outcome, never replay automatically | Browser advances through hung requests and verifies one mutation, cancel recovery and refresh recovery |
| **P2 — merge lost participation facts** | Responses moved, but missing registration/attendance timestamps did not; eligibility could disappear | Copy secondary `verified_at`/`checked_in_at` only if primary null; never copy mailbox verification | Three regressions cover transfer, preservation and no fabricated facts |
| **P2 — capacity command used wrong backend** | Empty SQL jobs table could hide a Redis backlog | Query configured default/account/participant queue backends; unknown/errors fail visibly; bounded encrypted-error iteration | Mocked Redis with empty SQL, unavailable metrics/no secret output, and 201-row pagination pass; actual Redis untested |
| **P2 — clean Docker package discovery** | Excluded cache directory absent before Composer package discovery | Create `bootstrap/cache` before autoload discovery | Source/framework checked; clean image build still required |
| **P2 — container worker signals/timeouts** | Image did not install PCNTL required for Laravel CLI alarms/signals | Build PCNTL; document CLI inventory and platform stop grace | Source checked; built extension inventory and actual termination untested |
| **P3 security — raw forwarded scheme to CGI HTTPS** | nginx promoted caller header to trusted server transport; Symfony treats even `http` as secure | Listener `$https if_not_empty`; Laravel owns forwarded trust | Offline untrusted/trusted/direct-TLS tests pass; no auth bypass/theft shown; actual nginx untested |
| **P3 — prerequisite/policy documentation** | Predis fallback advertised but absent; incomplete XML prerequisites; policy contradicted accepted Studio-add exception | Require installed phpredis/DOM/XMLReader/ZIP/CLI PCNTL; describe deliberate add exception and protected final issuance | Source/lock/framework comparison; no new dependency |
| **P3 — `/0` preflight inconsistency** | Helper accepted zero prefix though runtime parser already rejected it | Reject zero-prefix CIDRs in preflight too | IPv4/IPv6 `/0`/`/00` refused; valid exact/CIDRs retained; **not a reachable trust-all environment exploit** |

Primary changes: `SendTransactionalEmail`, `IssueSelectedCertificate`, `CertificateService`, selected-send/merge controllers, `CapacityReport`, Studio scripts/recipient row, Docker/nginx and runbook. No schema migration or dependency was added by this review.

## Final verification

| Check | Result | Boundary |
| --- | --- | --- |
| `composer run test` | **500 passed / 5,455 assertions** | Synthetic SQLite; baseline 479 / 5,290 |
| `php vendor/bin/pint --test` | **Passed** | PHP style |
| `npm run build` | **Passed** | PDF.js large-chunk advisory remains |
| `npm run test:js` | **9 passed** | JS units |
| `npm run test:e2e` | **33 passed** | Synthetic static snapshots, Chromium |
| `npm run test:workflow` | **13 passed** | Synthetic real-HTTP Chromium; MFA/step-up disabled in browser harness, separately feature-tested |
| Final Studio rerun | **5 passed** | Expanded correction/pagination/timeout regressions after final source |
| Opt-in 100-recipient workload | **1 passed / 623 assertions** | Real serialized SQL queue jobs and Dompdf; intercepted Brevo; sequential handlers |
| PDF accuracy suite | **8 passed / 26 assertions** | Synthetic ordinary/long/accented names |
| PDF extraction/render | **3 one-page PDFs**; exact expected names inside bounds; long/accented renders visually inspected | Synthetic designs, not actual owner's roster/artwork |
| Isolated cache harness | **Passed** config/route/view and cached root/login/guest/404 | Isolated SQLite/log/cache paths; no real cache clearing |
| Composer validation/platform requirements | **Passed** | Installed local PHP, not unbuilt image/production |
| Locked Composer/full npm audits | **Zero advisories** | Current advisory metadata, not application security proof |
| Strict UI static audit | **0 findings/errors/warnings/unresolved** | Contract audit; not WCAG certification |
| `git diff --check` | **Passed** | Existing dirty work retained |

The final workload's local selection request took **0.514s**, selection plus generation **21.946s**, and full test **34.349s**. Peak whole-test PHP allocation was **199,229,440 bytes (190 MiB)**, including framework/test/intercepted-HTTP retention. These are fixture measurements, not host sizing or production throughput promises.

It issued 100 PDFs and checked 100 exact PDF-to-recipient attachments. There were 100 simulated acceptances from 101 intercepted requests (one injected HTTP 429), zero duplicate certificates/acceptances, zero remaining/failed jobs, and retry payloads were removed after acceptance. Repeated selection and duplicate generation/delivery jobs were harmless in this tested path.

The shared 30-per-minute mail budget was exercised by advancing time through five cycles. The 34-second wall time does **not** mean real email can bypass that budget. Account and participant-access mail share the allowance. Confirmed inbox delivery was **not measured**. This does not prove concurrent PostgreSQL locks, Redis, network/provider performance, host RAM/CPU/timeouts or daemon memory recycling.

Machine-readable evidence and raw final logs are under `storage/framework/testing/`: `production-review-evidence.json`, `production-certificate-workload.json`, `production-review-php-final.log`, `production-workload.log`, `production-review-workflow-final.log`, `production-review-studio-final.log`, `production-review-snapshot.log`, PDF verification and dependency/cache/UI outputs. They are ignored local evidence.

## Assessment across the application

| Area | Verified/source assessment | Still needed |
| --- | --- | --- |
| Accounts/roles/permissions | Approval/setup/reset/suspension, owner isolation, audited admin grants, scoped routes and security-gate regressions | Deployed sessions, MFA enrollment/recovery, controlled mailbox flows |
| Four forms/responses | Registration/pretest/posttest/evaluation, validation, attempts/scores, mixed native/import workflows | Concurrent PostgreSQL submissions and realistic public arrival rate |
| Imports/reconciliation | Bounded CSV/XLSX, mapping/dry checks/held rows, exact identity, choice ambiguity/historical answers covered | PostgreSQL legacy-data upgrade; **real recipients not inspected or approved** |
| Eligibility/manual recipients | Shared queries/final locked recheck, automatic roster, audited exceptions, merge tests | Actual source reconciliation/policy-owner acceptance |
| Downloads/exports/privacy | Authorized private routes, path/revocation guards, CSV formula defense, streaming ZIP/retention/cleanup tests | Live storage ACLs/persistence/disk, restoration and scheduler |
| Frontend/UX | Phone-to-desktop/two-theme Chromium, keyboard drawers, history/offline/forms/import/Studio checks | Safari/Firefox, physical devices, zoom/AT and WCAG acceptance |
| APIs/integrations | Bounded JSON/navigation/status responses; durable email IDs; Brevo adapter; existing architecture appropriate | Live sender/IP/credit/provider settings, S3/storage and Redis |
| Maintainability | Blade/controllers/services remain suitable; no framework replacement, dependency or major redesign justified | Exact release selection and continued focused regressions; retain security freeze |

Security work combined independent baseline, operations/frontend investigation and fresh patch review. Managed scan `688cfb89-895f-4028-9ff3-9f023e2e58e7` records one low-severity pre-remediation nginx finding. Its sealed result belongs to the original snapshot; working-tree remediation/tests followed. Coverage is explicitly **partial**, not exhaustive line-by-line coverage of every repository file. Large import services, every historical migration, fixture/view/document and dependency were not completely reread. No high/critical exploit was confirmed in reviewed paths; this does not prove none exists elsewhere.

Tool accounting reports 30,261,568 total scan tokens, including 29,201,792 cached input tokens, across five threads. This is tool-reported context accounting, not a billing estimate or complete-source-coverage claim.

## Important remaining improvements and risks

1. **Host/process sizing:** choose FPM/default/email worker counts from measured resources and provider budget. Existing systemd target has several email workers. Never shorten visibility windows to accommodate free-tier request limits.
2. **Delivery/reconciliation:** provider acceptance is not inbox receipt. No bounce/delivery webhook was verified. Unknown outcomes deliberately require reconciliation.
3. **Changing batch membership:** whole-ready batches use current eligibility rather than an immutable original recipient manifest. Avoid editing rules/identities or revoking during normal runs. If fixed membership across such changes is required, evolve existing batch items and test on PostgreSQL first.
4. **Legacy selected-job identity:** terminal callbacks identify a participant rather than immutable work attempt. Stale old/new attempts need concurrent staging tests before increasing default worker concurrency; one-default-worker templates limit this path. No unsupported exactly-once guarantee is made.
5. **Historical rollback:** an initial workload teardown exposed an old SQLite `expires_at` index/drop-column failure in `down()`. Forward migrations pass; old rollback semantics were not rewritten. Newer migrations intentionally retain some encrypted/provenance columns. Use tested database/key/private-file restoration; PostgreSQL recovery remains unverified.
6. **Restore depth:** supplied helper's schema/ledger/selected-count checks alone do not prove decryption/private-file recovery; include application assertions.
7. **Container validation:** Docker/Podman/nginx unavailable here. Build the fixed image, check nginx/CLI extensions/private storage and exercise shutdown on the intended platform before approving that path.

Optional later work: provider delivery/bounce events, external queue-age/error alerts, broader browser/AT coverage and PDF.js delivery optimization. Refactoring large services can wait until needed and covered by acceptance tests.

## Free-only hosting evaluation

Cloudflare can provide DNS/TLS in front of a compatible PHP host. Normal free Workers/static hosting is not a drop-in Laravel runtime. Cloudflare Containers currently belongs to the **USD 5/month Workers Paid plan**, with additional resource billing; included usage is not a free-only plan. [Cloudflare container pricing](https://developers.cloudflare.com/containers/platform/pricing/)

Firebase's documented PHP-container path uses Cloud Run behind Hosting and requires **Blaze**. It is not guaranteed free-only hosting for this unchanged worker-based application. [Firebase Hosting/Cloud Run](https://firebase.google.com/docs/hosting/cloud-run)

An Always Free VM is structurally closer. Oracle documents such compute but warns of capacity shortages and idle reclamation. It is an option to evaluate, not an approved reliable deployment or availability promise. Self-managed database/Redis/backups and every release gate remain. [Oracle Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)

Retain the architecture, select/validate the host and keep real recipients untouched until the release gate is explicitly cleared.

## Later acceptance sequence — separate authorization required

1. Select exact clean source/artifact, locked dependencies and built assets.
2. Secure a compatible host; verify extensions, private persistence and resource limits.
3. Rehearse PostgreSQL upgrade and database/key/private-file restore in isolation.
4. Prove Redis isolation, supervised workers/scheduler/heartbeats/recovery and required simultaneous staging load.
5. Validate cached production configuration; require zero production preflight failures.
6. Under restricted traffic and separate authorization, smoke-test every role, login/MFA/logout/step-up, four forms/imports, upload/download, one certificate and one controlled mailbox. Record queueing, provider acceptance and inbox receipt separately.
7. Observe a small authorized batch, queues/errors/resources and rollback evidence before normal traffic.

**These operational actions remain unauthorized in this preparation session. Existing real data remains protected.**
