> 2026-10-21 recipient simplification: eligible participants now appear in Studio
> automatically. The former approval controls below are historical; manual
> exceptions use Add Recipient Manually in Studio. See STUDIO_RECIPIENTS.md.

# Forms and certificate workflow

The 2026-10-21 adaptive CSV/XLSX extension is described in
[ADAPTIVE_RESPONSE_IMPORTS.md](ADAPTIVE_RESPONSE_IMPORTS.md), including the required
unapplied retirement migration and current verification evidence. It supersedes
the older CSV-only/table limitations in the historical notes below.

Certificate configuration is now **Certificate Setup**, with design and email configuration in one location. Eligibility controls are exclusively under Form Responses → Eligibility Settings. Manual template naming has been removed; the display name uses the current webinar title and certificate type, falling back to Certificate of Participation where no type metadata exists. Existing artwork is not read or guessed to infer a type, and existing template IDs and certificate files are stable.

Email Configuration uses the existing per-webinar subject/message columns; there is no new schema or backfill. It supports `{{participant_name}}`, `{{webinar_title}}`, `{{certificate_type}}`, and the older `{participant}`/`{webinar}` aliases. Blank fields use professional defaults. HTML is escaped and line breaks are retained. Draft preview resolves sample information without saving. The confirmed sample-email action uses saved settings, renders a sample PDF without issuing a certificate, and queues only to the signed-in administrator. Tests use the fake/log provider; no real email was sent. The existing outbox handles delivery outcomes and retry idempotency. Samples expire after 15 minutes and remain subject to archive/deletion/retention checks. Saving setup or approving participants never sends mail; Studio retains its explicit confirmation before generation/delivery.

The webinar sidebar now leads to **Forms**, **Form Responses**, and **Certificate Studio**. Existing reports, settings, templates and delivery logs remain available. The old participant and import URLs remain compatible; imports are reached contextually from Form Responses.

1. In Forms, switch between Registration, Pre-Test, Post-Test and Evaluation. Use the existing editor to save settings, open/close submissions, manage questions and copy links. Preview works for closed forms and cannot submit participant data. Question order has keyboard-operable up/down controls. A 1–5 rating reuses the existing single-choice field model.
2. In Form Responses, select the same form type. Native submissions appear automatically. Import Responses preselects that form; the existing CSV preview, column mapping, dry check and final confirmation remain mandatory. Map external answer columns to local questions to combine them with native answers. Unmapped original cells remain inspectable and exportable.
3. Use Responses for the paginated answer table, private name/email search, source/order/history filters, individual inspection and CSV export. Use Summary for choice counts, percentages, rating/checkbox bars, bounded text examples and submission trends. The summary refreshes every 30 seconds while visible.
4. Eligibility Settings edits the existing webinar rules; it does not change historical rules automatically. Required form completion and minimum points are separate. Registration continues to recognize the historical verified registration marker. Any current submitted qualifying attempt can satisfy a requirement; superseded responses cannot. Existing attendance requirements and audited manual overrides remain supported.
5. In Eligibility review, inspect per-form completion, minimum score results and missing requirements. Select eligible recipients on the current page, choose Approve for Certificates, review the count and confirm. Approval is password-gated, unique per participant and audited with the administrator and eligibility snapshot. It does not issue a certificate or send an email.
6. Studio lists approved, currently eligible participants and existing authorized certificate work. It preserves the existing artwork, name formatting, PDF generation and delivery pipeline. Issuance rechecks both authorization and eligibility. A rule change retains approvals and existing certificates and displays review warnings. Duplicate approvals and certificate inventory entries are prevented.

## Data preservation and boundaries

- The additive migration creates `certificate_approvals` and a nullable encrypted `submissions.imported_data` column. It does not rewrite historical responses, names, email keys, eligibility rules or certificate artifacts.
- New imports retain the original cells encrypted, plus declared answer mappings. Mapped answers also use existing encrypted submission answers so answered-field/question edit restrictions continue to apply. Existing privacy erasure removes imported cells and approval records.
- Historical committed imports did not retain successful answer cells. Those cells cannot be reconstructed from the existing completion/score record. The interface explicitly marks unavailable answers; existing eligibility remains unchanged. Keep the original exports for review. Do not reimport real exports without separate authorization.
- CSV and direct XLSX response sheets are supported, including Google Forms/Sheets exports. See `CSV_IMPORT_GUIDE.md` for file limits and schema preview. Checkbox values use comma-separated labels; a label containing a comma must be corrected in the source or retained as an unmapped original value.
- No guessed name merges or email alias normalization are introduced. Existing email-only matching, conflicting duplicate holds, explicit correction review and history preservation remain in force.
- Every administrator retains the existing full-system administrator permissions. Public forms expose no response tables or administrative actions.

## Verification and continuation

Run all PHP/Composer/npm commands from this Laravel directory. This checkout has no Composer `test` script; use `php artisan test`.

```powershell
php artisan test
php vendor/bin/pint --test
npm.cmd run test:js
npm.cmd run build
npm.cmd run test:e2e
npm.cmd run test:workflow
```

`test:e2e` uses synthetic rendered snapshots. `test:workflow` drives a real Chromium session against an isolated SQLite file named `storage/app/private/workflow-browser.sqlite` on port 8011. Its seeder refuses any other database path or a real email provider. It creates only synthetic people and uses log email. It never starts a queue worker. Its reset command is restricted to that exact disposable database. PHP feature tests use SQLite `:memory:` and the test mail configuration.

On 2026-10-20, before the owner's later instruction to make no data changes, a consistent local SQLite backup was created at `storage/app/private/backups/database-before-response-workflow-20261020-230138.sqlite`, and only the additive migration was applied locally. Hash comparisons of 16 existing tables and the eligible participant set were identical before and after: 217 participants, 408 submissions, 87 eligible, one existing certificate, zero jobs. The approval table remained empty. No real response was imported and no person was approved, issued or emailed during this work.

**Current instruction: make no further writes to the real database or real participant data.** Continue verification only with isolated synthetic data. Do not start workers, change real eligibility/approvals, reimport real files, restore the backup, or issue/send real certificates without new explicit authorization. No production deployment or PostgreSQL/provider acceptance is established by these local checks.

Final local verification: 329 PHP tests / 3,817 assertions passed; final focused rerun 32 tests / 379 assertions passed; Pint passed; 8 Vitest tests passed; production Vite build passed; 27 snapshot browser tests passed; isolated real-app Chromium workflow passed. Browser evidence includes desktop dark, mobile light/dark, tablet form management, confirmation cancel/Escape/focus return, and theme persistence. The static UI audit reported zero findings. Vite continues to report its existing large PDF.js worker/chunk advisory.

Certificate Setup verification on 2026-10-20: full suite 342 tests / 3,927 assertions; latest focused suite 57 tests / 420 assertions (including provider-specific sender preview); 8 JavaScript tests; 27 snapshot browser tests; both isolated real-app Chromium workflows; Pint, production build and static UI audit passed. No real database access, migration, real email, participant action or queue worker was performed for the Certificate Setup changes. Screenshot evidence uses synthetic sender/recipient configuration. Existing provider/inbox acceptance remains untested.
