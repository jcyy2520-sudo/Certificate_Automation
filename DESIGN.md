# Webinar workspace design

## Product and audience

Administrators collect webinar responses, review eligibility and prepare certificates. English UI, desktop data work with tablet and mobile access. Existing event, privacy, identity and certificate contracts are authoritative.

The owner remains the only system administrator. Invited Webinar Managers manage
assigned webinars with explicit participant, certificate and email permissions;
existing regular-user ownership remains compatible. Public requests appear for
review immediately; approval sends the shared activation/password setup link. Account screens
reuse the auth layout and shared field/confirmation/theme controls. User
Management uses the established table on desktop and labeled stacked records on
phones so account state and actions remain visible without horizontal scrolling.

## North Star

Forms → Form Responses → eligibility review → Certificate Studio. A quiet working interface with the response table as the main surface. No decorative metrics or new visual identity.

## Visual language

Inter Variable throughout. The owner's later 24 October redesign request replaces
the blue logo with a minimal charcoal document/check mark and a live Inter
wordmark. Use it at sign-in and primary application navigation, with a quiet
verification-page placement. Webinar, Studio, public form and error chrome avoid
repeating the graphic. Stash is a reference for restraint and spacing, not a
copied design. Light mode uses an off-white canvas (`#f6f7f8`), white panels and a
white navigation rail with a charcoal selected item. Dark mode retains charcoal
surfaces and high-contrast actions. Panels have 12px corners and fine slate
borders without decorative shadows. Page titles use 600 weight at
`clamp(1.65rem, 3vw, 1.9rem)`; section headings are 1.125rem, body/data 0.875rem.
Shared buttons and fields are 40px on desktop, with 14px control labels; primary
touch controls and survey choices are at least 44px on phones. Editable phone
inputs use 16px type. Accent tokens and certificate/email artwork remain unchanged.
The full logo reserves the same 11.25rem by 3.125rem frame in either theme
(180x50px at the standard scale). The transparent
mark uses one PNG/lossless WebP asset, inverted in dark mode; the live name stays
sharp on 4K displays. The preserved image-generation master and prompt are in
`resources/branding`; `scripts/build-brand.php` owns deterministic web derivatives.

## Runtime token ownership

`resources/css/app.css` owns Tailwind theme tokens and shared primitives. Retain `--font-sans`, `--color-accent-*`, slate surface and text tokens. Blade uses existing `panel`, `field`, `field-label`, `button-*` and `badge-*` classes. No second theme system or generated token file.

## Layout and motion

Existing desktop webinar sidebar and mobile drawer. Four form links are navigation tabs with `aria-current`. Response table owns its horizontal and bounded vertical scrolling; forms use document scrolling. Shared navigation feedback and availability switches retain their current behavior. No new motion.

`workspace-main` owns spacing through `--workspace-gutter` (1rem–3rem), a 96rem
maximum for ordinary screens and a 140rem maximum for data workspaces below
1920px. Data workspaces use the available window width from 1920px. The root scale
is 18px from 1920px, 20px from 2560px, and 22px from 3200px; table text follows
that scale and headers wrap. Existing form/prose constraints remain. The primary
rail's navigation scrolls independently
on short screens. Studio side panels are persistent from 1280px; below that width,
the existing accessible drawers preserve the canvas area. Explicit Fit zoom
recalculates when its preview viewport changes; numeric zoom stays user controlled.

Participants, email delivery history, import history and response eligibility use
the shared `record-table` phone variant below 768px. `page-usability.js` derives
labels from the actual column headings and enhances the same controls; there is
no duplicate selection markup. Without JavaScript the original scrolling table
remains. Full subjects, errors, filenames, status and actions stay readable. Raw
source comparisons retain horizontal scrolling rather than becoming records.

