# Webinar workspace design

## Product and audience

Administrators collect webinar responses, review eligibility and prepare certificates. English UI, desktop data work with tablet and mobile access. Existing event, privacy, identity and certificate contracts are authoritative.

Approved regular users now manage their own webinars; platform administrators
review verified account requests and retain audited oversight. Account screens
reuse the auth layout and shared field/confirmation/theme controls. User
Management uses the established table on desktop and labeled stacked records on
phones so account state and actions remain visible without horizontal scrolling.

## North Star

Forms → Form Responses → eligibility review → Certificate Studio. A quiet working interface with the response table as the main surface. No decorative metrics or new visual identity.

## Visual language

Inter Variable throughout; existing title, section, body and utility sizes. White/slate surfaces in light mode and slate surfaces in dark mode. Accent uses the existing obsidian token scale. Thin table rules, modest existing panel rounding, clear focus rings.

## Runtime token ownership

`resources/css/app.css` owns Tailwind theme tokens and shared primitives. Retain `--font-sans`, `--color-accent-*`, slate surface and text tokens. Blade uses existing `panel`, `field`, `field-label`, `button-*` and `badge-*` classes. No second theme system or generated token file.

## Layout and motion

Existing desktop webinar sidebar and mobile drawer. Four form links are navigation tabs with `aria-current`. Response table owns its horizontal and bounded vertical scrolling; forms use document scrolling. Shared navigation feedback and availability switches retain their current behavior. No new motion.

On screens below 640px, response rows become compact stacked records with the
participant, submission state and first two answers. Inspect submission exposes
all fields. Desktop/tablet retain the horizontally scrolling table with a sticky
participant column. Long answers use a three-line preview and native details
control. This is limited to the response data table; eligibility selection keeps
its existing table behavior. Existing theme and typography tokens are unchanged.

## States and accessibility

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
