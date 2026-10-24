# Webinar workspace contracts

## Authorized extensions (22 October 2026)

The owner's requested improvements add a native creation-mode select. Standard
keeps the four forms/rules/template. Upload Forms Only creates a closed document
workspace from a title, without those defaults. The mode is immutable after
creation. Shared webinar settings own conditional field visibility and disabling.
Uploaded documents are private attachments, not public response-collection forms.
Upload Forms Only creation returns to the created webinar's main URL. An upload
is optional; empty document workspaces are fully accessible immediately. The
shared document screen owns an Add a document disclosure, opened automatically
for validation errors, and the same Excel-first picker for add and replacement.
Private `.xlsx` attachments support multiple sheets and preserve original bytes;
they do not use the response importer's single-sheet interpretation contract.
Downloads and mutations inherit ownership, webinar unlock/admin audit, MFA and
password-step-up middleware. Replaced/removed files remain inventoried privately
until authorized permanent webinar deletion. The existing policy grants platform
administrators oversight over all webinars; ordinary approved users see only
their own or explicitly assigned webinars. Public requests appear before approval;
approved requests and administrator invitations verify email during shared password setup.

`participant-messages.js` owns the message variant of page-only selection/count
and extends the existing shared confirmation/queue/log workflow. A count precedes
confirmation; no hidden all-results selection. `ParticipantMessageController`
and `EligibilityService` own eligibility and idempotency; the existing send job
rechecks lifecycle/recipient/owner state before each attempt. Message-only emails
have no certificate or attachment. Unknown provider outcomes require explicit
reconciliation before a manual retry. Log mail is labeled as captured, not inbox
delivery. Profile password changes require the current password, rotate session
credentials/reset links, preserve MFA, and return to sign-in. Passwords are never
retained in step-up replay payloads.

`navigation-request.js` retries only GET 502/503/504 responses, at most three
times with cancellable bounded delays. Mutations are never auto-replayed. Native
501/auth/validation errors remain visible; private entries stay on the page.
The shared CSS compatibility layer also owns interaction-state surfaces: dark
hover/focus overrides must outrank Tailwind utility layers, as the resting
surface overrides already do. Light-mode rules and native selection are kept.

Evidence: `RequestedImprovementsTest.php`, `tests/js/navigation-request.test.js`,
and `tests/workflow/improvements.spec.js`. Real-record transfer is governed by
`deploy/render/REAL_DATA_MIGRATION_PLAN.md`, not by code-deployment permission.

Account access follows `MULTI_USER_PLATFORM.md`: public request, administrator
approval and shared email activation/password setup. Pending requests appear
immediately. Admin invitations bypass approval and cannot grant Administrator.
Shared auth fields own error associations,
password visibility and first-invalid focus. Shared admin confirmations own
account/protection actions. Approval returns to the resulting account-status
view. Administrator webinar access is an explicit audited grant. Webinar password
protection is secondary to membership. Administrator locks override every
non-admin management permission, including the regular-user owner's authority,
and do not change public participant forms. Email
states describe queue/attempt/provider acceptance, never unverified inbox delivery.

Participant eligibility follows `ELIGIBILITY_FEEDBACK.md`. The existing
EligibilityService remains the sole source of certificate qualification.
ParticipantEligibilityService presents safe evidence and a separately finalized
communication status; it does not grant eligibility. ParticipantStatusLinkService
owns expiring one-time fragments, scoped sessions and revocation versions. Review
requests retain original evidence, and verified import corrections reuse
CsvImportService. Each manual notification uses the existing outbox/queue/log,
requires selection and confirmation, and revalidates its evidence before sending.

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Table Selection | resources/js/certificate-editor.js; resources/js/participant-certificates.js | Certificate sending workflow | displayed page only, explicit send confirmation | tests/workflow/studio-recipients.spec.js; tests/workflow/calm-workspace.spec.js |
| Select/Listbox | Native select with shared field class | Existing Blade builder and imports | native | tests/workflow/responses.spec.js |
| Date | Native datetime-local and LocalDateTime | Existing webinar timezone contract | typed server validation | tests/Feature/FormScheduleAndConsistencyTest.php |
| Form | FormController, existing field classes | AGENTS.md and existing form model | four form types, shared editor | tests/Feature/FormResponsesWorkflowTest.php |
| Scrollbar | resources/css/app.css | DESIGN.md | document scrolling; bounded response table | tests/workflow/responses.spec.js |
| Toast | partials/admin-ui and admin-scripts | Existing shared feedback | success, warning, error | tests/workflow/responses.spec.js |
| CRUD | FormResponseController and existing controllers | Existing domain services | POST filters preserve private searches; query parameters preserve tab/sort context | tests/Feature/FormResponsesWorkflowTest.php |