Upload-only creation opens the webinar's main URL immediately. The workspace
shows its title and document state; Add a document is an optional disclosure and
opens automatically after new-upload validation errors. Failed replacements reopen
only their own fields, retain the attempted title and associate the inline errors.
Excel `.xlsx` appears first in both
add/replace pickers. Legacy `.xls` must be saved as `.xlsx`. Attachments remain
private, retain original bytes, and do not import responses or publish forms.

On screens below 640px, response rows become labeled stacked records with the
participant, submission state and every answer. Long values retain their native
disclosure and Inspect submission remains available. Desktop/tablet retain the horizontally scrolling table with a sticky
participant column. Long answers use a three-line preview and native details
control. This is limited to the response data table; eligibility selection keeps
its existing table behavior. Existing theme and typography tokens are unchanged.

## States and accessibility

Participant Eligibility extends the existing private status page, webinar table,
Studio roster tabs and shared checklist. Eligible, Incomplete / Under Review and
finalized Not Eligible have text labels. Eligibility never implies issuance or
delivery. Optional activities remain visible as optional. The Studio's separate
Not eligible list is read-only; notification selection happens in Participant
Eligibility with a visible count and the existing confirmation. Review evidence
is downloaded privately as an attachment; no raw answers or another identity are
shown. Collection/import/review blockers and stale decisions have explicit states.

The 22 October creation-mode, private-document and message-only extensions reuse
the existing field, panel, button and confirmation primitives. They add no color,
font or theme system. Standard settings stay in their existing disclosure rows;
the upload-only mode hides and disables irrelevant rows. Message selection is
page-scoped, with an accessible live recipient count and explicit confirmation.
The shared dark interaction surfaces must respect cascade-layer precedence so
hovering a disclosure header cannot restore a light background. Browser evidence
is kept with the isolated workflow results, never with real credentials.

Native inputs, selects, tables, meters and dialog. Certificate sending is an explicit selection with a count and final confirmation. Eligible participants appear automatically; manual exceptions use one two-field native dialog. Page selection never implies all filtered results. Text labels accompany visual summaries. Required answers, invalid imports, no matches and missing historical data receive actionable explanations. Existing theme preference persists in localStorage.

## Preservation and verification

No historical requirement changes. Original imported cells are encrypted and erased through the existing privacy lifecycle. Existing certificate artifacts keep their authorization. Focused HTTP tests own data correctness; Playwright owns browser navigation, selection, dialogs, theme and responsive evidence. PostgreSQL and production provider behavior require their own environment verification.

Certificate Setup contains Certificate Design and Email Configuration only. Eligibility is edited exclusively in Form Responses. The composer uses the existing fields and plain text controls, with an explicit preview action opening a separate tab; preview does not consume the Save action. Test sending uses a separate confirmed, password-gated action, a synthetic recipient name and sample PDF, and the signed-in administrator's address. Existing theme tokens, feedback and confirmation components remain canonical.


## Frontend navigation and narrow-screen refinement (2026-10-21)

The existing Inter/obsidian identity remains canonical. Shared page headers wrap
within their content width; mobile page headings use 1.6rem and section headings
1.15rem. Shared field and primary control touch height is 2.75rem below 640px.
`resources/css/app.css` implements these values and the Studio control surfaces;
no generated token adapter or second palette was introduced. Dark Studio panes,
inputs, formatting tools and numeric controls use the established charcoal and
slate surfaces. Artwork retains its original color and aspect ratio.

Routine management navigation keeps the document and matching sidebar element.
Motion stays functional: existing drawer transitions and navigation progress,
with a shared reduced-motion override. Full frontend behavior and verification
boundaries are documented in FRONTEND_EXPERIENCE.md and UX-CONTRACT.md.

Studio recipients retain the existing left rail, name preview and formatting pane.
The top recipient action is Add Recipient Manually. Subtle Eligible / Manual text
shows provenance beside certificate status; delivery and sent history retain their
existing states and tabs. Add/edit use the shared field/button styles and a native
responsive dialog. The existing fonts, accent scale, layout and theme remain the
runtime owners; this change adds no tokens or visual identity.
