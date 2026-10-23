# Certimate UI and Webinar Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Refine the full Certimate interface, enforce administrator webinar locks, verify preservation and deploy the reviewed update.

**Architecture:** Reuse Blade layouts, Tailwind primitives and existing JavaScript lifecycle. Add webinar lock metadata and integrate it with the existing policy/middleware and actor revalidation. Release through the current pinned Render/Supabase deployment with a fresh backup and migration rehearsal.

**Tech Stack:** Laravel 12, PHP 8.3.36, Blade, Tailwind 4, Vite, Vitest, Playwright, PostgreSQL 17.11.

**Spec:** `docs/superpowers/specs/2026-10-24-certimate-ui-and-webinar-lock-design.md`

## Global Constraints

- Keep Certimate's own identity; Stash supplies inspiration for spacing and restraint.
- Block all non-admin management when locked, including the regular-user owner.
- Preserve `.env`, APP_KEY, credentials, real local/hosted records, files and existing workflows.
- No agent application mail/certificate sending or replay of old jobs; isolated log/fake verification only.
- No new dependencies, framework rewrite, speculative security changes or queue-window changes.
- Existing Free hosting and policy flags remain; report strict production limits accurately.
- Exclude unrelated `.claude/`, generated `storage/fonts/` and all private verification artifacts from publication.

## Review Focus

- Partial webinar projections must include lock state; otherwise a list/dashboard may expose protected details.
- Password grants, stale send plans, unscoped certificate routes and queued actor work must not bypass a newly applied lock.
- Unrelated users retain 404; only an otherwise entitled non-admin sees the professional restricted message.
- Mobile response tables must expose every answer, including long later columns and actions.
- Intermediate Studio widths, dark hover states, open overlays and asynchronous navigation must retain working controls.

### Task 1: Administrator webinar lock and authorization

**Files:** Create `database/migrations/2026_10_24_000100_add_administrator_webinar_locks.php`, `app/Http/Controllers/Admin/WebinarLockController.php`, `resources/views/errors/423.blade.php`, `tests/Feature/AdministratorWebinarLockTest.php`. Modify `app/Models/Webinar.php`, `app/Policies/WebinarPolicy.php`, `app/Http/Middleware/AuthorizeWebinarManagement.php`, `routes/web.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/Admin/WebinarController.php`, `app/Http/Controllers/Admin/ParticipantEligibilityController.php`, webinar/dashboard views.

**Interfaces:** `Webinar::isAdministratorLocked(): bool`; `WebinarPolicy::assigned(User, Webinar): bool` describes existing membership without the lock; `manage()` also enforces the lock. `scopeManageableBy($query, User)` filters visible content to accessible webinars. `WebinarLockController::update(Request, Webinar, AuditService): RedirectResponse|JsonResponse` accepts required boolean `locked` through named `admin.webinars.lock` PATCH route. New nullable fields are `admin_locked_at`, `admin_locked_by`.

- [x] Write failing tests for role/owner/assignment restrictions, HTML/JSON/navigation 423, unrelated 404, pre-existing password grant, child and unscoped routes, direct lock attempts, dashboard/selector leakage, actor revalidation, audit idempotence and preservation.
- [x] Run `php artisan test --filter=AdministratorWebinarLockTest`; confirm failures identify missing lock behavior.
- [x] Implement additive metadata, policy and central middleware checks before password/access prompts; keep administrator lock mutation outside the cross-owner content grant.
- [x] Implement minimal locked list rows, administrator controls/status and dedicated restriction page. Keep lock metadata guarded and changes transactional/audited.
- [x] Run new regressions plus `InvitationsAndAuditTest`, `AccountManagementSecurityTest`, `WebinarAuditAccessTest` and `FrontendNavigationTest`.

### Task 2: Shared Certimate interface refinement

**Files:** Modify `resources/css/app.css`, layouts, `partials/admin-rail.blade.php`, `partials/admin-mobile-header.blade.php`, `components/brand.blade.php`, `components/page-header.blade.php`, shared webinar navigation and affected overview/filter views. Keep existing DOM selectors and JavaScript behavior.

