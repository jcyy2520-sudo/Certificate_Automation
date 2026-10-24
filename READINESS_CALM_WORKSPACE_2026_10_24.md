# Calm workspace, Excel attachments and table fit

Status on 24 October 2026: **local implementation and verification complete;
publication and live rollout pending authorization.**

This working-tree candidate is based on
`00927a40cd6f0aa441d8f9ebbcb46589c69c3492` on
`release/render-free-2026-10-22`. It has not been committed, pushed or deployed.
The previous UI/lock release and its deployment approvals are separate history.
Do not repeat the previous clean-launch cleanup.

## Delivered behavior

- Webinar Documents offers `.xlsx` first in both add and replace file pickers.
  Ordinary multi-sheet workbooks, formulas and chart/drawing parts retain their
  original bytes through private storage and download. Existing archive size and
  expansion limits remain. Active-content workbooks, unsafe packages and renamed
  non-workbooks are rejected. Legacy `.xls` files must be saved as `.xlsx`;
  `.xlsm` and `.xlsb` are not accepted attachments.
- Upload Forms Only creation redirects to the created webinar workspace with a
  ready empty state and optional Add a document disclosure. Creating the webinar
  never requires uploading first. Other creation modes retain their destinations.
- Shared app, webinar, sign-in, public, error and Studio presentation uses a calm
  light canvas, restrained surfaces and consistent controls. The design studies
  the owner's Stash workspace while retaining Certimate's identity and behavior.
- Data workspaces use the available width on large displays. The base interface
  grows from 18 px at 1920 px to 20 px at 2560 px and 22 px at 3200 px. Navigation
  dimensions scale with it. At 3840 px the tested responses workspace is wider
  than 3400 px and its table cell text is at least 17 px; all ten fixture columns
  fit inside the local table area without horizontal overflow.
- Table headings, names, email addresses, subjects, filenames and long values
  wrap within useful column widths. The eligibility identity column no longer
  inherits a checkbox-sized width. Long answers retain explicit full-answer and
  submission inspection controls. Wider source/comparison tables keep their own
  horizontal scroll area when needed rather than shrinking their text.
- On narrow phones, eligible record tables use labels derived from their actual
  headings. Participant, eligibility, import and email-log records retain their
  real selection controls, values and actions. This enhancement is opt-in and
  JavaScript-gated; the native table remains usable without the enhancement.
- Mobile form inputs use 16 px text and primary touch controls meet the checked
  44 px target size. Public questions have native fieldset/legend structure,
  accessible prompt names and connected help/error descriptions. Short desktop
  navigation scrolls independently. Tablet Studio uses drawers to leave room for
  its canvas, and Fit zoom recalculates when the available space changes.
- Participant certificate selection now updates the selected count, select-all
  and partial state, and Studio submission button. This repairs a missing client
  handler discovered during browser verification. Failed document replacement
  reopens the correct document's form with its attempted title and associated
  errors; unrelated document forms remain unaffected.
- A new charcoal document/check mark has an unchanged transparent generated
  master, optimized web assets and refreshed browser icons. The live text
  wordmark stays sharp at high display density. Graphic branding is concentrated
  in sign-in and primary navigation; ordinary workspace and Studio controls use
  navigation icons. The lossless WebP mark is 10,308 bytes. No new dependency was
  added.

## Verification

The production source passed the required PHP, Pint and build sequence before
the final browser runs. The later isolated browser-harness changes also passed
Pint and the complete real-HTTP suite.

| Check | Final result |
| --- | --- |
| `composer run test` | 745 passed, 1 skipped; 8,407 assertions |
| Full `php vendor/bin/pint --test` | Passed, including final harness rerun |
| `npm run build` | Passed in 20.20 seconds |
| `npm run test:js` | 20 passed across 5 files |
| `npm run test:e2e -- --workers=2` | 33 passed |
| `npm run test:workflow` | 39 passed in 8.1 minutes |
| Strict premium UI audit | 0 findings, errors or warnings |
| Composer dependency audit | 0 advisories or abandoned packages |
| npm dependency audit | 0 reported vulnerabilities |
| Independent final source review | No remaining critical or important findings |
| Git whitespace check | Passed |
| Original `.env` and real SQLite file | Both byte hashes and sizes unchanged |

The one skipped PHP case is the opt-in real-response-file accuracy test; this
run did not set `REAL_RESPONSE_FILES_DIRECTORY` or import the owner's exports.
Its historical results are not counted as a fresh pass here.

Browser coverage includes 22 management routes at five widths in both themes
(220 combinations), and six populated route groups at nine widths in both
themes (108 combinations). The populated widths are 320, 390, 640, 768, 1024,
1280, 1920, 2560 and 3840 px. Coverage includes drawers, keyboard focus, history,
offline recovery, full later answers, selections, actual XLSX upload/download,
replacement recovery, administrator restrictions and Studio resizing. Final
4K responses, phone records and phone sign-in screenshots were visually reviewed.

These are local Chromium and isolated SQLite results. They do not constitute a
fresh hosted login, PostgreSQL acceptance, physical-device or assistive-technology
test, provider transport, inbox receipt, or live deployment verification. The
existing lazy PDF.js chunk still produces Vite's greater-than-500-kB advisory;
the build succeeds and the PDF functionality remains intact.

## Isolation and retained evidence

Tests use in-memory SQLite or the dedicated workflow-browser SQLite file, fake
or log mail transport, and synthetic records. The real-HTTP harness now also
pins all private runtime storage, sessions, compiled views and local file disks
to `storage/framework/testing/workflow-runtime`. Its setup, server and seeder
refuse the normal application storage path or nonlocal test document disks.
Both temporary browser-test servers stopped at the end of their suites.

Private local evidence is under
`storage/framework/testing/calm-workspace-20261024/`:

- `php-full.log`, `pint-final.log`, `build-final.log`, `js-full.log`,
  `snapshot-full.log`, and `workflow-full-final.log` are the final suite results.
- `premium-audit-final.json`, `composer-audit.json` and `npm-audit.json` hold the
  final audit results.
- `protected-files-before.json` and `protected-files-after.json` confirm the
  original configuration and database were unchanged without exposing contents.
- `responses-4k.png` and `participants-phone.png` show synthetic populated views.
  The separate `certimate-login-390-light.png` in the testing directory shows
  the final phone sign-in and identity.
- Historical RED/intermediate logs document defects found before correction;
  final logs above supersede their failures.

The new identity master and provenance are in `resources/branding/`; its
derivation script is `scripts/build-brand.php`. The approved design and completed
implementation plan are in `docs/superpowers/`. `DESIGN.md` and `UX-CONTRACT.md`
describe the current shared owners and responsive behavior.

No application migration, real participant change, mail or certificate send,
worker/scheduler start, credential/provider change, publication or hosted action
was performed. Original unrelated `.claude/` and generated `storage/fonts/`
remain outside this candidate. Existing security policies, queue visibility
windows, certificate artwork and delivery rules remain in place.

## Release boundary

The candidate is ready for source publication and a separately authorized live
rollout under `DEPLOYMENT_CHECKLIST.md`, with the exact reviewed source and fresh
verified recovery assets. This candidate introduces no migration or dependency
lock change. Preserve current hosted records, the original administrator,
application key, private files, existing Free hosting choice and the prohibition
on agent application email sends. Verify the new exact release and public
responsive assets after rollout; do not replay any clean-launch deletion.
