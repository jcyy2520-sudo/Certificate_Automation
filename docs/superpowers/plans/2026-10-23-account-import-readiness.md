# Account and import readiness implementation plan

**Goal:** Reproduce the missing account activation notification, preserve exact uploaded response data, and verify critical workflows before October 24.

**Architecture:** Retain the existing account outbox, email priority queues, transactional import services, encrypted original cells, and eligibility rules. Apply small fixes only to demonstrated defects; keep production mutations and real delivery outside isolated verification.

**Spec:** Owner request in this session: all four supplied workbooks, every practical record, no personal information in reports/logs, no original-file changes, preserve existing data and working certificate flows.

## Constraints and review focus

- Preserve all pre-existing dirty changes, credentials, real records and certificate files.
- Use in-memory/disposable databases, private fake storage and fake/log transports.
- Never match anonymous evaluations or names to identities by row order.
- Compare independent OpenXML source values with reader output, committed submissions, held rows and rendered values.
- Keep original headers, whitespace, identity spelling, formatted scores and timestamps available; matching and analytics may use explicit derived values.
- Verify account token expiry/replay, delivery outcomes, worker priority and meaningful administrator feedback.
- Keep local test evidence separate from hosted/provider/inbox and production approval.

## Work items

- [x] Trace account approval and activation; reproduce concrete defects with synthetic regressions; fix and run focused account tests.
- [x] Read all four files independently and compare every cell/row in isolated imports; reject unsafe identity mapping.
- [x] Reproduce XLSX score-denominator/identifier formatting and source display defects; fix reader, mapping and response rendering with regressions.
- [x] Inspect hosted configuration, queue/schema/health evidence read-only and identify release blockers.
- [x] Run PHP full suite, Pint and production build in order, then JS/browser workflows and isolated PostgreSQL checks where available.
- [x] Record aggregate accuracy results, remaining limitations and the source/operational release decision in a reviewable report and PROJECT_MEMORY.md.
