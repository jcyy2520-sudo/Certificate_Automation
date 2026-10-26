# Certimate upload, spreadsheet fidelity and mobile release

The owner requested independent investigation, fixes, clearer webinar management,
phone usability and a reviewed deployment. The owner also explicitly authorized
resetting hosted test records while retaining the administrator account. This
release uses the existing free services and requires no application migration.

## Confirmed critical failure

The deployed Nginx worker runs as `www-data`, but Alpine's inherited temporary
directory parents belong to `nginx`. Requests larger than the in-memory body
buffer failed before PHP. An unauthenticated synthetic probe isolated the layer:
1/4 KiB requests reached Laravel's 419 response; 16/64 KiB requests produced the
170-byte Nginx 500 page. The original 16,273-byte workbook was valid. A separate
private S3 `putFileAs` upload/download preserved synthetic XLSX bytes exactly.

Nginx now uses explicit private, worker-owned client-body and FastCGI temporary
directories. Image construction and startup provision them before ownership is
assigned. Both Git and image context exclude the temporary bytes. Upload limits,
queue windows, encryption keys and authentication policy are unchanged.

The pinned PHP 8.3.36/Alpine/Nginx container reproduced the original failure and
proved the correction. Requests from 1 KiB through 1 MiB arrived at PHP with exact
length and SHA-256. A slow 8 MiB FastCGI response was truncated by the original
configuration; the corrected configuration delivered the complete exact payload
and logged real disk buffering. Both temporary test containers were removed.

## Returning-user sign-in loop

Chrome reproduced `ERR_TOO_MANY_REDIRECTS` when an authenticated user opened
`/admin/login`, while the same session opened `/admin` successfully. Laravel's
default guest-middleware redirect selected the named public home route, which
redirected straight back to sign-in. The middleware now explicitly sends an
already-authenticated user to `admin.dashboard`. Its existing account, session
and applicable MFA checks still run. No credential or authentication setting
was changed. Independent review found no blocker in this narrow defect repair.

The regression first reproduced four failures, then passed all five cases with
29 assertions: returning administrator, user and manager, revoked-session
termination and anonymous home access.

## Spreadsheet fidelity

Confirmed regressions now cover phonetic annotations, effective cell/row/column
number formats, literal Excel errors, row/cell coordinates, shared-string bounds,
boolean lexical values, formula structures, calendar validity, precision, timezone
loss, unresolved named styles and quoted one-column headings. Unsupported or
ambiguous structures receive actionable refusal rather than a guessed value.
Ordinary formulas remain literal; cached results are not trusted. Email is still
the only automatic participant identity key. No eligibility rule changed.

The original four local workbooks passed 81,835 value/label comparisons and 3,702
eligibility comparisons with zero mismatches and all 612 rows accounted for.
Originals remain unchanged and were not uploaded to the hosted application.
Supported inputs remain bounded UTF-8 comma-separated CSV and safe XLSX. This
is finite evidence, not universal compatibility with every Excel feature.

## Workflow and phones

Overview now leads the workspace. A permission-aware guide uses the existing
Forms, Responses, Eligibility, Certificate setup and Send pages. The requirements
link opens Eligibility Settings rather than artwork. Creation clearly separates
response/certificate management from private file storage; an attachment does
not imply imported answers. Existing modes and records are not converted.

Phones have persistent labeled common navigation, the existing accessible More
drawer, safe-area spacing and room below content and feedback. All original
answers remain reachable. Studio retains its canvas and rendering behavior.
The responsive checks cover light/dark and narrow through wide viewports;
emulation does not establish physical-device or assistive-technology acceptance.

## Verification and release gates

- Full PHP suite after the redirect correction: 918 passed / 27,310 assertions,
  including opted-in original
  workbook verification in SQLite memory.
- Pint passed; production Vite build passed. The existing lazy PDF.js chunk-size
  advisory remains; the build did not fail.
- 26 Vitest tests and 15 deployment-template tests passed.
- 33 static Chromium scenarios passed.
- The full HTTP browser run passed 48 of 49 scenarios. The remaining test used a
  global selector that now matched both a drawer and a phone shortcut. The saved
  trace confirmed correct background `inert` behavior. After scoping the test to
  the drawer, the affected journey and all five new workflow/mobile scenarios
  passed in a focused six-scenario run. This is not one all-49-green run.
- The final production PHP 8.3.36 image passed 133 focused tests / 18,421 assertions,
  including original-workbook verification, without network access. Its test
  harness supplies runtime directories and an empty testing `.env`; production
  secrets and local databases are absent from the built image.
- Isolated config/route/view cache compilation passed. Composer validation,
  Composer audit and npm audit passed without known advisories.
- Fresh independent source review found four further accuracy blockers; each
  received an observed failing regression, a correction and an independent
  recheck. No Critical or Important source-review finding remains.

## Hosted recovery and authorized testing reset

The private release evidence includes a fresh encrypted row/schema/sequence,
runtime and object backup, verified database and object archive decryption, and
a matching disposable PostgreSQL restore/reset rehearsal. The rehearsal restores
38 tables and 32 owned sequence states, clears the intended 31 application
tables and preserves the complete administrator row and 46 migrations. It does
not reset sequences or change provider budgets/authentication preferences.

The reset helper checks source/deployment evidence, the exact backup, original
business projections and private object inventory before its transaction. It
deletes only explicitly backed-up object keys after commit, records partial
completion and refuses automatic reset replay. A separate independent review
accepted the guarded operation after missing/corrupt object-archive refusal was
added and tested. Never remove a completion marker to replay a committed reset.

Settled mobile visual checks are complete. Before release: scan the final
publication scope, commit only the reviewed files, verify the exact deployed source, verify
the actual large XLSX round-trip and reviewed synthetic response import, then
take a matching final recovery snapshot/rehearsal and apply the authorized reset.
Record the actual source SHA, deployment ID and outcomes in the outer workspace's
`RELEASE_COMPLETION_2026_10_26.md`. No real recipient send or issuance is required.
