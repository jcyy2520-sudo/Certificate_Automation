# System Audit — Webinar Platform

**Audited:** 2026-08-24 · **Reviewer roles:** software architect, product manager, security reviewer, UI/UX auditor

**Verdict:** an unusually well-engineered security and privacy core, wrapped around a product carrying roughly 25–30% more surface area than it needs. Two confirmed functional defects, one orphaned feature, one systemic duplication pair, and a handful of scalability ceilings. Nothing here requires a rewrite.

---

## 1. System understanding

### 1.1 Technology stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3, Laravel 12 |
| Database | PostgreSQL in production (SQLite in tests); enforced by `security:check` |
| Cache / session / queue | Redis (three isolated databases, enforced by preflight) |
| Frontend | Server-rendered Blade + Tailwind 4 via Vite; ~470 lines of hand-written JS, no SPA framework |
| PDF | `barryvdh/laravel-dompdf` |
| QR | `endroid/qr-code` (2FA enrolment only) |
| 2FA | `pragmarx/google2fa-laravel` (TOTP + hashed recovery codes) |
| Storage | `league/flysystem-aws-s3-v3`, private disk |
| Email | Brevo HTTP API behind a `TransactionalMailer` contract, with a `log` driver for dev |
| Tests | PHPUnit 11 (186 tests, 2,580 assertions — **all passing**), Vitest, Playwright |

### 1.2 Size

| Area | Files | Lines |
|---|---|---|
| `app/` | 70 | 8,785 |
| `resources/views/` | 48 | 3,806 |
| `database/migrations/` | 26 | 1,479 |
| `tests/` | 36 | 5,434 |
| `routes/web.php` | 1 | 213 (65 routes) |

Test-to-code ratio is ~0.62:1 — healthy.

### 1.3 Architecture

A conventional Laravel MVC layout with a genuine service layer:

```
routes/web.php  ──►  Controllers  ──►  Services (business logic)  ──►  Eloquent models
                          │                  │
                          │                  ├─► Jobs (queue: certificates, email)
                          └─► Middleware     └─► Console commands (privacy/security cron)
```

**Strengths.** Clear separation of concerns. A single documented lock order (`webinar → form → participant → child rows`) applied consistently across public submission, magic-link issuance, certificate issuance, and email delivery. Every privacy-relevant state is re-read *inside* the transaction that acts on it. Encryption is applied at the model-cast layer, with explicit `Crypt::encryptString` for the bulk inserts that bypass casts.

**Weaknesses.** Two near-identical services; two divergent copies of form resolution; two copies of the local-datetime-to-UTC converter; eligibility rules implemented twice (SQL and PHP); no request objects (all validation inline in controllers); no policies or gates at all.

### 1.4 Database design

24 tables — 13 domain, the rest Laravel infrastructure.

```
users ──< webinars ──< forms ──< form_fields
                │        │    └─< questions ──< question_choices
                │        └─< submissions ──< submission_answers
                ├──< participants ──< participant_access_tokens
                │         └──< eligibility_overrides
                ├──< eligibility_rules
                ├──< certificate_templates ──< certificate_batches
                ├──< certificates
                └──< email_deliveries
audit_logs (polymorphic, pseudonymised)
```

Notable decisions, all deliberate and documented in the migrations:

- Public bearer tokens (form share token, certificate verification code) are stored **encrypted** with a separate SHA-256 lookup column. Lookups hit the hash; plaintext is decrypted only for display.
- Personal data the app never filters or sorts on (`submission_answers.value`, `email_deliveries.recipient_email/subject/payload/last_error`, `certificates.recipient_name/revocation_reason`, `eligibility_overrides.reason`) is encrypted at rest via Eloquent casts.
- Queryable identity (`participants.email`) is deliberately *not* encrypted; `email_normalized` carries a case-folded unique index.
- `webinars.retention_due_at` is a one-way privacy commitment. `Webinar::preservePrivacyLifecycle()` (app/Models/Webinar.php:113) permits shortening but silently refuses extension, and `WebinarController::assertRetentionDeadlineNotExtended()` raises a validation error rather than failing quietly.
- `certificates.issuance_key` is a computed uniqueness guard preventing two active certificates per `(webinar, participant)`.

### 1.5 Roles and permissions

There is exactly **one** role: `administrator` (`User::ADMINISTRATOR_ROLE`). `users.role` defaults to `pending` and `is_active` to `false` — fail-closed, set by migration `..._001230`. There are no policies, gates, or per-webinar ownership checks; `webinars.created_by` is recorded but never consulted for authorization. Every administrator can see and do everything.

Participants have no accounts. Access is granted by two independent bearer mechanisms:

1. an unguessable 24-character form share token (`/f/{token}`), and
2. a one-time 256-bit magic link that establishes a webinar-scoped session grant plus an encrypted 24-hour "pass" cookie.

Administrator protection layers, in order: `auth` → `EnsureAdministrator` (re-checks `is_active`/`role` on every request) → `auth.session` → `EnsureTwoFactorEnabled` → per-route `EnsureRecentPassword` on destructive and exporting actions.

### 1.6 Main workflows

1. **Set up** — admin creates a webinar; four forms (registration, pretest, posttest, evaluation), one eligibility rule (`registration`), and one certificate template are auto-provisioned.
2. **Collect** — admin opens the webinar and the relevant form, copies the share link, distributes it.
3. **Participant registers** — opens `/f/{token}`, enters email, receives a magic link, confirms, fills the form. Completing the *registration* form sets `verified_at`, which is the eligibility signal.
4. **Assess** — pre-test, post-test, and evaluation are gated on an existing registration.
5. **Qualify** — admin configures requirements (registration / attendance / pretest / posttest / evaluation, with optional minimum scores) and can override any individual participant.
6. **Certify** — admin uploads a finished certificate image, positions the recipient name in the certificate studio, selects participants, and sends. Rendering and emailing run on the queue.
7. **Verify** — anyone holding a code can check `/certificates/verify/{code}`; the page reveals only event, issue date, and validity.
8. **Retire** — archive (reversible) or delete (typed-title confirmation, tombstone-first, files removed before rows). Scheduled commands erase expired participant data and prune audit logs, access tokens, and email deliveries.

### 1.7 Integrations

Brevo transactional email only. The client is hardened well beyond typical: the origin is allowlisted (`isApprovedBaseUrl` rejects anything but `https://api.brevo.com/v3`), redirects are disabled, retries are limited to genuinely transient failures, the provider response body is **never** surfaced into exception messages (which would leak recipients into `failed_jobs`), and Brevo's `duplicate_parameter` idempotency response is treated as terminal success.

---

## 2. Architecture review

### A-1 · Two participant magic-link services are ~70% duplicated

**Priority: High · Difficulty: Moderate**

