# Certificate Studio recipients

Eligible participants now appear automatically. `EligibilityService` remains the
source for requirements, scores, submission status and historical overrides.
Studio does not copy qualifying participants into another table or require the
former Form Responses approval action. Legacy approval records remain intact.

Use **Add Recipient Manually**, enter **Full Name** and **Email Address**, and
choose **Add to Studio**. This creates an explicit certificate exception, never a
registration, submission, attendance mark or ordinary eligibility override. It
does not generate a certificate or send email. The normal preview, style controls,
selection, final send confirmation, generation jobs and delivery pipeline apply.

The roster has subtle Eligible / Manual indicators, private server-side search,
source filtering and bounded pages. It refreshes its content every 30 seconds
while visible, outside open dialogs, and offers **Refresh recipients**. Add/edit/
remove refresh the roster without replacing the document or name-positioning work.

Manual exceptions use the existing `certificate_approvals` relationship. The new
metadata distinguishes manual authorization, whether Studio created the identity,
and removal time. Audit events record the actor, webinar, source and relevant
changes without copying names/emails into audit metadata. Removed exceptions keep
their participant, historical approval evidence, certificates and delivery history.
An independently eligible participant remains available after exception removal.

Email matching uses the existing normalized per-webinar unique key. A duplicate
eligible/manual recipient is rejected, and a matching email with a different name
requires review. Similar names with different emails stay separate. An existing
incomplete participant can receive an exception without any identity rewrite; its
genuine participant evidence remains authoritative.

Studio-created recipients can edit name/email while there is no genuine response,
verification/attendance evidence or certificate history. Preparing a certificate
locks the identity. Active certificate work must be explicitly revoked before
removing its exception. After revocation, the existing participant name correction
and reissue flow preserves the original PDF and issuance/delivery records.

Only an approved owner or an administrator with the existing audited access grant
can manage recipients. Add has no extra approval/reason/password step. Edit and
remove preserve recent-password protection; if expired, confirm in the linked
separate tab, then refresh the original Studio. The existing one-time replay
completes that pending management action.

Historical positive eligibility overrides continue to work and retain their
evidence. The participant page now directs certificate exceptions to Studio and
retains the separate exclusion/history controls. The old participant-add endpoint
delegates to the same manual mechanism. Historical approval POSTs remain compatible
for existing clients but no longer gate issuance or have visible approval controls.

## Data and release boundary

The owner's real participant database must remain untouched. The additive
`2026_10_21_000300_add_manual_certificate_recipient_metadata.php` migration is
prepared in source only. It preserves existing rows and deliberately retains
provenance columns on rollback. Apply it with all other pending schema changes
only through the approved backed-up release procedure in
`DEPLOYMENT_CHECKLIST.md`, after disposable PostgreSQL rehearsal.

Automated verification uses in-memory SQLite, fake certificate storage/queues and
a mocked transactional mailer. Browser verification uses the guarded
`workflow-browser.sqlite` fixture and log-only email. These checks do not prove
production PostgreSQL concurrency, hosted operation or live inbox delivery.

Focused regressions: `tests/Feature/StudioRecipientsTest.php` and
`tests/workflow/studio-recipients.spec.js`. Existing Form Responses, certificate,
owner, batch, email, privacy and snapshot suites remain part of the local gate.

## Local verification ? 2026-10-21

- Full PHP suite: 479 tests, 5,290 assertions; includes 17 new focused recipient
  regressions and updated legacy/owner certificate and Form Responses checks.
- Vitest: 9 passed. Snapshot Chromium: 33 passed.
- Authenticated Chromium: all 10 scenarios verified across the full run (9 passes)
  and an isolated passing response-workflow rerun. A concurrent Vite build briefly
  removed manifest.json during that one workflow; rerunning against stable assets
  passed without an application change. The two new Studio scenarios pass,
  including network failure recovery and eligibility arriving while Studio is open.
- Modal screenshots inspected at 1440px light and 320px dark; 768px tablet checked.
  Existing responsive matrix covers 320/390/768/1280/1920 in both themes.
- Full Pint, production Vite build, strict UI audit (zero findings) and Git diff
  whitespace check passed. The existing PDF.js chunk-size advisory remains.

Machine-readable evidence:
`storage/framework/testing/studio-recipient-verification-2026-10-21.json`.
All database, certificate and mail verification stayed within synthetic fixtures.
