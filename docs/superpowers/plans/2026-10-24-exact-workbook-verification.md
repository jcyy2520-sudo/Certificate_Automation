# Exact workbook verification implementation plan

> Execute the user-authorized verification in this session. Independent synthetic
> import tests and held-review pagination have separate file ownership. Review
> the combined changes and run final checks before reporting completion.

**Goal:** Prove source-to-preview/storage/table/detail/export fidelity for the
four opted-in workbooks and exercise adaptive layouts without retaining or
publishing real-person fixtures.

**Architecture:** An independent ZIP/XML oracle supplies source values. Existing
in-memory Laravel tests exercise the production import and rendering paths.
A dedicated Playwright process receives encrypted page data over its child
process pipe, keeps decrypted HTML in memory, intercepts every request, and
emits aggregate geometry/equality results only.

**Scope:** The user's 24 October exactness requirement and existing
`ResponseSourceFidelityTest`, `RealResponseFileAccuracyTest` and adaptive import
contracts. No universal claim about unsupported Excel layouts is permitted.

## Constraints

- Preserve original workbooks, `.env`, real databases and private application
  files. Fingerprint originals and protected files before and after.
- Use only isolated memory/test storage, fake/log mail and blocked HTTP.
- Never publish real values, names, emails, screenshots, traces, HTML snapshots
  or raw expected/actual assertion diagnostics. Reports contain counts and
  source coordinates/digests only.
- Anonymous evaluation cannot be attributed to participants or establish their
  eligibility. Test every decoded row and all previewed cells; state the
  participant-import boundary explicitly.
- Preserve normal queue/security/provider behavior. Fix only discovered defects.
- No commit, push, deployment, application mail, real migration or worker start.

## Tasks

- [x] Verify file availability and record fingerprints; rerun the existing opt-in
  four-workbook test as a baseline.
- [x] Extend the real-file test with full preview text, an independent source
  column inventory, unique paginated source-row coverage, mapped detail values,
  exact parsed CSV exports and evaluation preview/analysis coverage.
- [x] Reproduce and repair preview truncation/whitespace, malformed-row retained
  cell collisions and Unicode-only blank headings with regression tests.
- [x] Add a synthetic matrix for reordered/custom schemas, ambiguity, Unicode,
  whitespace, sparse cells, zero/false, exact numeric displays and source safety.
- [x] Make held rows beyond the old 200-row cap reachable by pagination and prove
  each synthetic row remains inspectable once.
- [x] Verify actual real-file rendered tables in a privacy-preserving browser
  process at phone/tablet/desktop/4K widths and both themes. Check complete text,
  disclosures, labels, wrapping and local scrolling without real screenshots.
- [x] Run focused/full relevant tests, required PHP/Pint/build sequence, targeted
  browser regressions, final source review and protected-file comparisons.
- [x] Record precise results, supported boundaries and remaining gaps in a
  source-safe report and the project handoff.

Final result: `../../../READINESS_EXACT_WORKBOOKS_2026_10_24.md`. All 612 source
rows were previewed, 74,710 value/label comparisons and 3,702 eligibility checks
had zero mismatches; full PHP 788/24,397, Pint/build, 20 JS, 33 snapshots, 11
real-HTTP browser cases and 510 private rendered layouts passed. Original six
protected files remain unchanged. No live operation was performed.