**Issue.** `ParticipantMagicLinkService` (466 lines) and `ParticipantStatusLinkService` (292 lines) implement the same protocol twice. Eight private helpers are byte-for-byte identical apart from an HMAC domain-separation string: `tokenHash`, `emailFingerprint`, `key`, `sessionKey`, `tokenLifetimeMinutes`, `sessionLifetimeMinutes`, `maximumLiveTokens`, `validGrant` (app/Services/ParticipantMagicLinkService.php:337-461 vs app/Services/ParticipantStatusLinkService.php:237-288). The `request`/`consume`/`participant` triads differ only in which parent they lock and which webinar statuses they accept.

**Impact.** This is the application's authentication boundary for participants. Every hardening change — token lifetime, sibling-revocation policy, grant validation, replay handling — must be made twice, and a fix applied to one copy but not the other is a silent authentication weakness. The copies have *already* diverged: the magic-link service revokes sibling tokens and re-verifies `email_verified_at`; the status service neither sets nor checks `email_verified_at` at all.

**Recommendation.** Extract an abstract `ParticipantLinkService` holding the token/fingerprint/session primitives and the transactional `consume` skeleton. The two concrete classes then supply only the HMAC domain string, the session key prefix, the delivery type, the acceptable webinar statuses, and the lock target (form vs webinar). Target: one ~250-line base plus two ~60-line subclasses.

**Tradeoff.** Inheritance adds one indirection to a security-critical path, and reviewers must read two files instead of one. That is worth accepting, because divergent copies of an auth protocol are the more dangerous failure mode, and the existing tests (`ParticipantMagicLinkSecurityTest`, `ParticipantStatusPageTest`) will hold the refactor honest. **If Section 3's recommendation to remove the status feature is accepted, this issue disappears entirely — decide feature scope (F-1) first, then refactor.**

---

### A-2 · Form resolution exists twice, and the two copies enforce different rules

**Priority: High · Difficulty: Easy**

**Issue.** `PublicFormResolver::resolve()` requires the webinar to be un-archived, not tombstoned, and inside a live retention window. `PublicFormController::resolve()` (app/Http/Controllers/PublicFormController.php:473) is a near-copy that only checks `archived_at`. `thanks()` is the sole caller of the weaker copy.

**Impact.** The thank-you page renders (and can display a score) for a webinar that has passed its privacy retention deadline or begun permanent deletion — exactly the states every other public route refuses. It is a narrow leak (the score requires a session-held `submission_id`), but it contradicts the system's own stated invariant.

**Recommendation.** Delete the private method; inject `PublicFormResolver` into `thanks()` as the other three actions already do.

**Tradeoff.** None. Pure duplication removal.

---

### A-3 · `localDateTimeToUtc()` is copy-pasted between two controllers

**Priority: Medium · Difficulty: Easy**

**Issue.** app/Http/Controllers/Admin/WebinarController.php:419 and app/Http/Controllers/Admin/FormController.php:474 hold the same DST-validating timezone converter, differing only in nullability handling.

**Impact.** A DST edge-case fix applied to one will silently miss the other. Both feed schedule fields that decide whether a public form accepts responses.

**Recommendation.** Move it to `App\Support\LocalDateTime::toUtc(?string, string, string): ?CarbonImmutable` and call it from both.

**Tradeoff.** None meaningful.

---

### A-4 · Eligibility rules are implemented twice — in SQL and in PHP

**Priority: Medium · Difficulty: Complex**

**Issue.** `EligibilityService::eligibleParticipantsQuery()` (app/Services/EligibilityService.php:108) expresses the rules as correlated subqueries; `EligibilityService::meets()` (line 207) expresses the same rules as in-memory collection filters. Both must agree for the participant list's complete/incomplete filter, the count badge, the studio's eligible set, and the issuance guard to be consistent.

**Impact.** Adding a sixth requirement type means writing it correctly in two languages. Get one wrong and a participant can appear eligible in the list but be rejected at issuance — or, worse, the reverse.

**Recommendation.** Keep both. The SQL form is genuinely required for pagination and counting at scale; the PHP form is genuinely required for the already-hydrated single-participant path. Instead, make the coupling explicit: move both into a `Requirement` value object per rule type exposing `matches(Participant): bool` and `constrain(Builder): void`, so the pair sits in one class and cannot be added in only one place.

**Tradeoff.** This is the most invasive suggestion in the audit and it does **not** fix a live bug — `EligibilityConsistencyTest` already asserts the two agree, and all 186 tests pass. Defer until a third requirement type is actually needed. Listed for visibility, not for immediate action.

---

### A-5 · No request objects; validation lives inline in controllers

**Priority: Low · Difficulty: Moderate**

**Issue.** Every rule set is written inline. `FormController` (520 lines) and `CertificateController` (483 lines) carry substantial validation, normalisation, and presentation-mapping logic alongside their orchestration.

**Impact.** Controllers are hard to scan, and the same field rules are restated with inconsistent limits across five files. Concretely: `full_name` is `max:180` on the public form but `max:120` in the admin add-participant form — a participant can register a name the admin UI then refuses to save.

**Recommendation.** Extract FormRequests for the six largest actions and align the field limits. Fix the `full_name` mismatch (settle on 180) as a standalone one-line change regardless.

**Tradeoff.** FormRequests scatter logic across more files. Given the size these controllers have reached, that is now a net win — but it is Phase-2 cleanup, not urgent.

---

### A-6 · Presentation logic lives in controllers as status-mapping `match` ladders

**Priority: Low · Difficulty: Easy**

**Issue.** `CertificateController::studio()` and `::status()` each contain three parallel `match(true)` ladders mapping `(certificate, delivery)` into a status slug, a label, and a detail string (app/Http/Controllers/Admin/CertificateController.php:62-96 and 140-190). `ParticipantController::index()` has a fourth, subtly different mapping for the same concept.

**Impact.** Four places define "what state is this certificate in", and they disagree: the participants table calls a `sent_at`-less issued certificate `queued`, whereas the studio calls it `sent` when no delivery row exists. An admin sees two different answers on two screens.

**Recommendation.** One `CertificateDeliveryState` enum with `from(Certificate, ?EmailDelivery)`, `label()`, and `detail()`. Delete the four ladders.

**Tradeoff.** None. A strict simplification that also fixes a real inconsistency.

---

## 3. Feature audit