**Interfaces:** Existing `panel`, `field`, `field-label`, `button-*`, `badge-*`, rail/workspace classes and `data-*` lifecycle hooks remain canonical. Add focused semantic classes only where multiple pages share a genuine contract.

- [x] Refine existing base typography, neutral canvas/surfaces, control density, borders/focus and spacing in place; preserve the current accent identity and semantic status colors.
- [x] Apply theme-aware light navigation and quiet mobile drawers without removing collapse preferences or the Certimate logo.
- [x] Normalize page headers/actions, list rows/filter alignment and long text wrapping. Keep public/auth/error screens consistent through existing shared primitives.
- [x] Refine Studio panel/toolbar surfaces and ensure all existing controls remain reachable; leave certificate geometry/rendering unchanged.
- [x] Build assets before browser verification; review representative desktop/phone screenshots in both themes.

### Task 3: Complete responsive datasets and browser lock workflow

**Files:** Modify responsive table styles, `resources/views/admin/responses/index.blade.php` and other table views only where needed. Create `tests/workflow/administrator-lock.spec.js`, its synthetic fixture, and `tests/workflow/ui-refinement.spec.js` if existing responsive coverage needs populated long-data assertions.

**Interfaces:** Existing labeled scroll containers, semantic tables, response `data-label` fields and native details remain. Workflow fixtures use only `workflow-browser.sqlite`, testing environment and log/fake mail.

- [x] Add a failing browser assertion for a later response column at 320px, then expose every answer in stacked mobile records while preserving full-value disclosure and selection.
- [x] Exercise admin lock/unlock with a second authenticated manager/owner context, including a page already open, JSON/direct URLs and restored prior permissions after unlock.
- [x] Check populated tables, file controls, long names/titles, dialog scroll/focus, mobile drawers and Studio at 320/390/640/768/1024/1280/1920px as relevant.
- [x] Keep existing navigation/history/offline/unsaved-change and corrected-certificate progress tests passing.

### Task 4: Full verification and independent review

**Files:** Update `DESIGN.md`, `UX-CONTRACT.md`, a dated readiness report and repository-root `PROJECT_MEMORY.md`; private logs under `storage/framework/testing/ui-refresh-20261024/`.

- [x] Run `composer run test`, `vendor/bin/pint --test`, `npm.cmd run build` in that order, then JS, snapshot Chromium and real-HTTP workflow suites.
- [x] Run Composer/npm audits, isolated config/route/view cache checks and publication privacy/secret checks.
- [x] Rehearse migration/upgrade and data preservation in a disposable PostgreSQL schema. Retain exact migration and aggregate proof, remove only the known private test schema.
- [x] Have a fresh reviewer inspect the entire scoped diff for requirement gaps and regressions; fix concrete findings and rerun affected checks.
- [x] Verify local `.env` and database preservation against the private baseline; record scope, passed checks and remaining production gates.

### Task 5: Pinned release and deployment

**Files:** Reviewed source/docs only, published to existing `release/render-free-2026-10-22` branch. Private new backup/restore/migration proof and deployment attestation remain ignored.

- [ ] Review exact staged paths, exclude secrets/data/generated caches and preserve unrelated files; commit/push the tested release and verify remote SHA.
- [ ] Obtain a fresh encrypted hosted backup and prove restore plus the additive migration in a disposable schema, preserving the administrator and business projections. Never repeat clean-launch deletion.
- [ ] Apply only the reviewed additive migration through the backed-up release procedure, deploy the exact SHA to existing Render service and preserve current environment/domain/Free plan settings.
- [ ] Verify hosted SHA, schema, health/assets/TLS on both domains and no unexpected mail/job changes. Use non-sending synthetic lock proof; hand off real credential/MFA steps if required.
- [ ] Open the finished Certimate site in Chrome and report implementation, test/release evidence and strict production limitations truthfully.
