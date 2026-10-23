# Certimate interface refinement and administrator webinar locks

Date: 24 October 2026 (Asia/Manila)

Status: approved by the owner on 24 October 2026. Independent builder review:
"nothing important left". The owner explicitly reiterated that Certimate must
retain its own identity and must not literally copy Stash.

## Intent and agreed scope

Refine the entire existing webinar management application into a calm, readable,
consistent workspace, using the owner's Stash application as visual inspiration.
Keep Certimate's name, logo, Inter font, neutral accent, light/dark preference,
features, meaningful URLs, role permissions and established workflows.

The owner selected refinement of shared layouts/components over redesign of
individual workflows. The owner also confirmed that an administrator lock must
block **all non-admin management**, including a regular-user owner. Public
participant forms remain governed by their existing availability rules.

## Constraints and current architecture

The application is Laravel 12, Blade, Tailwind 4/Vite and small JavaScript modules.
There is no Livewire or SPA framework. Shared layouts, `panel`, `field`,
`button-*`, `badge-*`, page headers and navigation already own most appearance.
Existing asynchronous navigation retains server authorization, browser history,
unsaved changes and normal-document security/download boundaries.

Use the current services for imports, identity, eligibility, certificates,
private storage, email and audit. Preserve strict CSP, safe DOM construction,
database lock order, queue visibility windows, idempotency and transport policy.
The scoped lock feature is explicitly requested authorization work; unrelated
security/crypto/privacy code remains frozen.

The current source is commit `547e2c3336c0d01c91d565ab30789a8ce424a5d6` on
`release/render-free-2026-10-22`. Existing unrelated `.claude/` and generated
`storage/fonts/` are excluded. Preserve the local `.env`, APP_KEY, passwords,
all real participant records, uploads, certificate PDFs and mail history.
The owner's previous no-agent-send instruction remains applicable.

## Approaches

1. **Shared refinement (owner selected).** Improve existing typography,
   spacing, surfaces, navigation and responsive primitives, then correct pages
   that need specific treatment. This gives broad consistency with small,
   reviewable changes and preserves familiar workflows.
2. **Page-by-page workflow redesign.** Restructure navigation and screen flows
   individually. This allows larger changes to each screen but adds disruption,
   duplication and regression risk. The owner did not select this approach.

No dependency or frontend-framework replacement is needed.

## Interface design

Use Stash's restrained hierarchy and quiet surfaces: an off-white canvas, white
panels, fine neutral borders, charcoal primary actions, muted supporting text and
generous spacing between meaningful sections. Keep status colors for operational
meaning. Avoid decorative gradients, heavy shadows, oversized headings and new
animation. Keep both themes legible and consistent.

Use one typography and control scale. Page titles should be about 28-32px on
desktop and 24-26px on phones; body/table text remains comfortably readable.
Prefer sentence-case labels, concise helper text and clear primary actions.
Give related controls consistent heights, spacing, focus states and disabled/busy
states. Preserve required confirmation, validation, retry and progress behavior.

The light-theme desktop navigation becomes a quiet white sidebar with a subtle
border and a clear charcoal selected item. Dark mode retains the corresponding
charcoal surfaces. Preserve collapse preferences, visible labels, active-route
indicators and existing mobile drawers. Webinar navigation keeps its established
categories and paths. Use the existing Certimate brand assets.

Apply the shared treatment to dashboard and webinar list/overview/settings,
forms and responses, participants and eligibility, imports and held-row review,
documents/uploads, Certificate Setup/Studio, messages/logs/audit, user management,
profile/security, authentication, public forms/verification and errors.

Certificate Studio retains its canvas, full font catalog, percentage geometry,
name-fitting logic, numeric/keyboard alternatives, recipient selection, preview,
shared-style save, corrected-file handling and progress workflow. Refine panel,
toolbar and recipient-list density; keep every control reachable on phones.
Do not modify actual certificate artwork, name coordinates or PDF rendering.

## Responsive and accessible behavior

Validate at 320, 390, 640, 768, 1024, 1280 and 1920px where relevant, including
intermediate Studio breakpoints, light/dark themes and long content.

Page containers must fit their viewport; wide datasets own their scrolling.
Tables keep meaningful headings and all important information. The current mobile
response table hides later answer columns: replace that rule with a readable
stacked representation in which every answer is reachable on the same screen.
Long answer previews retain disclosure/inspect access to the complete value.
Other dense tables may scroll within labeled keyboard-accessible regions, with
actions reachable and clear headers. Avoid global overflow clipping.

Keep 44px touch targets for primary mobile controls, 16px editable input text on
phones, sensible wrapping, bounded dialogs with their own scrolling, safe-area
spacing, focus trapping/restoration, keyboard operation, field/error associations
and reduced-motion preference. Preserve server validation and no-JS fallbacks.
Use semantic table/form/dialog elements and existing shared focus treatment.

