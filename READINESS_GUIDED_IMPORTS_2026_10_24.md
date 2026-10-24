# Guided imports and final free-service release —24 October2026

The owner approved all five import/table improvements and subsequently authorized
an autonomous final audit, controlled account/email verification and deployment
using the existing free services. Existing records, encryption keys and owner
authentication preferences are preserved. This document records the candidate's
behavior and release procedure; private operation proofs record the actual SHA,
backup, migrations, deployment and controlled delivery outcomes.

## Implemented behavior

- Upload, Confirm columns, Review results and Import are distinct visible steps.
  Choices survive preview navigation/refresh after saving; a changed choice
  requires a fresh dry check. Final confirmation remains bound to the exact
  source, destination, mappings and options.
- Evaluations without identities can be saved explicitly as unattributed
  feedback, with complete tables, original-row inspection, literal summaries and
  exports. They create no participants/submissions and never establish individual
  completion or eligibility. Identical answers on different rows remain distinct.
- Private reviewed mapping profiles reuse only exactly identical ordered headers
  and uniquely compatible native questions. A changed/missing/reordered heading
  requires review. Profiles contain schema/preferences, with no respondent values,
  filename, source claim, review token or confirmation.
- Optional table controls provide heading search, question selection,
  Comfortable/Compact spacing, shown counts and reset. All columns start visible;
  source/identity/actions stay available. Very wide long-heading tables use fewer
  rows per page, with every row still reachable.
- Held review has explicit filters and an inspector showing original cells,
  interpretation and reason. Correction sheets preserve row/import references and
  exact original-cell JSON. CSV headings remain unique after protective quoting.
- The delivery log now labels expired unattempted activation mail accurately;
  the existing resend workflow creates a fresh link. Existing account priority,
  delivery retries, quotas, idempotency and queue visibility remain intact.
- The earlier calm/responsive redesign, larger high-resolution typography,
  selective professional branding, Excel document upload and upload-only creation
  destination changes are retained in this release.

## Source and private-workbook evidence

The broad PHP gate passed846tests/26,639assertions, followed by full Pint and a
successful production build. Vitest passed26tests, deployment-template tests12,
and static Chromium33. Configuration, route and Blade caches compile with isolated
artifacts. Composer strict validation and Composer/npm audits passed with no known
advisories. The existing lazy PDF.js large-chunk build advisory remains.

An independent ZIP/XML oracle compared81,835 original/decoded/stored/displayed/
exported values and labels and3,702 eligibility outcomes with zero mismatches.
Every one of the612 source rows is accounted for. Registration238:205ready/33held;
pre-test127:84ready/43held;post-test123:76ready/47held. The evaluation's124anonymous
rows are saved as feedback, with all124details/export records and all6literal
summaries checked; individual completion remains unchanged. No real source file
was modified and the isolated verifier sent no email or HTTP request and created
no certificate, approval, batch or queue job.

The private Chromium verifier checked67rendered pages across5widths and2themes:
670layouts,84,700exact-text checks,43,940label checks and17,540disclosure checks,
with zero mismatches, page overflow, clipping or runtime errors. Source cells and
HTML stayed in memory and were transferred through an authenticated encrypted
child pipe. No real screenshots, traces, videos, DOM snapshots or raw fixtures
were saved. Synthetic real-HTTP workflows separately exercise actual interaction,
including the two import stages, profile reuse, filters, table controls, keyboard
operation and browser zoom. Physical-device/assistive-technology certification
is a separate evidence layer.

Independent review found and corrected collaborator access to pending imports,
CSV heading collisions, missing feedback reconciliation outcomes, repeated-header
storage expansion and stale failure metadata overwriting a concurrent commit.
Retained regression cases reproduced these failures before correction.

## Measured bounds

Supported response inputs are UTF-8 comma-separated CSV or a safe `.xlsx` workbook
with one populated response sheet, at most4MB/5,000responses/200columns/300,000cells,
32KB per cell and bounded decoded/archive data. Unsafe or unsupported workbook
features and ambiguous/lossy formats receive an explicit refusal or review; unknown
numeric text is never silently treated as a score or rating. This is not a claim
of universal compatibility with every Excel feature or arbitrary malformed file.

Two synthetic300,000-cell profiles with2,000-character headers passed. After the
storage fix,1499×200 persisted about3.3MB of row ciphertext and4999×60 about4.0MB;
headers were retained once. Adaptive pagination reduced initial table HTML from
12.0MB to1.41MB and from3.64MB to0.59MB. Those first pages rendered in about0.3s
locally, with actual next-page row coordinates verified. Complete original-cell
JSON exports can still be large: the adversarial long-heading profiles streamed
about612MB each with bounded memory. These are local PHP/SQLite measurements,
not a Render concurrency or PostgreSQL capacity guarantee.

## Required release operations

Publish only the reviewed code/design/tests/docs/branding candidate, excluding
`.env*`, real databases, private recovery/source files, browser/test outputs,
`.claude/` and generated `storage/fonts/`. Select one exact SHA after the source
gates; private helpers verify that clean source and the two migration hashes.

Before a live write, create and decrypt-verify an encrypted consistent application
database/schema/runtime snapshot and all private S3 objects. Restore into a
random guarded PostgreSQL schema, preserve original row/schema/sequence state,
verify every serial default resolves to the private sequence, apply the two new
tables, roll back only those tables and reapply. Never run the historical cleanup.
After public additive apply and pinned Render deployment, check46migrations,
original data/key/object hashes, both HTTPS hosts, assets and account pages.
Local schema installation uses a separate verified SQLite backup/rehearsal and
preserves the existing local business records and private files.

Controlled hosted verification uses only a uniquely tagged regular test account
and the approved sender's own plus-address mailbox. It checks setup/login/recovery,
general transport and an optional synthetic sample-certificate workspace without
creating participants or issuing real certificates. It suspends only that test
account and archives only its test workspace. Provider acceptance, mail-server
delivery and inbox receipt must be reported separately; an uncertain send is
reconciled rather than blindly resent.

## Free-service acceptance

The existing Render Free service sleeps after inactivity and can restart; combined
workers/scheduler stop with it and temporary pending uploads may need re-uploading.
Committed records and private objects use Supabase PostgreSQL/S3 storage. Brevo
Free provides300daily sends; the actual application retains its conservative
250/day/30-per-minute budget and account-first queue. No payment, plan upgrade or
free-service restriction bypass is part of this release.

These constraints prevent an honest unlimited, always-on or100%inbox-delivery
promise. The unchanged strict `security:check --production` profile still has
requirements beyond the owner-selected Free configuration, including continuous
operations and its stronger authentication/Redis profile. A successful backed-up
Free deployment is reported at that evidence layer, without weakening the strict
check or treating SQLite/browser tests as live-provider/host certification.
