# Google Forms imports and certificate accuracy

Supported inputs are UTF-8 comma-separated CSV and Excel `.xlsx` workbooks with
one populated response sheet, including Google Forms/Sheets exports. Limits are
4 MB uploaded, 5,000 response rows, 200 columns, 300,000 cells and 32 KB per cell.
XLSX archives are limited to 32 MB expanded and 16 MB decoded response text.
Export the response sheet, not the Google Form itself. Google Form URLs,
screenshots, PDFs and summary-only files cannot prove certificate completion.
Keep the original export: the private temporary copy is deleted after import.

For the active public registration form shown to the project owner, use the
exact external-form checklist in [GOOGLE_FORMS_REGISTRATION_BLUEPRINT.md](GOOGLE_FORMS_REGISTRATION_BLUEPRINT.md). The application can safely import the resulting export, but it cannot change or authenticate a Google Form without access to the Google account that owns it.

## Organizer workflow

1. Create the webinar and review its certificate requirements. Minimum scores
   are **points**, not percentages. Confirm the title, date, wording, and
   signatures in the certificate artwork.
2. Import registration first, then pretest, posttest, and evaluation. Choose
   the correct webinar and destination form for every upload. Identify the
   Google Form source by title and export date/version, then explicitly confirm
   the destination. A Google Forms CSV has no cryptographically trusted form
   identity, so the system cannot prove its origin from the file alone.
3. Confirm the column mappings. Use one full-name column, or first name,
   optional middle name, last name, and optional suffix. Multiple plausible
   email columns require an explicit choice. Names retain their spelling and
   capitalization. The preview reports format, row/column counts, value types,
   empty cells, sample values and mapping status. Unique exact question labels
   are suggested; similar or duplicate labels require a decision. Additional
   fields appear automatically in Responses without editing the native form.
4. Select the source timestamp date order and timezone. Missing timestamps are
   held unless you explicitly allow the import time; the reconciliation report
   identifies that substitution.
5. For an evaluation, select one or more actual required-answer columns. Blank
   answers are held. A pretest or posttest may use a validated mapped quiz score
   as completion evidence; otherwise it also needs one or more answer columns.
   **Summary-only exports cannot establish certificate completion.** Re-export
   the complete Google Forms response sheet instead.
6. Choose the score format. Points accept `8`, `8.50`, and `8/10`. Percentage
   mode needs a positive maximum from the CSV or the declared maximum field.
   For example, 80% of 10 becomes 8 points. Unsupported precision and
   conversions requiring rounding are held for review. A mapped score cell
   cannot be blank.
7. Run the dry check. Inspect the interpreted name, score, date, totals, and
   held-row reasons. A dry check creates no participant or submission. Changing
   mappings or options requires another dry check.
8. Confirm the destination and dry-check results, then import. The server runs
   validation again. Valid rows are imported and unsafe rows are held. Download
   the reconciliation CSV and account for every source row.
9. Check the recipient roster and actual Preview PDF before bulk issuance. Use
   a short name, a long name, an accented name, and a suffix in the rehearsal.

## Adaptive tables and summaries

Response columns combine current and retired native questions with fields from
committed imports for that destination form. Column order in the file does not
affect identity matching. Unmapped columns retain their original labels and
values; separate structures in later imports add fields without rewriting old
responses. Native questions keep their configured types. Unknown imported
questions stay text, even when their values look numeric. Map ratings/choices
to an existing matching question to combine their analytics with native answers.
Only explicitly mapped quiz scores participate in score requirements.

Mapped choices use the same interpreted value for validation, storage and charts;
surrounding whitespace is removed from choices while the original cell stays
available in Inspect submission and exports. Checkbox selections are matched
against the declared choices, including labels containing commas. If a value can
mean either one combined label or several selections, the row is held with a
specific error. Leave that column unmapped to retain its exact text for review
without assigning uncertain selection counts.

Desktop/tablet tables scroll horizontally and keep the participant visible.
Phones show participant details and the first two answers, with Inspect
submission providing every field. Long answers have an expandable preview.
Search, source/history filters, ordering, pagination and CSV export still apply.
Exported headers and answers are protected against spreadsheet formula injection.
Excel formulas remain literal text; formulas are never evaluated or accepted as
cached score values. Excel date cells become local date/time text and use the
declared export timezone, while original CSV text is retained exactly.

Blank and repeated header names require correction because their mapping is
ambiguous. Multiple populated workbook sheets require a response-only workbook.
Files with missing optional values are accepted; missing required evidence is
held. A difference from the native questionnaire is visible for review, and the
organizer still declares the external answer columns that establish completion.

## Handling rows that need review

- An invalid earlier row does not prevent a later valid response from being
  evaluated.
- Identical valid duplicates are counted once. Conflicting valid responses for
  the same email are all held; the system does not choose the latest or highest
  score.
- A different name on an existing email is held. An exact matching name under a
  different email is a review signal, never an automatic identity merge. When
  one person used two mailboxes, use the reviewed **Merge identities** action
  with a reason and confirmation. It is blocked while either identity holds an
  active certificate or conflicting responses for the same form.
- Unmatched responses can be attached after importing registration or adding a
  confirmed participant. Adding a participant from import review does **not**
  grant eligibility. An eligibility override is separate and explicit.
- If a mapped question's type or choices changed, or the question was retired
  while a row was held, upload that response again and review a current mapping.
  Resolving a held row cannot silently reinterpret its answer. Wording-only
  edits retain the import-time label in Inspect submission.
- Correct invalid rows in the original export and upload again. Existing
  responses are never silently overwritten.
- To correct an earlier CSV response, upload the corrected export, inspect the
  held existing-response row, and use **Correct earlier import** with a reason
  and confirmation. The prior response remains as `superseded` and is excluded
  from eligibility. This cannot replace a live form response and is blocked
  while the participant has an active certificate. Revoke, review, then reissue.
- Dismissing a held row records the decision to leave it out. It does not
  certify the participant or repair source data.

## Certificate and delivery safeguards

The renderer uses the participant name and the certificate's saved layout. It
refuses missing names, unsupported glyphs, missing artwork, off-page placement,
or a fit smaller than 12 points. The browser bundles the DejaVu fonts used by
the PDF renderer and refits after fonts load. Browser and PDF engines can still
differ, so the actual Preview PDF is the final visual check. The renderer cannot
detect whether a name overlaps arbitrary text embedded in artwork; review the
design before issuance.

Correcting a participant name never rewrites an issued PDF. Revoke and reissue
to create a corrected certificate. Resending is blocked when the participant or
certificate name no longer agree. If Brevo has an unknown outcome after a
connection failure, check the provider log first and record the provider-log
search or case reference before explicitly queueing a new delivery. An email
accepted by the provider cannot be recalled.

## Existing data and release validation

These safeguards govern new imports and explicit corrections. They do not
retroactively repair historical names, overrides, or certificates. Review old
data before a new batch.

Automated tests use SQLite and synthetic data. They do not certify PostgreSQL,
live queue workers, real email delivery, or a deployed environment. Use
`DEPLOYMENT_CHECKLIST.md` for deployment validation and rehearse with an actual
event export and approved certificate artwork before real sends.