The shared `record-table` variant in `page-usability.js` / `app.css` owns mobile
participants, email and import history and response eligibility. Headings supply
cell labels; one actual checkbox/action node remains authoritative in either
layout. JavaScript-free pages keep native scrolling tables. Scope and selection
counts remain page-only. `participant-certificates.js` owns eligible-row selection,
the partial/all state and the Studio action count on the participant list; disabled
rows cannot join the selection. Subject, detail and filename text wraps instead of
depending on hover. Tests are in `tests/workflow/calm-workspace.spec.js` alongside
upload/download, public field naming, short-height navigation, tablet Studio and
3840px layout regressions.

Replacement forms flash a `_document` view-context identifier; only the matching
rendered record reopens and uses retained title/errors. This value is never used
for authorization or storage, which continue using the route-bound document.

Studio's persistent panels begin at 1280px. `page-usability.js` uses that same
boundary for modal isolation; primary/webinar navigation retains its 1024px
boundary. Resizing to desktop dismisses Studio drawers. Fit zoom follows preview
viewport changes; explicit numeric zoom does not. Dynamic participant prompts
use native fieldset/legend groups and explicit input names/help/error references.

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

Source previews retain complete headings, values and whitespace and paginate five
responses at a time through every row, including anonymous evaluations. Preview
navigation uses only `preview_page`; viewing a row grants no participant identity
or completion. Held issues have a separate `issues_page` paginator at 200 per
page, retaining every malformed-row cell with collision-free overflow labels.
CSV response exports use explicit standard quoting/escaping and retain exact
original-cell JSON; formula-leading cells receive the existing protective quote.
Excel dates use the documented ISO local-wall-time representation. Unsupported
numeric fractional-second formats refuse import rather than discard precision.
Combined response columns group normalized labels; each import's original label
and value remain available in Inspect submission and original-cell JSON.
`RealResponseFileAccuracyTest` is opt-in and emits counts/coordinates only. Its
private browser runner keeps decrypted HTML/cells in memory, blocks all external
requests and saves no real screenshots, traces, DOM snapshots or data fixtures.

The guided import separates Confirm columns from Review results. Validated
choices and non-personal outcome counts remain on the pending import; changing
choices clears the old review hash. Saving a profile never supplies a source
claim or final confirmation. Profiles are private to their owner and compare
ordered headers exactly; native question reuse requires a unique exact kind,
label, type and choice set. Existing webinar permissions still allow authorized
administrators and managers to continue another uploader's pending import.

Evaluation imports may explicitly choose Unattributed evaluation. These records
have no participant/submission association and cannot establish completion or
certificate eligibility. The separate feedback workspace preserves every source
row, including identical answers, with literal per-column summaries and original
exports. Exact headers remain once on the import and encrypted ordered values
remain on each row. Webinar retention removes these values. Reconciliation
accounts for saved feedback and held rows without assigning an identity.

Saved response tables start with all question columns shown and Comfortable
density. Choose questions filters its heading list only, never response values.
The shown-column count and Reset table remain visible; identity/source/actions
remain available. Choices live only in page memory and dispose on navigation.
Exceptionally long/wide headings reduce the server page size below25 to bound
repeated mobile labels; every column and every response remains reachable through
pagination. CSV headings remain unique after spreadsheet sanitization, with exact
original labels and cells retained in original-cell JSON.

Held review groups duplicates, unmatched emails, invalid values and identity
conflicts, with separate pagination. Its inspector keeps the original cells,
interpreted result and reason together. The correction download respects the
selected group/form, retains source row/import references and standard CSV quoting,
and never silently edits or promotes an imported identity.

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