| Feature | Verdict | Rationale |
|---|---|---|
| Webinar CRUD + auto-provisioned forms | **Keep** | Core. Auto-provisioning four forms on create is good product design. |
| Public form (share-link only, no navigation) | **Keep** | Core; the deliberate absence of an event listing is a real privacy asset. |
| Participant magic-link verification | **Keep** | Core. Well built. |
| Per-webinar "verification off" mode | **Keep** | Legitimate escape hatch for closed/internal events; fails closed. |
| Registration capacity | **Keep** | Correctly serialised under an exclusive webinar lock with a shared-lock fast path. |
| Attendance check-in | **Keep, fix** | Real value; the toggle is broken on the first table row (U-1). |
| Eligibility rules + overrides | **Keep** | Core. |
| Certificate template upload + visual name placement | **Keep** | Core; "upload the finished artwork, we only place the name" is the right simplification. |
| Certificate studio (select → preview → send) | **Keep, modify** | Good UX, but must be paginated (P-2). |
| **Participant status page** (7 routes, 292-line service, 4 views) | **Remove or finish** | Unreachable as shipped — see F-1. |
| **`CertificateController::addRecipient()`** | **Remove** | Dead code; no route (F-2). |
| **`certificates.batch` / `certificates.store` routes** | **Remove or expose** | Two of three issuance paths are unreachable from the UI (F-3). |
| **`Webinar::timezoneOptions()` / `timezoneLabel()`** | **Remove** | Dead — the timezone picker was removed from the UI (F-4). |
| **`EligibilityService::eligibleParticipantIds()`** | **Remove** | Only tests call it; `eligibleParticipantsQuery()` supersedes it. |
| **`password_reset_tokens` table** | **Remove** | No password-reset flow exists; recovery is CLI-only via `admin:create` (F-5). |
| `inspire` console command | **Remove** | Laravel skeleton leftover. |
| Reports & analytics | **Keep** | Small, cheap, genuinely useful funnel view. |
| CSV export | **Keep** | Correctly formula-escaped and password-gated. |
| Audit log | **Keep** | Pseudonymised, retention-bounded, sanitised. |
| Public certificate verification | **Keep** | Minimal disclosure; correct handling of revoked and erased states. |
| Email outbox + relay + heartbeat | **Keep** | This is what makes delivery observable rather than hopeful. |
| `security:check` preflight | **Keep** | Among the strongest parts of the codebase. |

---

### F-1 · The entire participant status feature is unreachable

**Priority: High · Difficulty: Easy**

**Issue.** `/f/{token}/status` and its six sibling routes, `ParticipantStatusController` (158 lines), `ParticipantStatusLinkService` (292 lines), `ParticipantStatusPageTest`, and four Blade views implement a private "check my progress and download my certificate" page. **Nothing links to it.** A grep across every Blade template finds inbound links only from the status flow's own pages. The thank-you page ends with *"You can close this page now"*; the certificate email links to public verification, not to status; the form pages have no footer.

**Impact.** Roughly 600 lines of security-sensitive code — a second bearer-token authentication protocol with its own session grants — is maintained, tested, and exposed to the internet to serve a page participants have no way to find. That is pure attack surface and maintenance cost for zero delivered value. It is also the source of duplication A-1.

**Recommendation.** Choose one, deliberately:

- **(a) Remove it.** Delete the seven routes, controller, service, four views, and test. Removes ~600 lines and one authentication protocol, and dissolves A-1. Right answer if certificate delivery by email is considered sufficient.
- **(b) Finish it.** Add one line to `public/thanks.blade.php` and one button to `emails/certificate-issued.blade.php` linking to `/f/{token}/status`. Roughly 10 lines of work, and the feature starts earning its keep.

**Tradeoff.** (b) is cheaper *today* and gives participants genuine self-service, reducing "where is my certificate?" mail to the organiser. But it commits the project to maintaining two participant authentication protocols indefinitely. (a) is the simplicity-first answer and matches the system's own stated principle that "a share link opens exactly one form and nothing else". **Recommendation: (b) if participant self-service is a product goal, (a) otherwise — but do not ship the current third state, where the code exists and nobody can use it.**

---

### F-2 · `CertificateController::addRecipient()` is dead code

**Priority: Medium · Difficulty: Easy**

**Issue.** app/Http/Controllers/Admin/CertificateController.php:196 is a 45-line public action that creates a participant and an unconditional eligibility override. No route maps to it. `CertificationManagementTest:333` even asserts the URL is *absent* from the page. Its behaviour is fully duplicated by `ParticipantController::store()`.

**Impact.** A reader must work out that this cannot be reached. Worse, if someone later adds a route to "re-enable" it, they would expose a participant-creation endpoint that — unlike `ParticipantController::store()` — does not accept an organisation and writes a different audit action, silently forking the two paths.

**Recommendation.** Delete the method and its now-unused imports.

**Tradeoff.** None.

---

### F-3 · Three certificate issuance paths exist; two are unreachable from the UI

**Priority: Medium · Difficulty: Easy**

**Issue.** `issueSelected` (studio — used), `store` (per-participant, `POST .../participants/{p}/certificate`), and `batch` (bulk, `POST .../certificates/batch` → `IssueCertificateBatch`). Grepping every Blade and JS file finds zero references to the latter two. The `batch` path additionally drags in the `certificate_batches` table, the `IssueCertificateBatch` job (83 lines), `CertificateService::issueBatch()` (~90 lines), and a `security:check` assertion about its 1800-second visibility timeout.

**Impact.** Two live, password-gated HTTP endpoints and ~200 lines of queue machinery exist for capabilities no operator can invoke. The `batch` path is also the *only* consumer of an entire database table.

**Recommendation.** The studio caps a send at 200 participants and tells the admin to *"use 'Send to all ready'"* for larger groups — a button that does not exist. Either wire that button to `certificates.batch` (the intended design, ~15 lines of Blade) or delete the batch path, its job, its service method, and the `certificate_batches` table. Delete `store` either way; it is strictly weaker than `issueSelected`.

**Tradeoff.** Keeping batch means keeping a table and a long-running job. Removing it means events with more than 200 eligible participants need repeated 200-at-a-time sends. Given that the UI already *promises* the bulk button, **wiring it up is the smaller lie to fix.**

---

### F-4 · Dead timezone helpers

**Priority: Low · Difficulty: Easy**

**Issue.** `Webinar::timezoneOptions()` (app/Models/Webinar.php:62) enumerates every IANA zone with live offsets; `timezoneLabel()` formats one. `WebinarController::validated()` documents that *"the timezone picker was removed from the UI"*. Neither method is referenced anywhere outside the model.

**Impact.** ~30 lines of dead code that would construct several hundred `DateTimeZone` objects if ever called, and reads as a live feature.

**Recommendation.** Delete both. Keep the `timezone` column and its `'sometimes','nullable','timezone'` rule, which are still used by imports and tests.

**Tradeoff.** If a timezone picker returns, the method is ~10 lines to rewrite. Not worth carrying.

---

### F-5 · Vestigial `password_reset_tokens` table

**Priority: Low · Difficulty: Easy**

**Issue.** The Laravel skeleton's table is created and truncated by `system:reset`, but the application has no forgot-password route, controller, view, or notification. Recovery is CLI-only — `admin:create` doubles as a password reset.

