# Guided Adaptive Imports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Implement the five accepted import/table improvements while preserving exact source evidence and an easy, consistent workflow.

**Architecture:** Extend the current import service with an explicit unattributed evaluation mode backed by separate encrypted records. Keep the existing individual-response path and use server-persisted pending choices, exact-schema mapping profiles, existing Blade components and a small lifecycle-managed table controller.

**Tech Stack:** Laravel 12, PHP 8.3+, Blade, Tailwind, small JavaScript modules, SQLite-isolated PHPUnit, Vitest and Playwright.

**Spec:** `docs/superpowers/specs/2026-10-24-guided-adaptive-imports-design.md`

## Global Constraints

- Keep the existing Blade/Laravel architecture, visual language, email-only participant matching, preview, dry-check binding and explicit final confirmation.
- Only additive migrations are authored. Do not migrate the real local database or hosted environment.
- Do not start mail/issuance workers, commit, push or deploy.
- No real values in repository fixtures, screenshots, logs or external services.
- Individual responses remain the default.

## Review Focus

- Identical anonymous answers must remain distinct source rows; an anonymous import must never confer eligibility.
- Reordered, whitespace-changed or missing profile headers must not reuse positional mappings.
- Preview pagination and back navigation must retain saved choices and full source text.
- Hiding columns must retain correct table/card labels, actions and reset behaviour across page navigation.
- Filtered correction downloads must contain every scoped unresolved row exactly once, standard CSV escaping, safe display cells and exact original JSON.

### Task 1: Unattributed evaluations

**Files:** new `UnattributedResponse` model/migration, service/controller/views/tests; `CsvImportService.php`; targeted webinar-wide retention integration.

**Interfaces:** `CsvImportService::responseMode(Import $import, mixed $value): string` validates `individual|unattributed` (unattributed only for evaluation). `validateMap(Import $import, array $chosen, string $responseMode = 'individual'): array` retains the existing default. `process()` consumes `options.response_mode`. New pages use route names `admin.unattributed.index`, `.show`, `.export`; the parent adds routes and navigation.

- [x] Write and run failing synthetic tests for distinct rows, full exact cells, no attribution/eligibility, rollback/retry, invalid structure, retention and access scope.
- [x] Implement encrypted independent rows, bounded queries/literal summaries/export and lifetime integration.
- [x] Run focused tests and review the resulting interfaces before integration.

### Task 2: Reviewed mapping profiles

**Files:** new `ImportMappingProfile` model/migration/service/controller and focused tests; new profile Blade partial. Parent owns route and map-page integration.

**Interfaces:** `ImportMappingProfileService::matching(Import $import, User $user)` returns own same-type profiles; `save(Import $import, User $user, string $name)` saves only a previously dry-checked, current valid mapping; `apply(Import $import, User $user, ImportMappingProfile $profile)` applies only an exact ordered-header match, revalidates current form compatibility and clears review state. Controller route names `admin.webinars.imports.profiles.store`, `.apply`, `.destroy`.

- [x] Write/run failing tests for exact match, reordered/changed headers, ownership, incompatible native question mappings and schema-only storage.
- [x] Implement explicit save/apply/remove with actionable schema differences and no copied source descriptions/confirmation.
- [x] Run focused tests; return integration variables and form routes to the parent.

### Task 3: Guided flow and held-row review

**Files:** `ImportController.php`, map/index/create Blade views, `ImportReviewService.php`, `routes/web.php`; `GuidedImportWorkflowTest.php`, `ImportHeldReviewTest.php`.

**Interfaces:** POST `imports/{import}/choices` persists validated column choices and clears stale review; GET map `step=columns|review` renders the corresponding step. Review results are durable metadata limited to counts/row references/reasons, not respondent samples. Held review consumes `issue_group=duplicates|unmatched|invalid|identity`; correction export streams source rows with original-cell JSON.

- [x] Write/run failing tests for saved choices, navigation, stale-review rejection, filtered counts and scoped full correction CSV.
- [x] Implement the two distinct screens with existing final commit routes and secondary Back/Edit actions.
- [x] Add profile/mode integrations and clear final destinations; run targeted existing import regressions.

### Task 4: Optional adaptive table controls

**Files:** new `components/response-table-controls.blade.php`, `resources/js/response-table-controls.js`, `tests/js/response-table-controls.test.js`; response views, `app.js`, `app.css`.

**Interfaces:** controls operate only within `[data-response-workspace]`; question headers/cells use identical `data-question-column` keys. Initial state is all visible/comfortable, search filters the chooser only, reset restores the default. No browser persistence or mutation of response content.

- [x] Write/run failing JS tests for state, search, last/first column toggles and reset.
- [x] Implement accessible chooser, visible counts, density and lifecycle disposal, including mobile card labels.
- [x] Run JS and synthetic browser workflows at narrow/tablet/large widths plus keyboard/zoom.

### Task 5: Integrated exactness, performance and review

**Files:** extend private real-file test/capture inventory, synthetic adaptive/workflow tests, readiness/UX docs and root `PROJECT_MEMORY.md`.

- [x] Extend the anonymous real-file branch to save/review/summarize/export all 124 rows while proving no individual completion or side effects.
- [x] Verify all four originals and protected local files remain byte-identical; scan candidate source/evidence for real values.
- [x] Measure a near-limit synthetic file and table; report measured limits honestly.
- [x] Run full PHP tests, full Pint, frontend build in that order, then JS/static and focused real-HTTP browser tests.
- [x] Obtain an independent read-only review, resolve material issues with tests and record remaining evidence boundaries.
- [x] Update readiness and project memory. Later owner authorization supersedes the earlier publication hold; complete the backed-up free-service rollout under DEPLOYMENT_CHECKLIST.md.