## Administrator lock behavior

Add nullable administrator-lock metadata on each webinar, separate from its
existing optional webinar password and its public open/closed state. Existing
webinars default to unlocked. Guard the new fields from ordinary mass assignment.
An administrator can explicitly lock or unlock from the webinar list or overview
through the shared confirmation pattern. Show `Admin locked` and explain that
participant access and saved data are unaffected.

Use an administrator-only, authenticated, CSRF-protected, throttled mutation.
Retain the existing configurable recent-password middleware; do not re-enable the
owner-disabled password prompts. Acquire the webinar row lock, re-read state,
change only lock metadata and normal update time, and record the actor, operation
and state transition in the existing audit trail. Repeated requests for the
current state are harmless and do not duplicate state-transition audit records.
Administrators can change the lock without first obtaining a cross-owner webinar
grant; normal administrator access/audit rules still apply to opening content.

`WebinarPolicy::manage` and permission checks deny locked webinars to every
non-admin. The central management middleware first checks the user's existing
relationship, then returns a dedicated 423 response for an otherwise entitled
non-admin on a locked webinar. An unrelated user still receives 404. This runs
before password unlock, controllers, exports, downloads and file access. JSON
and asynchronous clients receive the same clear restricted-access message.

The message is: "Access to this webinar has been restricted by an administrator.
Contact your administrator if you need access." Provide a route back to available
webinars. An existing password grant, browser history, open tab, prior assignment
or direct URL cannot bypass the check; the next server request is rejected.
Already downloaded/displayed information cannot be remotely retracted.

Keep a minimal assigned/owned list entry so the person can understand the status:
title and lock label, without a workspace link, description, counts or actions.
Exclude locked content from non-admin dashboard aggregates, recent delivery
previews and cross-webinar selectors. Administrator lists retain full content and
controls. Unlocking restores only the user's existing permissions; it grants no
new membership or capability.

Previously requested non-admin certificate/email work must continue to recheck
the requesting actor through the existing policy at execution/finalization and
must not become a bypass. Reuse those existing guards and test them. Preserve
historical system work and administrator work under their current contracts.
Do not change participant-facing availability, public verification, retained
records, files, certificate status/history, assignments, settings or eligibility
as part of the lock operation. Normal existing processing may record its own
permission-denied outcome when revalidation rejects older non-admin work.

## Verification and release

Add meaningful regressions before implementing the lock: admin/non-admin roles,
owners and assigned managers, direct HTML/JSON/navigation requests, previously
unlocked sessions, scoped child routes and unscoped certificate routes, mutations,
downloads/exports, dashboard leakage, unlock restoration, audit/idempotence and
data preservation. Reuse isolated synthetic fixtures and log/fake transport.

Run `composer run test`, full Pint check, then production frontend build, followed
by JS and Chromium snapshot/real-HTTP workflows. Extend responsive checks for
populated long tables, complete mobile answers, drawers/dialogs, uploads, Studio
controls and the admin/manager lock flow. Review representative screenshots.
Rehearse the additive migration and upgrade/data preservation on disposable
PostgreSQL before touching the deployed database. Verify dependency audits,
configuration/route/view caches and publication privacy/secret exclusions.

The owner authorized deployment after implementation/testing. Deploy a reviewed,
exact commit to the existing Certimate Render service, preserving Supabase data,
private storage, APP_KEY, accounts, sender settings, domain and current policies.
Do not repeat the previous clean-launch deletion, copy local real data, send
application email, or change billing. Require a fresh verified encrypted backup
and matching restore/migration proof before the additive live migration. Confirm
the final hosted SHA, migration status, health, assets, both domains and lock
behavior using synthetic verification that does not send mail.

Open Chrome for the owner to review the result. Record changes and evidence in
the repository's project memory and release notes. Local Chromium evidence is
not physical-device/assistive-technology or inbox-delivery evidence.

The existing Render Free profile sleeps and does not meet continuous production
worker guarantees. Preserve its $0 restriction unless the owner authorizes a
hosting change. Report source/release readiness and strict production readiness
separately; do not claim that a UI update removes hosting, recovery, capacity or
live provider acceptance gates.

## Builder decisions and exclusions

The implementer chooses exact spacing/radii, responsive table variants, test
fixture details and whether small Blade partial extraction improves reuse.
Reuse existing primitives and avoid broad controller/service rewrites.
The expected migration adds only lock metadata; no backfill of business data is
required. There is no auto-unlock schedule, bulk lock, lock reason editor,
participant suspension, tenant model, new dashboard metric, email notification
or certificate change in this feature.
