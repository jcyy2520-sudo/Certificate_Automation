# Calm responsive workspace implementation plan

> Execute the authorized work in this session, with independent investigation of
> the document backend and creation redirect. Keep each agent's file ownership
> separate and review the combined result before completion.

**Goal:** Accept Excel attachments and make every Certimate workflow usable in a
calm responsive interface without removing existing behavior.

**Architecture:** Laravel/Blade shared layouts and CSS own appearance;
page-usability.js owns repeatable table labels and accessibility enhancements.
Existing private document controller owns attachment validation/storage.

**Spec:** ../specs/2026-10-24-calm-responsive-workspace-design.md

## Global constraints and review focus

Preserve real data, .env/key, private files, frozen unrelated security code,
queue windows and provider settings. No migration, mail, publication or deployment
is part of this implementation. Ensure mobile records retain selection/actions;
multi-sheet Excel attachments preserve bytes; creation needs no upload; long
text remains readable; short viewports and 4K do not hide controls.

## Tasks

- [x] Reproduce document exclusion in a dedicated feature test; implement XLSX
  allowlist/package support in WebinarDocumentController; verify private download,
  replacement, multi-sheet preservation and active-content rejection.
- [x] Pin uploads-only creation destination in WebinarCreationDestinationTest;
  redirect to admin.webinars.show and show a complete workspace before uploading.
- [x] Add meaningful real-HTTP browser regressions for mobile participant and
  email records, public input geometry, optional upload and 4K layout. Observe
  the existing gaps before changing the shared primitives.
- [x] Extend app/webinar/auth/public layout rhythm, shared controls and navigation
  in resources/css/app.css and shared Blade owners; migrate record-based tables
  through one explicit responsive table class and shared label enhancement.
- [x] Redesign documents workspace with optional upload disclosure, Excel-first
  help, accessible file feedback and intact replacement/download controls.
- [x] Update DESIGN.md/UX-CONTRACT.md together with runtime ownership; review
  screenshots and independent audit findings across all route groups.
- [x] Run composer run test, full Pint check, npm run build, then JS, snapshot and
  real-HTTP suites; strict UI audit and diff/privacy checks. Verify protected-file
  hashes and record exact results in the project handoff. Review source changes
  independently; report local evidence separately from live rollout.

Final results and evidence boundaries are recorded in
`../../../READINESS_CALM_WORKSPACE_2026_10_24.md`. All implementation tasks passed
local verification; publication and live rollout remain separate operations.