**Impact.** Minor schema noise that implies a self-service reset flow which does not exist. The genuine operational gap — an admin locked out of both password and 2FA needs shell access — is worth documenting rather than solving with a reset-email flow, which would weaken the current no-inbound-auth-email posture.

**Recommendation.** Drop the table in a migration. Add a "Locked out?" line to the login page pointing at the documented CLI recovery.

**Tradeoff.** None; the CLI-only recovery model is the safer choice and should stay.

---

## 4. UI/UX audit

### U-1 · Nested `<form>` breaks the attendance toggle on the first participant row *(confirmed defect)*

**Priority: High · Difficulty: Easy**

**Issue.** `resources/views/admin/participants/index.blade.php:73` opens `<form method="GET" action=".../certificates/studio">` around the whole participant table. Each row then opens a second `<form method="POST" action=".../attendance">` at line 118. HTML forbids nested forms; the parser resolves this by discarding the *first* inner form and re-parenting its controls onto the outer one.

Verified against the committed render in `public/_preview/participants.html` using headless Chromium:

```
forms in DOM:  .../attendance for participants 2,3,4,5,6,7,8   ← participant 1's form is absent
row-1 button "Not marked" → owner form: .../certificates/studio   (method GET)
rows 2-8 buttons          → owner form: .../participants/N/attendance (method POST)
```

**Impact.** Clicking *Present / Not marked* on the first participant of every page does not record attendance — it silently navigates to the certificate studio, discarding the admin's place in the list. The admin gets no error and no indication the mark failed; the badge simply still reads "Not marked" when they navigate back.

**This is recoverable, not fatal.** `resources/views/admin/participants/show.blade.php:18` carries a working, un-nested "Mark present" button, so the first participant's attendance can still be set from their detail page. Attendance is a selectable certificate requirement, but no participant is permanently blocked — an admin who notices the badge is not changing has a second path. The real cost is silent failure of a visible control, plus the confusion of being thrown into the certificate studio for no apparent reason.

The row-1 `@csrf` field is also absorbed into the GET form, so **the administrator's CSRF token is appended to the URL query string** on that navigation (see S-2). All 186 tests pass because `AttendanceTest` POSTs to the route directly and never renders the page.

**Recommendation.** Un-nest. Two clean options:

- Give the attendance buttons `form="attendance-{{ $participant->id }}"` and place the POST forms after `</table>`, outside the selection form. HTML's `form` attribute exists for exactly this.
- Or drop the wrapper form entirely and have `resources/js/app.js` build the studio URL from the checked boxes and `location.assign()` it — the selection state is already tracked there.

