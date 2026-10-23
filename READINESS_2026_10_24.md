# Certimate release readiness - 24 October 2026

This update follows the approved shared-component UI refinement and administrator webinar-lock design. Certimate retains its own Inter typography, blue logo/accent, artwork and workflows; Stash informed spacing and restraint. The owner explicitly accepts the existing Render Free sleep/wake behavior and authorizes deployment to that service. No paid hosting change is part of this release.

## Implemented behavior

- Shared light/dark surfaces, typography, navigation, controls, tables, dialogs and Studio panels use the existing components with quieter styling and consistent spacing. First-time navigation starts with labels visible; saved collapse preferences remain effective.
- Mobile response records expose every answer, including later imported columns, long-value disclosure and submission inspection. Existing horizontal scrolling remains available for wide desktop tables.
- The full Certimate logo reserves the same 180 by 50 frame in both themes; the dark icon and lettering have been resized to match the light lockup.
- Only administrators can lock/unlock individual webinars. All non-admin management, including regular-user ownership and assigned managers, is denied before password grants or content handlers. Public participant forms and existing records remain intact.
- Backend policy/middleware protects direct URLs, JSON requests, downloads, exports, selectors, dashboard content and actor revalidation in existing queued work. Unrelated accounts retain 404. Entitled restricted users receive a professional 423 explanation, including independent Studio refresh/status and form-availability requests.
- Lock changes are transactional, idempotent and audited. Only nullable `admin_locked_at` and `admin_locked_by` metadata is added; assignments, participants, responses, settings, certificates and stored artwork are preserved. Unlocking restores existing permissions.

## Source verification

PHP 8.3.36: 715 tests passed, one opt-in real-file verification skipped, 8,151 assertions. The full Pint check and Vite production build pass. All 20 JavaScript tests pass. Configuration, route and Blade caches compile in isolated paths. Composer/npm audits report no known vulnerabilities. The existing PDF.js large-chunk advisory is unchanged and does not fail the build.

All 33 snapshot Chromium cases pass. All 31 real-HTTP workflow cases have passing evidence: 30 passed in the complete run, then the existing adaptive-import case passed after its obsolete hidden-answer expectation was updated to require every later mobile answer. Browser coverage includes synthetic administrator/manager/owner sessions, direct requests, already-open restricted pages, saved navigation preference, equal-size theme branding, 220 management-page navigations and 84 populated list/Studio layout combinations across 320/390/640/768/1024/1280/1920-pixel layouts. These Chromium checks do not claim physical-device or assistive-technology certification.

The fresh PostgreSQL application restore, additive upgrade, rollback and reapplication passed: 36 tables, 44 final migrations, 107 original-table projection comparisons and 578 guarded Laravel queries. The exact upgrade write allowlist was exercised twice. The private schema was removed, hosted public business projections remained unchanged, and no application mail was sent. The publication scan covered 547 text files, found no known secrets/private paths/new private identities, and identified only an unchanged common-text name collision in historical SECURITY.md. Original local environment and database byte hashes remained intact before the explicitly backed-up additive release upgrade.

A fresh encrypted hosted database/private-file backup and disposable PostgreSQL restore/upgrade proof are required for this release. Promotion applies only `2026_10_24_000100_add_administrator_webinar_locks`, increasing the hosted migration ledger from 43 to 44. Existing values are compared before/after; the original administrator, encryption key, provider credentials, domains and owner-selected authentication policies are retained. The previous clean-launch deletion must never be repeated.

## Deployment profile and acceptance boundaries

The existing Render Free container runs the PHP app, certificate/email workers and scheduler while awake, with durable application data in Supabase PostgreSQL and private object storage. The service sleeps after inactivity and wakes on incoming requests; jobs and scheduled work cannot run while it sleeps. Uncommitted temporary import sources can require re-upload after a container restart. The owner accepts these hosting limits. See [Render Free documentation](https://render.com/docs/free).

The existing 300-email/day Brevo provider allowance is separate from server uptime; application safety budgets remain configured below that allowance. This release does not send application email or certificates, replay old queues, or establish actual inbox delivery. Real credential sign-in and live provider/inbox acceptance remain owner-operated checks. The hosted MFA/reconfirmation policy exceptions are preserved as explicitly requested in the prior release. The unchanged strict `security:check --production` profile still requires Redis, stronger authentication and monitored continuous operations; this accepted Free deployment is not certification against that stricter profile.

Publish only reviewed source, tests and documentation. Keep `.env*`, real databases, recovery archives, generated files, browser artifacts, `.claude/` and `storage/fonts/` out of Git. Verify the exact published SHA on Render, 44 hosted migrations, HTTPS `/up` and login on both existing domains, loaded assets and unchanged business/mail state before closing the release. Keep the service resumed.
