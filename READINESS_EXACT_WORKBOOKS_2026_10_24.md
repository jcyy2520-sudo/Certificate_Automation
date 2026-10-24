# Exact workbook and adaptive table verification

The owner authorized read-only source verification of four private response
workbooks on 24 October 2026, with a strict no-silent-change requirement. This
follow-up builds on the uncommitted calm-workspace candidate based on
`00927a40cd6f0aa441d8f9ebbcb46589c69c3492`. It does not authorize or perform a
hosted import, certificate issuance, email, migration, publication or deployment.

## Source scope and exactness

The independent `OpenXmlResponseOracle` reads ZIP/XML without OpenSpout or the
application parser. The opt-in test compares its source values against decoded
CSV, pending previews, original encrypted records, displayed response values,
mapped and original detail views, held review, and complete parsed CSV exports.
It independently inventories expected answer columns and visits each accepted
source row exactly once through pagination and export.

The complete real-file check performs **74,710 value/label comparisons with zero
mismatches**, plus **3,702 independent PHP/SQL eligibility comparisons** with
zero mismatches. It traverses all 124 source-preview pages, covering every one
of the 612 source response rows.

| Source | Rows | Columns | Previewed rows | Isolated participant import outcome |
| --- | ---: | ---: | ---: | --- |
| Registration | 238 | 13 | 238 | 205 accepted; 33 held |
| Pre-test | 127 | 13 | 127 | 84 accepted; 43 held |
| Post-test | 123 | 13 | 123 | 76 accepted; 47 held |
| Anonymous evaluation | 124 | 6 | 124 | Previewed and decoded; no participant attribution |

Every held row retains its source evidence. Registration holds comprise 27
duplicates, five invalid emails and one identity conflict. Pre-test holds comprise
nine duplicates and 34 unmatched emails; post-test holds comprise 18 duplicates
and 29 unmatched emails. These are expected review outcomes, not missing rows.
No names or email addresses were guessed or corrected. Anonymous evaluations
cannot establish individual completion or certificate eligibility.

Text values and headers use strict equality, preserving case, Unicode combining
characters, punctuation, spaces and newlines. Matching identity is separately
trimmed/lowercased. Excel dates have a documented canonical ISO local-wall-time
representation; quiz score displays retain their denominator. CSV formula
protection deliberately prefixes formula-triggering cells with a quote, while
original-cell JSON retains the original source value. These explicit
representations are tested separately from text preservation.

## Defects found and corrected

1. The source preview shortened headers to 40 characters and values to 50. The
   extended real-file test recorded 92 mismatches before correction. Complete
   headings/values now wrap and preserve whitespace.
2. The preview stopped after five rows. It now has five-response pages through
   the complete source, including all anonymous evaluation rows. Viewing those
   rows creates no participant or submission. Page numbers are bounded and
   private values never enter pagination URLs.
3. Held review stopped at 200 records. A separate paginator now makes later
   issues reachable while preserving form and uploaded-file page context. A
   synthetic 205-row test follows the actual next links and checks every value
   exactly once.
4. An extra cell in a malformed row could overwrite a real header named
   `column N`. Collision-free overflow labels preserve every source cell,
   including collisions against existing fallback suffixes.
5. Unicode-only whitespace headings could create visually blank columns. They
   now fail validation; original nonblank labels remain unchanged.
6. OpenSpout trims ordinary `t="str"` values. The reader restores removed source
   padding, including all-whitespace strings, without changing its decoded
   literal core. It retains padding metadata only where padding exists.
7. ISO Excel date cells lost fractional seconds. They now retain them in the
   canonical text. Numeric Excel date formats displaying fractional seconds
   explicitly refuse import because the underlying reader rounds them before
   delivery; those digits are never silently discarded.
8. Default PHP CSV escaping could alter quote/backslash structure and corrupt
   the original-cell JSON column. Response exports now use explicit standard
   escaping. The synthetic regression changed from 11 parsed cells to the
   correct eight, with exact original values restored.

## Adaptation evidence and limits

The synthetic fidelity matrix includes XLSX profiles with 3, 13, 30, 80 and 200
columns, moved identity columns, custom/non-English labels requiring explicit
mapping, 1,450-character labels, long unbroken text, multiline and surrounding
whitespace, empty/zero/false values, rich strings, literal escapes, padded IDs,
large decimal tokens, percentages, scores, 1904 dates, reordered/evolving
schemas, native-question snapshots and ambiguous mappings. The profiles check
652 independently expected source cells and 632 adaptive answer cells through
storage/display/export. Additional regressions cover unsafe formulas, unsupported
formats, malformed rows and explicit 201-column/5,001-row rejection.

