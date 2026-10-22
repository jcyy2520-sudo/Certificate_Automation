# Webinar workspace contracts

Account access follows `MULTI_USER_PLATFORM.md`: public request, explicit email
verification, administrator approval and separate password setup. Only verified
requests appear in User Management. Shared auth fields own error associations,
password visibility and first-invalid focus. Shared admin confirmations own
account/protection actions. Approval returns to the resulting account-status
view. Administrator webinar access is an explicit audited grant. Webinar locks
are secondary to ownership and do not apply to public participant forms. Email
states describe queue/attempt/provider acceptance, never unverified inbox delivery.

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Table Selection | resources/js/certificate-editor.js | Certificate sending workflow | displayed page only, explicit send confirmation | tests/workflow/studio-recipients.spec.js |
| Select/Listbox | Native select with shared field class | Existing Blade builder and imports | native | tests/workflow/responses.spec.js |
| Date | Native datetime-local and LocalDateTime | Existing webinar timezone contract | typed server validation | tests/Feature/FormScheduleAndConsistencyTest.php |
| Form | FormController, existing field classes | AGENTS.md and existing form model | four form types, shared editor | tests/Feature/FormResponsesWorkflowTest.php |
| Scrollbar | resources/css/app.css | DESIGN.md | document scrolling; bounded response table | tests/workflow/responses.spec.js |
| Toast | partials/admin-ui and admin-scripts | Existing shared feedback | success, warning, error | tests/workflow/responses.spec.js |
| CRUD | FormResponseController and existing controllers | Existing domain services | POST filters preserve private searches; query parameters preserve tab/sort context | tests/Feature/FormResponsesWorkflowTest.php |

Searches containing participant data stay in the session, following ParticipantController's existing privacy convention. Tab and view are URL state. Imports keep their existing preview, mapping, dry-check, confirmation and held-row review. Certificate Studio derives its roster from EligibilityService plus active manual certificate exceptions. Eligible participants need no transfer or approval. Manual additions use only full name and email, never fabricate responses, and do not issue or send. Existing records are never merged or renamed from a name match. Historical approvals and overrides remain available as evidence. Any submitted qualifying attempt can satisfy a score threshold; superseded responses never qualify. Eligibility settings are shared by all forms in the same webinar.

Response imports analyze CSV/XLSX structure before creating a pending upload.
Unique exact question labels are suggestions, never fuzzy semantic matches.
Extra imported fields are visible without mutating a native form. Unknown types
remain text; numeric syntax never assigns score or rating semantics. The preview
shows counts, samples, blanks and mapping warnings. Dry-check choices are bound
to final confirmation. Invalid/unmatched/conflicting rows remain reviewable.
Removing a native question retires it while retaining historical answers. New
native submissions retain the question definition used at submission time;
Inspect submission shows that snapshot. Existing historical wording cannot be
reconstructed if it changed before snapshots existed.

Certificate Setup owns the existing webinar email fields. Normal webinar edits preserve those fields when omitted. Certificate names are derived for display from the current webinar title and certificate type; explicit artwork/style saves store that name while retaining the template ID. Reads never create a missing template. Read-only form preview submitters use `data-preview-submit` with the shared admin submit handler, allowing repeated previews and subsequent Save without disabling duplicate protection on mutations. Sample email requests require confirmation, recent password and an idempotency UUID; they create no participant or certificate record.


## Navigation and page lifecycle (2026-10-21)

`resources/js/navigation.js` and `app/Http/Middleware/FrontendNavigation.php` own
opt-in Blade navigation, history/scroll restoration and asynchronous native forms.
The representation preserves all existing controller/middleware checks and the
strict CSP/Trusted Types policy. There is no browser cache of private views or
drafts; history contains only a position index and scroll coordinates. Existing
participant searches remain server-session state. Failed validation, offline and
uncertain mutation outcomes retain the current page; no mutation is auto-replayed.
A navigation click during a save waits for success. Unrelated unsaved forms need
an explicit discard decision before a save can replace them. Unsaved-change
comparison excludes CSRF/method fields and treats empty file inputs consistently.

`app.js` owns initialization/disposal; page-global listeners, font/resize observers
and timers terminate before the next body is installed. `theme.js` and
`partials/theme-init` are the shared runtime and pre-paint preference owners.
`form-feedback.js` associates existing server error text with fields. With
JavaScript active, forms use server validation instead of native validation bubbles;
without it the original HTML constraints remain the progressive fallback.
`page-usability.js` owns scrollable-table semantics, search enhancement,
autogrowth and overlay keyboard/background isolation. `standalone.js` owns the
same theme/account/field feedback on auth/public/verification/error pages.
Native public submissions and authentication keep their normal security transitions.
See FRONTEND_EXPERIENCE.md for the workflow and test inventory.

## Certificate Studio recipients (2026-10-21)

`EligibilityService::certificateParticipantsQuery` owns the unified roster; its
normal eligibility calculation remains authoritative for Form Responses. Manual
exceptions reuse participant identity and certificate_approvals metadata, with a
source, removable authorization and a flag distinguishing Studio-created identities
from existing response identities. CertificateService checks authorization under
its existing locks at preparation and finalization. Issuance snapshots retain
manual provenance and the actual requirement results, including unmet requirements.

`studio-recipients.js` reuses the shared navigation representation/buildNode and
shared Blade roster partials. Search stays in POST bodies; source/search filters
are transient to the open Studio so drafts and participant data are not put into
URLs or browser storage. Roster pages stay bounded at 200; search and source
filtering run across the full webinar. The list refreshes every 30 seconds while
visible and outside dialogs, with a manual Refresh recipients action. Refresh
preserves the current certificate designs and selection within the displayed page.
Stale reads are aborted/ignored. Mutations are never automatically retried.

The add dialog is a native dialog using the existing fields, buttons, feedback
and theme. It preserves invalid entries, associates field errors, restores focus,
and inserts successful additions into the current roster without document reload.
Adding requires existing approved owner/audited administrator access, without a
second eligibility approval or a reason field. Pending identity edits/removal keep
the existing recent-password gate. An expired gate links to confirmation in a
separate tab; the existing one-time replay completes the action and Refresh
recipients updates the original Studio without losing design work.

Only Studio-created identities without response/verification/attendance evidence
or any certificate history can be edited through the manual identity action.
Existing participants can receive a manual exception after an exact normalized
email/name check, but their response identities stay under participant management.
Removal deactivates the exception and retains participants/certificates/delivery
history. Active certificate work must be explicitly revoked before removal;
normal eligibility can still independently keep the person in Studio. Name
correction after explicit revocation uses the existing participant correction and
reissue flow, preserving the original PDF and delivery record. Historical approval
POSTs remain compatible but no longer gate Studio or appear as a workflow in UI.
