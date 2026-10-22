# Adaptive response imports

Implemented locally on 2026-10-21. This extends the existing reviewed imports,
ownership middleware, encrypted answers, eligibility and certificate approvals.
It does not migrate the owner's actual database or import real participant files.

## Workflow

1. Select a form in Form Responses and choose Import Responses.
2. Upload a UTF-8 comma-separated CSV or an XLSX response sheet.
3. Review the detected format, row/column counts, source column order, blank
   counts, conservative value types, samples and mapping status. Unique exact
   native question labels are suggested. Similar or ambiguous labels need review.
4. Confirm identity/answer mappings, date order/timezone, source/destination and
   external required answer columns. Unknown question types remain text. Numeric
   answers never automatically become quiz scores or ratings.
5. Run the transactional dry check, inspect row errors and interpreted values,
   then confirm the same choices to import. Changed choices require a new check.
6. Valid rows become linked submissions. Invalid/unmatched/duplicate rows retain
   review evidence. Existing responses require the established explicit correction
   action to be superseded. Importing never approves or issues certificates.

Matching remains normalized email only. Similar names never merge identities.
Registration needs a name. Assessments/evaluations need declared completion
evidence or a valid mapped quiz score as applicable. Configured score requirements
stay authoritative. The preview flags native questions absent from an external
questionnaire; the organizer declares that external source's required answers.

## Architecture and preservation

- `ResponseFileReader` checks archive structure and size before decoding XLSX
  with locked OpenSpout 4.32.0. XML entities, traversal, macros, embedded objects
  and external workbook links are rejected. Formulas remain literal text; cached
  results are never used. Excel dates become local wall-clock text interpreted
  with the declared export timezone. Empty rows preserve source error numbers.
- XLSX rows stream into a bounded temporary stream and produce the canonical
  private CSV used by the existing importer. Original file hashes support exact
  file checks; a separate normalized hash guards pending-file integrity. Existing
  cancellation, pruning and erasure still remove the private pending file.
- `ImportedResponseSchema` stores an import-owned schema in `imports.column_map`.
  No native questions or physical answer columns are created. Extra fields use
  stable keys based on normalized exact header labels within their destination
  form. Their semantics remain unconfirmed until mapped to a known native field.
- Encrypted `submissions.imported_data` retains original cells, mapped values and
  import-time definitions. Mapped native answers also use existing encrypted
  submission answers, preserving analytics and answered-question edit guards.
  Choice validation and storage share one parser. Surrounding choice whitespace
  is normalized without changing original cells. Commas inside checkbox labels
  are accepted only when the declared options give a unique interpretation;
  ambiguous selections are held rather than assigned misleading chart counts.
  Held rows retain their committed schema snapshot. Resolution rejects changed
  question types/options and retired questions, requiring a fresh reviewed map;
  wording-only edits preserve the original import-time label.
- `FormResponseService` merges current/retired native questions and committed
  import schemas. Lists paginate; summaries stream in chunks. Configured choice
  and checkbox fields get distributions, known number fields get aggregates, and
  mapped quiz scores get score summaries. Unknown types show bounded text samples.
  Original cells stay inspectable/exportable. CSV headers and answers are both
  sanitized against spreadsheet formula injection.
- Desktop/tablet tables scroll horizontally with sticky participant identifiers.
  Phones show participant/state information and two answer previews, with Inspect
  submission exposing all fields. Long answers use a three-line preview and native
  details control. Existing session-based search, filters, ordering and pagination
  remain. No new theme tokens or frontend framework were introduced.

## Required migration and historical limits

`2026_10_21_000200_preserve_retired_response_questions.php` adds nullable
`deleted_at` columns to `form_fields` and `questions`. Removing a field/question
retires it from the live form while preserving its answer rows and quiz choices.
Retired definitions remain available in history and are excluded from new
native submissions and new import-mapping choices.

New native submissions snapshot their question definitions in metadata. Inspect
submission retains the wording/choices used at submission time. Previously
changed wording and previously deleted answers cannot be reconstructed. Older
completion/score-only imports remain explicitly marked as missing original
answers; no values are invented.

**The new source requires this migration; it has not been applied to the owner's
actual database.** Use the approved backup and PostgreSQL rehearsal procedure in
`DEPLOYMENT_CHECKLIST.md` before release. Rollback drops retirement markers and
can make retired questions visible again; rehearse rollback behavior too.

## Limits

- Upload: 4 MB; 5,000 response rows; 200 columns; 300,000 cells; 32 KB per cell.
- XLSX: one populated response sheet; at most 1,000 archive entries, 32 MB expanded,
  and 16 MB decoded response text. Blank/duplicate headers require correction.
- Existing bounded synchronous transaction, dry check and busy submit feedback;
  no new worker/infrastructure. Install locked dependencies and PHP XML/DOM,
  XMLReader and ZIP extensions. No formula engine is installed.

## Local verification

- Full `composer run test -- --compact`: **444 tests / 4,984 assertions** on SQLite test DBs.
- Twenty-four focused adaptive tests include changing/reordered schemas, mixed sources,
  a 1,000-row import, choices/checkboxes/numbers, date/score errors, duplicate files,
  archive/XML protections, formula injection, XSS, rollback, privacy erasure and
  unrelated webinar/form preservation. The final four cover normalized choice
  storage/quiz links, comma-containing and ambiguous checkbox selections, blank
  worksheets around a response sheet, and changed definitions during held review.
- Synthetic migration rollback/upgrade preserved snapshots of six existing tables;
  retiring the historical quiz retained its answer and choice label.
- Pint, Composer validation, `git diff --check`, eight Vitest tests and Vite build
  passed. The existing PDF.js bundle-size advisory remains.
- 31 Chromium snapshot tests and eight isolated real-HTTP Chromium workflows passed.
  Adaptive coverage includes 30 columns, full answers, search/no-results, desktop
  light, tablet dark, 390px/320px phones and no document overflow. Synthetic
  screenshots are in `storage/framework/testing/adaptive-*`. The mixed-source
  workflow checks that imported choices with surrounding whitespace contribute
  correctly to each of the four forms' charts, then completes eligibility review
  and Certificate Studio approval. Final check details are recorded in
  `storage/framework/testing/adaptive-review-verification-2026-10-21.json`.

Current `composer audit --locked` and `npm audit --omit=dev` report no advisories.
The earlier dependency advisories were resolved by the separately recorded
cleanup; this follow-up added no dependencies. Production PostgreSQL/Redis/HTTPS/
provider, physical-device and assistive-technology acceptance remain separate
release follow-ups. No actual dataset read/write/migration, real import, live
email, queue worker, commit, push or deployment occurred for this change.
