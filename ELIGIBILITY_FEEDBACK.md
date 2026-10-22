# Participant eligibility and feedback

The existing EligibilityService still decides certificate qualification. No rule,
score threshold, Evaluation requirement, submission or manual exception is changed
by this feature. Eligibility, certificate generation and email delivery are shown
separately. Administrator actions remain scoped to webinar policy permissions.

## Participant experience

The existing participant status page now shows Registration, Pre-Test, Post-Test,
Evaluation and required attendance, including optional/required labels, matched
record references, safe timestamps and configured score thresholds. An import's
recording time is explicitly distinguished from a source submission time. Raw
answers, other identities and private organizer notes are never rendered.

Email-matched held rows are Under Review. Missing records are Not Found, with an
explicit explanation that this is not proof of non-submission. Different emails
are never matched automatically by name. Existing organizer overrides are labeled
as decisions rather than fabricated completion evidence.

Personal links carry 256-bit random secrets in fragments, store only keyed
digests, require a CSRF POST, expire and are single-use. Status sessions are
participant/webinar/email/version-bound and expire. Revoke personal status access
invalidates both issued links and current sessions. Certificate emails include a
personal status link; a fresh link can be requested through the existing entry
page. Access also stops at archival, deletion or the privacy retention deadline.

## Reviews and final decisions

One pending review per participant is enforced under locks and a database unique
claim. Requests are idempotent, rate limited and do not grant eligibility.
Explanations and decision reasons are encrypted. Optional evidence is PDF/PNG/JPEG
up to 2 MiB with actual MIME/extension and image-dimension checks. Private file
inventory commits before storage I/O, upload failures remain visible, and downloads
are authorized attachments with sandbox/nosniff headers. Privacy erasure and webinar
deletion include these files and retain inventory when cleanup fails.

Approving a correction either applies an existing validated held import for the
same email through CsvImportService, or verifies a record change already made
through the existing management workflow. Imported replacements preserve
superseded responses and retain the active-certificate correction guard. Other
discrepancies are reconciled in Imports/Participants first. A request or uploaded
file alone cannot create submission evidence or an eligibility override.

Not Eligible is a separate, explicit communication decision, permitted only after
collection closes, uploads/held rows are resolved, reviews are decided and the
organizer confirms final verification with a participant-visible reason. The
decision is tied to a keyed fingerprint of rules and evidence. Changes invalidate
the decision; newly qualifying records appear in Studio without another approval.
Prior decisions/reviews remain recorded rather than overwritten.

## Operator controls and notifications

Webinar navigation includes Participant Eligibility, with a webinar switcher,
private search, current-status/review filters, 50-row pages, individual records,
review decisions and notification history. Studio has a separate non-selectable
Not eligible list (first 50; full list in Participant Eligibility). That list
includes unresolved records, not only finalized failures.

Notifications are explicitly selected (one person or up to 50), counted and
confirmed by the operator. No background eligibility email is triggered by a
request, correction or final decision. Existing pending notifications are skipped;
previous notifications require an intentional resend and uncertain provider
outcomes require reconciliation. Durable submission UUID/digest claims prevent
repeat-submit duplicates. The existing email queue, budget, retries, encrypted
outbox and logs are reused. Stale evidence, expired/revoked access or revoked
sender permissions cancel work before transport. Eligible recipients with a
generated/processing certificate are skipped to avoid an unnecessary extra email.

The configured certificate transport also handles eligibility/status-link email.
General participant-message transport remains independent. Log captures are never
called delivery. Provider acceptance and confirmed delivery events are distinct.
No inbox receipt is inferred from a queue or accepted API request.

Administrative decisions and notification actions use permanent internal actor
IDs and encrypted historical actor snapshots in the existing audit trail. Reasons
are stored in the review/decision record, not copied to generic audit metadata.

## Schema and release boundary

Migration `2026_10_22_000400_add_participant_eligibility_feedback` adds review and
decision tables and a participant access version. No backfill or original-row
rewrite is performed. Rollback deliberately retains evidence and revocations.
Apply only after a verified backup and isolated preservation/PostgreSQL rehearsal.
Local synthetic checks do not establish production mail, restore or host readiness.
No real participant notification or certificate is authorized for agent sending.


## Final local verification — 22 October 2026

- Full composer test run: 584 passed, 6,342 assertions. Includes 28 new eligibility feedback cases plus existing account, authorization, import, issuance, privacy and delivery regressions.
- Full Pint check passed; Vitest12passed; production Vitebuildexit0. Existing large PDF-worker chunk warning only.
- Real HTTP Chromium workflow suite:18passed. Static snapshot Chromium:33passed. Synthetic data/isolated SQLite/log providers only; no real transport was invoked. Participant mobile dark, admin tablet dark and desktop Studio screenshots visually inspected.
- Isolated config, route and Blade view cache checks passed. Composer locked audit0advisories/0abandoned; full npm audit0vulnerabilities. Known-secret scan109eligible changed/untracked source files:0matches; all local credential files ignored and unrelated .claude excluded.
- Read-only original local28tables exactly match verified backup projections; integrityok/FK0; local schema34unchanged. Isolated clone42migration rehearsal preserves30table projections. Disposable PostgreSQL42migration+latestrollback/reapply passed and schema removed; public webinar count unchanged.

Completed and verified here means local/synthetic behavior and the isolated schema/preservation checks above. No new production deployment, important-database migration, real-record import, real certificate/participant email or live provider/inbox delivery test was performed. Actual new Docker PHP runtime and Render behavior remain release-time checks. Owner has one final task before deployment, so release remains on HOLD.