Then add a rendering assertion (Playwright, or a PHPUnit test asserting the response body contains no `</form>` between the table's opening and closing tags) so this class of defect cannot recur.

**Tradeoff.** The `form=` attribute approach is a two-line change and keeps everything server-rendered. Prefer it.

---

### U-2 · A failed public-form submission erases everything the participant typed

**Priority: High · Difficulty: Moderate**

**Issue.** To keep personal data out of session flash storage, `PublicFormController` calls `$request->request->replace([])` before re-throwing every `ValidationException` (lines 101, 110, 310). Laravel's `withInput()` then flashes an empty array, so every `old(...)` call in `resources/views/public/form.blade.php` resolves to nothing.

**Impact.** A participant who mistypes one email address, or misses one required question on a 20-question post-test, is returned a completely blank form with an error banner. On a long assessment this is severe enough to cause abandonment, and it will read to the participant as the system losing their work.

**Recommendation.** Keep the privacy property; restore the UX. Either:

- Selectively re-flash only the non-identifying answers — `fields.*` for non-`email`/`text`/`textarea` types and `questions.*` choice IDs are opaque integers and enum strings, not personal data — while continuing to drop `full_name`, `email`, `organization`, and free-text answers; or
- Render the invalid values back into the response directly from the rejected payload without touching the session at all, which preserves the "no untracked copy of personal data" invariant completely.

**Tradeoff.** The second option is more work but strictly better: it keeps the zero-session-PII guarantee *and* returns a fully repopulated form. The first is a ~15-line change that fixes most of the pain and leaves name and email (which browsers autofill anyway) blank. Either beats the current behaviour. Worth surfacing to the product owner explicitly: **the current design chose a privacy absolute over a usability essential without recording the decision.**

---

### U-3 · The public form shows a three-step progress bar for a one-step form

**Priority: Medium · Difficulty: Easy**

**Issue.** `resources/views/public/form.blade.php:36-42` renders a three-dot stepper whose fill is driven purely by how many inputs are filled in. There is no step 2 or step 3 — the form is a single page, and the thank-you page hard-codes the same stepper at 100%.

**Impact.** Participants read a stepper as "page 1 of 3" and expect two more screens. It manufactures anxiety on a form that is actually shorter than it looks, and the dots convey nothing the fill bar does not.

**Recommendation.** Replace the three dots with a plain determinate progress bar, or a "6 of 9 answered" counter. Keep the fill logic — it is good.

**Tradeoff.** The design reference presumably called for a stepper. A progress bar honours the visual intent without the false promise.

---

### U-4 · "Certificate delivery" reports different states on two screens

**Priority: Medium · Difficulty: Easy**

**Issue.** Same root cause as A-6. On the participants table, an issued certificate with no `EmailDelivery` row shows **Queued** (`participants/index.blade.php:141`); in the studio the identical record shows **Accepted by provider**. The studio also polls every 5 seconds and can flip a badge under the admin's cursor while the participants table beside it stays stale.

**Impact.** The admin cannot trust either screen and has no way to tell which is right.

**Recommendation.** Ship the `CertificateDeliveryState` enum from A-6 and render both screens from it.

**Tradeoff.** None.

---

### U-5 · The registration form's breadcrumb says "Tests"

**Priority: Low · Difficulty: Easy**

**Issue.** `resources/views/admin/forms/edit.blade.php:47` hard-codes `:crumbs="['Tests' => ...]"` for every form type. The sidebar correctly files the registration form under **Participants** and the pre/post/evaluation forms under **Tests**.

**Impact.** Small but constant: the breadcrumb contradicts the navigation the admin just used.

**Recommendation.** `$form->type === 'registration' ? 'Participants' : 'Tests'`.

---

### U-6 · The retention deadline is irreversible, and the UI never says so

**Priority: High · Difficulty: Easy**

**Issue.** The moment a webinar is published, `retention_due_at = ends_at + data_retention_days` is committed and can thereafter only be *shortened* (app/Models/Webinar.php:113). The settings form (`resources/views/admin/webinars/form.blade.php:200-203`) presents "Days to keep participant data after the event ends" as an ordinary number input defaulting to **7**, with no warning at all. The only feedback is a validation error — *"This change would extend the committed privacy deadline and was refused"* — after the admin has already tried.

**Impact.** An organiser who accepts the 7-day default and then discovers, on day 8, that they still need to issue certificates finds that issuance, the participant status page, and every share link are permanently dead for that event, with no supported remedy: `CertificateService::lockedIssuableWebinar()` refuses outright. This is the single most likely way for a real deployment to lose access to data it still needed.

**Recommendation.** Two small changes:

1. On the settings page, when `retention_due_at` is already committed, show the resolved absolute date and the words "This deadline can be shortened but never extended."
2. Reconsider the 7-day default. Certificate issuance is typically a post-event workflow spanning weeks. A default of 30–90 days with the same one-way guarantee is far safer, and the privacy commitment is unchanged in kind.

**Tradeoff.** Longer default retention keeps personal data around longer, which is genuinely worse for privacy in isolation. But a default that routinely destroys the organiser's ability to complete the event's *purpose* will drive operators to set 3,650 days out of fear — a strictly worse privacy outcome than a sane 30-day default they leave alone. Make the tradeoff visible in the UI and let the organiser choose knowingly.

---

### U-7 · The dashboard shows cross-webinar participant email addresses

**Priority: Low · Difficulty: Easy**

**Issue.** `DashboardController` loads the eight most recent `EmailDelivery` rows and `admin/dashboard.blade.php` renders `{{ $delivery->recipient_email }}` — decrypted participant addresses from every webinar — on the first screen after login.

**Impact.** Not an authorization flaw (all viewers are administrators), but it puts PII on the always-open landing page, where it is most exposed to shoulder-surfing and screen sharing. It also conflicts with the care taken everywhere else to keep addresses out of URLs, logs, and audit metadata.

**Recommendation.** Mask to the same first-character-plus-domain form the participant access screen already uses (`resources/views/public/access.blade.php:11-14`), or show only the webinar title and status.

**Tradeoff.** Slightly less at-a-glance debugging value. The studio's per-certificate delivery tracker already covers that need in context.

---

### U-8 · The certificate studio's 200-participant limit points at a button that does not exist

**Priority: Medium · Difficulty: Easy**

**Issue.** `CertificateController::issueSelected()` returns *"Send to up to 200 at once here. For a larger group, use 'Send to all ready'."* No such control is rendered anywhere (see F-3).

**Impact.** An organiser with 400 eligible participants hits a wall and is told to use a feature they cannot find.

**Recommendation.** Resolve together with F-3 — either add the button or change the message.

---

## 5. Database and performance audit

### P-1 · `Form::acceptsResponses()` runs an uncached `COUNT(*)` per call

**Priority: High · Difficulty: Easy**

**Issue.** `Form::registrationIsFull()` (app/Models/Form.php:98) executes a filtered participant `COUNT(*)` on every invocation. `acceptsResponses()` calls it, `closedReason()` calls it again, and `admin/webinars/show.blade.php:124-127` calls `acceptsResponses()` **twice per form** inside a loop over four forms — plus once at line 7 and once more in each `closedReason()` in the notice block.

**Impact.** The webinar overview page issues roughly 6–10 redundant `COUNT(*)` queries against `participants` on every load. On a large event that is the biggest table in the schema, and the count is unindexed for the `verified_at IS NOT NULL AND privacy_erased_at IS NULL` predicate. Cost grows linearly with participants, so the page gets slower exactly as the event succeeds.

**Recommendation.**

1. Memoise the count on the `Form` instance for the request (`private ?int $verifiedCount = null`).
2. In the Blade views, assign `$live = $form->acceptsResponses()` once per loop iteration and reuse it.
3. Add a partial index: `CREATE INDEX participants_webinar_active_verified_index ON participants (webinar_id) WHERE verified_at IS NOT NULL AND privacy_erased_at IS NULL AND deleted_at IS NULL;`

**Tradeoff.** Memoisation means a form object holds a stale count for the life of a request. That is correct for rendering; every *decision* path (`PublicFormController::submit`, `lockAcceptingContext`) already re-reads the form under a lock and constructs a fresh instance, so a memoised value can never leak into a capacity-enforcement decision.

---

### P-2 · The certificate studio loads every eligible participant with no pagination

**Priority: High · Difficulty: Moderate**

**Issue.** `CertificateController::studio()` hydrates the full eligible set (`->get()`, no limit) with their certificates, then renders one hidden checkbox plus one `<span data-name-store>` per participant into a hidden form, *plus* one `<article>` per participant in the sidebar with an `<img>` of the certificate background. The `?participants=` scoping is capped at 200 but is optional — arriving via the sidebar "Send certificates" link applies no cap at all.

**Impact.** A 3,000-participant event produces a multi-megabyte HTML document with 3,000 background `<img>` elements pointing at the same non-cacheable route (`Cache-Control: private, no-store`) — i.e. 3,000 image requests. The page will be effectively unusable well before that.

**Recommendation.** Paginate the certificate list (50–100 per page) and keep the selection in `sessionStorage` across pages, as `resources/js/app.js` already does for the participants table. Replace the per-row `<img>` with a single CSS `background-image` on a shared class, and allow that one background response to be cached `private, max-age=300`.

**Tradeoff.** Cross-page selection is fiddlier than one flat list. The existing `sessionStorage` selection code proves the pattern already works here.

---

### P-3 · The studio status endpoint polls an unbounded query every 5 seconds

**Priority: High · Difficulty: Easy**

**Issue.** `resources/js/certificate-editor.js:417` runs `setInterval(pollStatus, 5000)` for as long as the tab is open. `CertificateController::status()` loads **every** participant of the webinar — not just those with certificates — eager-loads their certificates, queries `email_deliveries` for all of them, and returns a JSON row per participant. There is no `ETag`, no cursor, no unchanged short-circuit, and no interval back-off.

**Impact.** One admin with the studio open on a 3,000-participant event generates 720 full-table scans per hour; two admins double it. Because certificate generation is queue-driven and can take minutes, tabs get left open. `document.hidden` is checked, which helps, but a visible idle tab polls forever.

**Recommendation.**

1. Restrict the query to participants that actually have a non-revoked certificate (`whereHas('certificates', ...)`) — usually a small fraction.
2. Return `304` via `ETag` computed from `max(certificates.updated_at)` + `max(email_deliveries.updated_at)`.
3. Back off: poll every 3 s while any row is `queued`/`sending`, then every 30 s, then stop after 5 minutes of no change with a "Refresh" affordance.

**Tradeoff.** Back-off means a status can lag by up to 30 s once things are quiet — invisible in practice, because nothing is changing when the interval is long.

---

### P-4 · `webinar-nav` fires an extra query on every webinar page

**Priority: Low · Difficulty: Easy**

**Issue.** `resources/views/components/webinar-nav.blade.php:5` runs `$webinar->forms()->select(...)->get()` on every render, with a comment noting it deliberately ignores whatever the controller eager-loaded.

**Impact.** One extra query per page — small, but paid on every admin screen inside a webinar, duplicating data `WebinarController::show()` and `FormController::edit()` already loaded.

**Recommendation.** `$webinar->relationLoaded('forms') ? $webinar->forms : $webinar->forms()->get(...)`. The component keeps working standalone but stops re-querying when the controller already provided the data.

**Tradeoff.** Slightly more logic in the component; worth one query on every page.

---

### P-5 · A now-useless unique index survives on the encrypted `verification_code`

**Priority: Low · Difficulty: Easy**

**Issue.** Migration `..._000700` widened `certificates.verification_code` to `text` and added `certificates_verification_code_hash_unique`, but never dropped the original `certificates_verification_code_unique`. Because the column now stores AES ciphertext with a random IV, two identical codes would produce different ciphertexts and the index would not catch the collision.

**Impact.** An index that costs writes and enforces nothing, plus a misleading signal that uniqueness is guaranteed on that column. (Uniqueness *is* correctly guaranteed — by the hash index.) On MySQL a unique index on a `text` column would additionally require a prefix length; this is PostgreSQL-only code by design, so nothing breaks, but it is portability debt.

**Recommendation.** Drop the stale index in a follow-up migration.

---

### P-6 · Dashboard counts are unbounded aggregates

**Priority: Low · Difficulty: Moderate**

**Issue.** `DashboardController` computes four `COUNT(*)` values across the whole `webinars`, `participants`, `certificates`, and `email_deliveries` tables on every dashboard load. The single-round-trip `selectSub` construction is a nice touch, but the counts themselves are full scans.

**Impact.** Fine at hundreds of thousands of rows; degrades past that. Not a current problem.

**Recommendation.** Leave as is. If the counts become slow, cache them for 60 seconds (`Cache::remember`) rather than adding counter columns — these numbers are informational, not transactional.

**Tradeoff.** Cached counts can be up to a minute stale. Acceptable for a dashboard tile; not acceptable for the capacity check, which correctly does not use this path.

---

### P-7 · Unused schema

**Priority: Low · Difficulty: Easy**

`password_reset_tokens` (F-5) has no code path. `certificate_batches` is written only by the unreachable `batch` route (F-3). `certificate_templates.template_path`, `canvas_width`, and `canvas_height` are set at creation and never read — the real geometry lives in `layout.bg_w`/`bg_h`. `forms.settings`, `form_fields.metadata`, `questions.metadata`, and `webinars.settings` are nullable JSON columns nothing writes.

**Recommendation.** Drop `template_path`, `canvas_width`, `canvas_height`, and the four unused JSON columns in one migration. Decide `certificate_batches` and `password_reset_tokens` alongside F-3 and F-5.

**Tradeoff.** Speculative JSON "settings" columns feel like cheap future-proofing, but they are load-bearing in exactly one way: they make readers assume configuration exists where none does. Remove them; adding a column later is one migration.

---

## 6. Security audit

**Overall: strong.** This codebase does several things most Laravel applications do not: keyed HMAC fingerprints instead of raw hashes for IPs and user agents; one-time TOTP time-step consumption to defeat same-window replay; magic-link credentials delivered in the URL *fragment* so they never reach a server log or proxy; tombstone-before-delete for webinar removal; a fail-closed `security:check` preflight; and provider error messages scrubbed so recipients cannot leak into `failed_jobs`. The findings below are refinements, not a rebuttal.

### S-1 · Only administrators exist; there is no per-webinar authorization

**Priority: Medium · Difficulty: Complex**

**Issue.** `EnsureAdministrator` is the entire authorization model. `webinars.created_by` is recorded but never checked. No `Policy` or `Gate` exists anywhere in `app/`. Every administrator can read every participant's name, email, answers, and scores across every webinar, and can delete any webinar.

**Impact.** Correct and appropriate for a single-organisation deployment — which is what the README and the CLI-only account creation describe. It becomes a serious problem the moment two departments, an external co-host, or a contractor share the instance, because there is no way to scope access short of a separate deployment.

**Recommendation.** Do **not** build multi-tenancy speculatively. Instead: (1) document explicitly, in the README and the deployment checklist, that every administrator is a full-system administrator and that account creation is therefore a trust decision; (2) if scoping is ever required, add a `webinar_user` pivot and a `WebinarPolicy` rather than retrofitting roles onto `users.role`.

**Tradeoff.** Building roles now would add real complexity to every controller for a requirement that may never arrive — the audit's own principle against speculative features applies. Documenting the limit is the right move today.

---

### S-2 · The administrator CSRF token is leaked into the URL query string

**Priority: Medium · Difficulty: Easy**

**Issue.** A direct consequence of U-1. Because the first row's `<form method="POST">` is discarded by the parser, its `@csrf` hidden input is re-parented into the enclosing `method="GET"` form. Submitting that form — which is what the row-1 attendance button now does — serialises `_token=<session CSRF token>` into the query string of `/admin/webinars/N/certificates/studio`.

**Impact.** The session CSRF token lands in browser history and would land in any access or upstream proxy log for that request. `Referrer-Policy: no-referrer` prevents cross-origin referrer leakage and the token alone is not an authenticator, so practical severity is limited — but a session-bound secret in a URL is a real hygiene failure and would be flagged by any external pentest.

**Recommendation.** Fixed automatically by U-1. Verify afterwards that no rendered admin page contains a `method="GET"` form enclosing an `@csrf` field.

---

### S-3 · Destructive actions are gated inconsistently

**Priority: Medium · Difficulty: Easy**

**Issue.** `EnsureRecentPassword` protects export, delete, archive, link rotation, field/question deletion, eligibility override, and all certificate issuance and revocation. It does **not** protect `participants.store` (creates a participant *and* an unconditional, never-expiring `eligible` override), `participants.attendance` (satisfies a certificate requirement), `participants.name` (rewrites the name printed on every future certificate), or `certification.design`.

**Impact.** An attacker with a hijacked admin session — or someone at an unlocked workstation — can manufacture a fully eligible certificate recipient with an attacker-controlled name and email in two ungated requests, then needs to pass the password gate only once at the final send. The gate on issuance is doing real work, but the inputs it consumes are unprotected.

**Recommendation.** Add `EnsureRecentPassword` to `participants.store` and `participants.name`. Leave `attendance` and `certification.design` ungated — high-frequency, low-consequence, and both audited — but record that decision in `SECURITY.md`.

**Tradeoff.** Gating `participants.store` means a password prompt during bulk manual entry, which is genuinely annoying. Mitigation: the 900-second confirmation window means one prompt covers a 15-minute data-entry session. An acceptable cost for an action that grants certificate eligibility unconditionally.

---

### S-4 · Manually added participants receive a permanent, unconditional eligibility override

**Priority: Medium · Difficulty: Easy**

**Issue.** `ParticipantController::store()` writes `EligibilityOverride{decision: 'eligible', expires_at: null}` for every manually added participant. `EligibilityService::evaluate()` short-circuits on any active override, so that person is permanently eligible regardless of every configured requirement, and the participants table shows them as `complete`.

**Impact.** Intentional — the row exists for off-platform attendees — but it is a silent privilege grant that the interface offers no way to *remove*. The only recourse is adding a countervailing `ineligible` override, which then also has to be reasoned about. A participant added by mistake is permanently certificate-eligible.

**Recommendation.** Two low-cost improvements: (1) surface active overrides on the participant detail page with a password-gated "Remove override" action; (2) show the override badge in the eligibility column with a tooltip naming who granted it and when — the data is already loaded, since `eligibilityOverrides.administrator` is eager-loaded in `show()`.

**Tradeoff.** None. This makes an existing power visible rather than adding a new one.

---

### S-5 · Some writes bypass the deletion tombstone

**Priority: Low · Difficulty: Easy**

**Issue.** `WebinarController::update`, `::archive`, and `ParticipantController::store` all check `deletion_started_at !== null` and abort with 409. `CertificateController::revoke`, `::download`, `ParticipantController::attendance`, `::updateName`, and `::override` do not.

**Impact.** Small — `CertificateService` re-checks the tombstone under lock before any issuance, so no new PII-bearing artifact can be created. But an admin can revoke a certificate or rename a participant on a webinar that is mid-permanent-deletion, producing audit entries against rows that are about to vanish and confusing incident review.

**Recommendation.** Hoist the check into a small `RejectDeletedWebinar` middleware applied to the whole `webinars/{webinar}/...` group, instead of repeating `abort_if` in each action.

---

### S-6 · The `local` filesystem disk is the default for certificates in `.env.example`

**Priority: Low · Difficulty: Easy**

**Issue.** `config/webinar.php` resolves `certificate_disk` to `env('CERTIFICATE_DISK', env('FILESYSTEM_DISK', 'local'))`, and `.env.example` ships `FILESYSTEM_DISK=local`.

**Impact.** Correctly handled from a security standpoint — `security:check --production` fails if the disk is public or served, and `local` with `serve => false` is genuinely private. But a deployment that stores certificates on a container's local disk loses them on redeploy, and nothing warns about that. This is a durability gap wearing a security setting's clothes.

**Recommendation.** Add a production preflight check that `certificate_disk` is `s3` (or at minimum warn when it is `local`), on the grounds that generated PDFs must survive a container restart.

**Tradeoff.** Forces S3/MinIO configuration before production. Given that certificates are the system's primary durable output, that is the correct requirement.

---

### S-7 · CSP relies on `style-src 'unsafe-inline'`

**Priority: Low · Difficulty: Moderate**

**Issue.** `SecurityHeaders` sets a strict nonce-based `script-src` (excellent) but `style-src 'self' 'unsafe-inline'`, needed for the dynamic width percentages in the reports and progress bars.

**Impact.** Low. With `script-src` locked to a nonce and `script-src-attr 'none'`, injected inline CSS cannot execute; residual risk is CSS-based exfiltration, which requires an existing injection point. The application escapes all output through Blade and uses no `innerHTML` (Trusted Types is enforced), so no injection point is known.

**Recommendation.** Acceptable as-is; document the decision. If tightening is desired later, move the handful of dynamic widths to CSS custom properties set via a nonce'd `<style>` block and drop `'unsafe-inline'`.

---

### S-8 · Positive findings worth preserving

Called out so a future refactor does not remove them by accident:

- **`AuthController::DUMMY_PASSWORD_HASH`** keeps unknown-email logins on the same bcrypt timing path — genuine anti-enumeration, not theatre.
- **`ParticipantMagicLinkService::consume()`** revokes *every* sibling token on use, closing the forwarded-email replay window, and spends the token even when the subject was withdrawn, so it cannot be retried after a data restore.
- **`PublicFormController::submit()`** returns an identical thank-you response for erased, deleted, and attempt-exhausted participants, so the form cannot be used as an email-participation oracle.
- **`ParticipantController::safeCsvCell()`** prefixes `= + - @` cells to defeat spreadsheet formula injection, correctly noting that quoting alone does not protect.
- **`CertificateFileService::delete()`** refuses any path that is not exactly `certificates/{public_id}.pdf`, so a tampered database row cannot become an arbitrary-file-deletion primitive.
- **Migration `..._001200`** refuses to run if duplicate active certificates exist, rather than guessing — fail-loud beats fail-quiet for data integrity.
- **`SendTransactionalEmail::deliveryIsInvalid()`** re-verifies webinar status, retention, form acceptance, participant existence, *and* that the delivery's recipient still matches the participant's current email, immediately before contacting the provider.

---

## 7. Improvement roadmap

Each phase is independently shippable. Phases 1 and 2 have the clearest return.

### Phase 1 — Remove unnecessary features and complexity

*Goal: shrink the surface before improving it. No behaviour change for anything an operator actually uses.*

| # | Action | Ref | Difficulty |
|---|---|---|---|
| 1.1 | **Decide the participant status feature: finish it (~10 lines) or delete it (~600 lines).** Do this first — it determines whether A-1 exists. | F-1 | Easy |
| 1.2 | Delete `CertificateController::addRecipient()` | F-2 | Easy |
| 1.3 | Delete `certificates.store`; wire up or delete `certificates.batch` + `IssueCertificateBatch` + `issueBatch()` + `certificate_batches` | F-3, U-8 | Easy |
| 1.4 | Delete `Webinar::timezoneOptions()` / `timezoneLabel()` | F-4 | Easy |
| 1.5 | Delete `EligibilityService::eligibleParticipantIds()`; point its two tests at `eligibleParticipantsQuery()` | §3 | Easy |
| 1.6 | Drop unused schema: `password_reset_tokens`, `certificate_templates.template_path/canvas_width/canvas_height`, the four unused JSON `settings`/`metadata` columns, the stale `certificates_verification_code_unique` index | F-5, P-5, P-7 | Easy |
| 1.7 | Delete the `inspire` command | §3 | Easy |

**Expected outcome:** ~700–900 lines and 4–6 tables/columns removed; one participant authentication protocol eliminated if 1.1(a) is chosen.

---

### Phase 2 — Fix bugs, inconsistencies, and architecture issues

| # | Action | Ref | Priority |
|---|---|---|---|
| 2.1 | **Un-nest the participant-table forms** (fixes broken row-1 attendance *and* the CSRF-in-URL leak); add a rendering assertion | U-1, S-2 | High |
| 2.2 | Introduce `CertificateDeliveryState`; delete the four divergent status ladders | A-6, U-4 | High |
| 2.3 | Delete `PublicFormController::resolve()`; use `PublicFormResolver` in `thanks()` | A-2 | High |
| 2.4 | Extract `App\Support\LocalDateTime::toUtc()` from both controllers | A-3 | Medium |
| 2.5 | Extract the shared participant-link base class **(only if 1.1(b) was chosen)** | A-1 | High |
| 2.6 | Add `EnsureRecentPassword` to `participants.store` and `participants.name`; add a `RejectDeletedWebinar` middleware to the webinar route group | S-3, S-5 | Medium |
| 2.7 | Align `full_name` max length (180) across the public and admin forms | A-5 | Low |

---

### Phase 3 — Improve UI/UX and user workflows

| # | Action | Ref | Priority |
|---|---|---|---|
| 3.1 | **Restore form repopulation after a failed public submission** without reintroducing PII into session flash | U-2 | High |
| 3.2 | **Surface the retention deadline's irreversibility** on the settings page; raise the default from 7 days to 30 | U-6 | High |
| 3.3 | Replace the false three-step stepper with a determinate progress bar | U-3 | Medium |
| 3.4 | Show active eligibility overrides on the participant page with who/when, plus a password-gated "Remove override" | S-4 | Medium |
| 3.5 | Mask recipient email addresses on the dashboard | U-7 | Low |
| 3.6 | Fix the registration form's "Tests" breadcrumb | U-5 | Low |
| 3.7 | Add a "Locked out?" note on the login page pointing at the CLI recovery path | F-5 | Low |

---

### Phase 4 — Optimize performance and scalability

| # | Action | Ref | Priority |
|---|---|---|---|
| 4.1 | Memoise `registrationIsFull()`, hoist repeated `acceptsResponses()` calls out of Blade loops, add the partial index on `participants` | P-1 | High |
| 4.2 | Paginate the certificate studio; share one background image instead of one per row | P-2 | High |
| 4.3 | Narrow the studio status query, add `ETag`/`304`, back off the 5-second poll | P-3 | High |
| 4.4 | Make `webinar-nav` reuse an already-loaded `forms` relation | P-4 | Low |
| 4.5 | Load-test one webinar at 5,000 participants across: participants index, studio, status poll, CSV export, and `privacy:erase-expired-participants` | P-1..P-3 | Medium |
| 4.6 | Revisit dashboard count caching only if 4.5 shows it matters | P-6 | Low |

---

### Phase 5 — Final security review and production readiness

**Re-review after Phases 1–4:**

- Re-run `php artisan security:check --production`; require a clean pass.
- Re-run the full suite (186 tests today) plus the new rendering assertions from 2.1.
- Confirm no rendered admin page contains a `method="GET"` form enclosing `@csrf` (S-2).
- Confirm the `EnsureRecentPassword` route list matches `SECURITY.md`.
- Re-read `PrivacyErasureTest` and `WebinarPrivacyLifecycleTest` against any schema dropped in 1.6.

**Production readiness checklist:**

| ✔ | Item |
|---|---|
| ☐ | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` on HTTPS with the real hostname |
| ☐ | `APP_KEY` generated once and backed up **out of band** — every encrypted column and every HMAC fingerprint depends on it; losing it is unrecoverable data loss |
| ☐ | `ADMIN_PASSWORD` absent from the runtime environment |
| ☐ | PostgreSQL with `DB_SSLMODE=verify-full` and a pinned `DB_SSLROOTCERT`; `MANAGED_POSTGRES=true` only after provisioning managed backups and restricted ingress |
| ☐ | Redis with **three isolated databases** for cache, sessions, and queues (`SESSION_CONNECTION=session`) |
| ☐ | `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=strict`, `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME<=30` |
| ☐ | `CERTIFICATE_DISK` on S3/MinIO — **not** `local`; verify a PDF survives a container restart (S-6) |
| ☐ | `TRANSACTIONAL_EMAIL_PROVIDER=brevo` with a freshly rotated `BREVO_API_KEY` from a secret manager |
| ☐ | Queue visibility timeouts: default queue `retry_after` > 1800 s; email queue `retry_after` ≈ 120 s and < 1800 s (inside Brevo's idempotency window) |
| ☐ | **Two** workers running: the default queue (certificates) *and* the `emails` queue — the studio's stall warnings exist because forgetting one is the most common operational failure |
| ☐ | `php artisan schedule:work` (or a cron entry) running — otherwise retention erasure, audit pruning, token pruning, and the outbox relay never execute |
| ☐ | `security:check --production` passes with zero failures |
| ☐ | At least one administrator has completed 2FA enrolment, and **recovery codes are stored offline** |
| ☐ | CSP enforced (`SECURITY_CSP_ENABLED=true`, `SECURITY_CSP_REPORT_ONLY=false`); HSTS configured with a considered `max-age` |
| ☐ | `APP_TRUSTED_PROXIES` set to exact IPs/CIDRs **or** empty for direct TLS — never `*` |
| ☐ | A retention default agreed with whoever owns the privacy decision, and its irreversibility understood by whoever will create webinars (U-6) |
| ☐ | Documented and accepted: **every administrator account is a full-system administrator** (S-1) |
| ☐ | A restore drill performed: prove a database backup plus the `APP_KEY` actually decrypts participant data |

---

## 8. Summary of priorities

| Priority | Count | Items |
|---|---|---|
| **Critical** | 0 | — nothing found that causes unrecoverable data loss or an exploitable breach |
| **High** | 9 | A-1, A-2, F-1, U-1, U-2, U-6, P-1, P-2, P-3 |
| **Medium** | 11 | A-3, A-4, A-6, F-2, F-3, U-3, U-4, U-8, S-1, S-3, S-4 |
| **Low** | 12 | A-5, F-4, F-5, U-5, U-7, P-4, P-5, P-6, P-7, S-5, S-6, S-7 |

**The three changes with the highest return, in order:**

1. **U-2 + U-6** — the two places where a well-intentioned privacy control produces a user-hostile outcome nobody wrote down as a tradeoff. U-6 in particular is the most likely way a real deployment loses access to data it still needed.
2. **F-1** — a single scoping decision that either removes ~600 lines of security-sensitive code or turns a dormant feature into a working one for ~10 lines.
3. **U-1** — a confirmed silent failure of a visible control, fixable in two lines. Recoverable via the participant detail page, so it is cheap-to-fix rather than urgent.

Everything else is genuine but incremental. The security core, the locking discipline, the privacy lifecycle, and the test suite are all above the standard this kind of application usually reaches. The work ahead is mostly about removing what was built and never wired up, and about making a few implicit tradeoffs explicit.
