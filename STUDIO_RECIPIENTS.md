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


## 22 October: inline shared-style save and local pending-work notice

Use for every recipient retains its explicit confirmation and audited validation,
but enhanced Studio requests now receive only the canonical eight style values.
The editor updates its default and unchanged recipient adjustments in place,
retains selection and preserves newer edits made during the save. It does not
rebuild the roster, render PDFs or create email deliveries. Failed saves retain
adjustments, restore the button and require an explicit retry; a 30-second timeout
advises checking the saved state before retrying. Normal form submissions still
redirect to the appropriate editor for progressive enhancement.

The localhost immediate-send profile never recommends starting a queue worker
for older unfinished certificate work. It explains that the item is waiting and
directs the organizer to its explicit Send now control. Older unresolved email
requests direct the organizer to Email logs. Merely viewing this notice or saving
a style does not execute any certificate or email work.

## 22 October: PDF font fidelity and plain certificate email

All five Studio font choices use the same bundled DejaVu family in the browser
and generated PDF, including regular, bold, italic and bold italic. Arial
equivalent maps to DejaVu Sans; Times New Roman equivalent maps to DejaVu Serif.
PDF CSS uses unquoted family names because escaped quotes inside a style element
were making Dompdf fall back to Times. The regression checks inspect the actual
PDF BaseFont values and confirm that the email attachment contains those same
PDF bytes. Existing issued PDFs and delivery history are retained; retrying an
existing email does not regenerate its certificate.

Certificate email uses the saved message in black text on a white background,
with no decorative card, banner, button, personal link or verification-code
addition. Owner-only preview information and explicit test-email identification
remain visible in those modes. Partial message updates preserve the existing
subject; explicitly clearing a field still restores its configured default.

The owner explicitly requested removal of password reconfirmation for sending.
Selected, individual, all-ready, resend and administrator sample certificate
actions therefore keep their authentication, webinar permission checks, CSRF
protection and rate limits without EnsureRecentPassword. Other actions, including
revocation, identity correction, settings changes and downloads, retain their
existing password policy. Recipient/preview and sample-send confirmations remain.

## 22 October: name baseline and artwork name area

The later screenshot exposed a separate vertical-position defect: Dompdf's CSS
line layout put the name below its browser position. The renderer now places the
name directly on the PDF canvas using the same one-em baseline calculation as
Studio. Browser rounding is corrected using the loaded font's actual metrics.
Font kerning and discretionary ligatures are disabled in the name preview to
match the PDF's glyph advances. The complete name is fitted before drawing.

Montserrat, Lato and Cormorant Garamond add three locally bundled, licensed font
families, each in regular, bold, italic and bold italic. All eight font choices
(32 style combinations) use matching embedded/static files. Sources, hashes,
licenses and ascent/descent metadata are in resources/fonts/certificates/.

The current local artwork's name area was corrected above its printed line and
away from its decorative column. Its optional name_max_width and name_bottom
constraints are shared by browser and PDF, including recipient adjustments. A
replacement artwork clears those previous-image constraints. Existing issued
PDFs, certificate records, delivery history and eligibility data are retained.
The saved template correction does not issue, send or retry anything.

## 23 October: corrected attachments and local progress

The owner's signature-only correction keeps the original certificate records, identifiers, name text, fonts, positioning, issuance time and earlier delivery history. The approved 86 stored PDFs have their background image stream replaced with the signed artwork. Original files and a verified SQLite backup are retained privately. Pixel comparisons for all86 allow changes only in the dean's signature area; the name drawing streams and font resources stay identical.

An audited signature correction returns a currently eligible recipient to To send until a new certificate delivery is accepted after that correction. This is a derived display state: it does not reset sent_at or create a new certificate. Preview corrected PDF opens the actual stored attachment inline. Editing is disabled for that correction. The 52 unresolved import rows still block NEW all-ready issuance; they do not block the explicit corrected resend and are not altered by this workflow.

Local selected, all-ready and corrected-resend actions use a visible per-recipient progress dialog. A throttled preparation request creates an expiring plan bound to the logged-in user and webinar. Each paced request executes one atomically claimed step using the existing issuance/resend validation; completed step responses are cached so replays do not send again. An uncertain response or quota/configuration refusal stops the run without an automatic retry. Stop finishes the current request and starts no further recipients. The page must stay open. Provider acceptance, failures, skipped recipients and unconfirmed outcomes are separate; acceptance is not inbox proof. Local cache must persist between HTTP requests (the owner's runtime uses file cache); production queue operation is unchanged.

Sent retains the original history and also offers explicit single/bulk corrected resend controls for currently eligible corrected recipients. To send is the preferred preview-and-select workflow. No ineligible notification is dispatched by certificate actions. Certificate sending and certificate PDF/ZIP review no longer require password reconfirmation by the owner's explicit request; sign-in, authorization, CSRF and send confirmations remain. Other sensitive-action password gates and queue visibility windows are unchanged.
