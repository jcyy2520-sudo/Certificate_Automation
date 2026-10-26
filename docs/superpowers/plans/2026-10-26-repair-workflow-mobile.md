# Certimate repair, workflow and mobile implementation plan

> **For agentic workers:** Use systematic debugging and test-driven development for confirmed defects; use fresh review before release. The owner explicitly delegates decisions and authorizes fixes and deployment.

**Goal:** Repair the hosted Excel upload, clarify webinar management, improve phone navigation, prove supported spreadsheet fidelity and redeploy safely.

**Architecture:** Retain Laravel controllers/services and shared Blade layouts. Use existing authorization and transactional import/review mechanisms. Reuse the current free host and durable PostgreSQL/S3 storage.

**Tech stack:** Laravel 12, PHP 8.3, Blade, Tailwind 4/Vite, PHPUnit, Vitest and Playwright.

**Spec:** `docs/superpowers/specs/2026-10-26-repair-workflow-mobile-design.md`.

## Constraints and review focus

- Preserve credentials, administrator account, email-only matching, encrypted original cells, fixed form types, certificate rendering and queue visibility windows.
- Real workbooks stay local; original values do not enter public fixtures or logs.
- App permissions must govern every added navigation item, including assigned managers.
- Upload-only mode must not promise response import/certificates.
- Phone navigation must survive existing partial page navigation and not cover controls or tables.
- Unsupported workbook values must be refused visibly; source column order, row identity and original whitespace must survive.
- A failed storage operation must produce useful feedback and not leave an apparently successful attachment.

## Tasks

- [x] Identify hosted upload failure. Compare a synthetic request and the actual workbook's format in isolation, current logs, database privileges and the exact S3 storage call. Capture an observed regression, fix only the confirmed cause and verify add/replacement/download/error handling.
- [x] Clarify workflow in overview, desktop/mobile navigation and creation-mode copy. Reuse shared UI; link to actual requirement settings. Verify administrator and assigned-manager destinations, document mode, and no fake completion claims.
- [ ] Add phone-friendly common navigation and compact safe-area layout using existing interactions. Verify 320/390/768px in light/dark, keyboard menus, complete response content, forms and Studio.
- [x] Independently audit XLSX/CSV value and schema fidelity. Add adversarial synthetic regressions for confirmed gaps and rerun the opt-in original-workbook oracle in SQLite memory with aggregate-only evidence.
- [ ] Verify fresh full PHP -> Pint -> build, JS, static/real browser workflows, required caches, deployment templates and independent diff review. Update project handoff and readiness evidence.
- [ ] Back up hosted data/private objects/runtime and verify recoverability. Rehearse the scoped test-data reset, preserve the administrator byte hash, keep migrations/key/queue configuration intact. Publish only the reviewed source and build from the pinned commit.
- [ ] Deploy to the existing free Render service, verify health/assets/auth boundaries, synthetic attachment round-trip and reviewed response import, perform the authorized scoped reset, and verify an empty testing workspace plus unchanged administrator. Record exact source/deploy IDs and remaining evidence limits.