This establishes the tested inputs and supported contract, not universal support
for arbitrary Excel features. Response imports accept UTF-8 comma-delimited CSV
or `.xlsx` with one populated response sheet, up to 4 MiB uploaded, 5,000 response
rows, 200 columns, 300,000 cells, 32 KiB per text cell and 16 MiB decoded data.
Duplicate/blank headings, uncertain identity mappings, unsupported numeric formats
and unsafe content are refused or held for review. Multi-sheet private document
attachments use their separate byte-preserving upload contract.

Combined tables group normalized question labels. Original per-import labels
remain available in Inspect submission and original-cell JSON; a combined
canonical label is not a claim that every historical file spelled it identically.
Unknown columns remain text and do not become scores, ratings or identity fields
by their appearance alone.

## Privacy and browser method

Original workbooks are opened read-only. Imported test records use SQLite memory,
random fake private disks, null logging and transport fakes; pending real-value
test files are removed from the dedicated fake adapter at teardown. Original
workbook, configuration and real-database fingerprints are compared before/after.

`tests/accuracy/real-workbook-layout.mjs` launches the opt-in PHP test and receives
authenticated encrypted page data over its child-process pipe, using a random
ephemeral key. Decrypted HTML and expected cells remain in process memory. Its
fresh browser context blocks service workers/downloads and intercepts every
request, fulfilling only captured pages and allowlisted local build assets.
It writes aggregate results and error digests, without real screenshots, traces,
video, DOM snapshots, raw-data fixtures, external requests or actual app POSTs.
The context and browser close after the check. Synthetic screenshots are a
separate visual review layer.

The final browser capture covers 51 rendered pages: 31 source-preview pages
(including every anonymous evaluation page), all 17 accepted-response pages and
three held-review pages. Each runs at 320, 390, 768, 1280 and 3840 px in both
themes. This is rendered-page text/geometry evidence; the separate synthetic
real-HTTP workflow verifies interactive upload and pagination with the live
application code. It is not a hosted login or physical-device/AT acceptance test.

Independent final review found no remaining critical/important issues. Its pure
parser stress probe measured 52 MiB peak for 300,000 unpadded `t="str"` cells,
down from 218 MiB before avoiding unnecessary padding arrays. That measurement
excludes Laravel/framework overhead and is not a production capacity guarantee.

## Final verification record

Status: **local implementation and verification complete; source uncommitted and
hosted rollout not performed.** Final production source was frozen after the
independent review's precision and memory corrections, then verified in the
required PHP/Pint/build order before final browser checks.

| Check | Fresh final result |
| --- | --- |
| Full PHP suite, real-file opt-in enabled | 788 passed; 24,397 assertions; no skipped tests |
| Real-file value/label comparisons | 74,710; zero mismatches |
| Independent eligibility comparisons | 3,702; zero mismatches |
| Dedicated real-rendered-page Chromium check | 51 pages / 510 layouts; 74,200 exact text checks |
| Browser labels and disclosures | 36,500 label checks; 17,540 disclosure checks |
| Browser failures / clipping / document overflow / runtime errors | Zero |
| Focused synthetic real-HTTP browser workflows | 11 passed, including source-pagination navigation |
| Snapshot Chromium suite | 33 passed |
| Vitest | 20 passed across 5 files |
| Full Pint and production Vite build | Passed; build 22.80 seconds |
| Independent final source review | No remaining critical or important findings |
| Original four workbooks, `.env`, real SQLite database | All six byte hashes and sizes unchanged |
| Remaining files on real-accuracy fake disks | Zero |
| Source/public-preview/log private-identifier scan | 95 text files; zero matches |
| Git whitespace check | Passed |

The final source scan used 246 unique email strings and 226 source-name strings
read privately from the workbooks. These are scan needles, not a participant
count. Neither the needles nor real cell contents were written to the report.
The local browser test servers stopped after completion. No actual application
transport was performed. The existing lazy PDF.js chunk still emits Vite's
greater-than-500-kB advisory; the build succeeds.

Private evidence is in
`storage/framework/testing/exact-workbooks-20261024/`: `php-full-final.log`,
`realfiles-full-final.json`, `pint-final.log`, `build-final.log`, `js-final.log`,
`snapshot-final.log`, `workflow-final.log`, `browser-final.log`,
`browser-layout.json`, `protected-before.json`, `protected-after.json`, and
`private-output-scan.json`. Historical RED logs document the corrected failures;
the final results above supersede those failures. The synthetic source-preview
phone screenshot was visually reviewed; it contains only generated test data.

The source worktree remains uncommitted; previous unrelated `.claude/` and
generated `storage/fonts/` are preserved. No live mail, worker, source publication
or hosted change has been performed. Deployment follows the existing separate
release procedure and never repeats the historical clean-launch deletion.
