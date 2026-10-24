# Calm responsive Certimate workspace

24 October 2026, Asia/Manila. Implements the owner's current whole-application
redesign and Excel upload request, carrying forward the approved Certimate
identity and shared-component approach in the earlier UI specification.

## Intent and approach

Administrators and webinar managers need a quiet workspace that remains usable
on a phone, including populated records, upload controls and certificate work.
Study Stash at https://stash.johnchristiann.com for spacing, restraint and clear
hierarchy. Retain Certimate's own logo, Inter, neutral actions and both themes.
Shared primitives plus targeted workflow layouts are the chosen implementation
approach. A framework rewrite or separate phone application would duplicate the
working behavior and is unnecessary.

## Visual and layout decisions

Runtime CSS remains the token owner. Use an off-white workspace canvas, white
panels, slate text and fine neutral borders. The accent scale and certificate
artwork stay under their existing owners. Make navigation and form controls
easier to read, use a consistent spacing rhythm, and constrain prose while
allowing data workspaces to use more of a 4K screen. Use vector branding and
existing fonts; no decorative raster assets or new animation.

Record-based tables (participants, messages, email history and import history)
become labeled records on phones, preserving selection, status and every action.
Actual comparison tables retain their own visible, keyboard-accessible scrolling.
No data disappears at a breakpoint. Long email subjects/details wrap. Mobile
editable inputs use 16px type; primary controls and survey choices have 44px
minimum touch geometry. Drawers and confirmations retain their existing focus,
Escape, unsaved-change and scroll ownership contracts.

## Upload and creation behavior

The private document picker and server must accept Excel .xlsx first alongside
the current PDF, DOCX and image types. The response importer is a separate flow.
Private attachments preserve original bytes, extensions and download protection;
multi-sheet workbooks are valid attachments. Reuse bounded package inspection and
reject active/macro content. Explain how to save older .xls workbooks as .xlsx.

Creating an Upload Forms Only webinar opens the created webinar's main URL
immediately. Its workspace shows the webinar title and document state. Uploading
is an optional next action, not a prerequisite or forced first screen. Empty and
error states guide the next action without obstructing the workspace.

## Boundaries and verification

Preserve .env, APP_KEY, real databases, stored documents/PDFs, queues, provider
settings, domain logic, locks and permission checks. No live mail or certificate
issuance. This request authorizes local source work and synthetic verification;
deployment is reported separately from the checked local result.

Verify backend upload/download/replacement and creation regressions, all PHP
tests, Pint and build in that order, then JS, snapshots and isolated real-HTTP
workflows. Extend browser coverage beyond overflow: record values/actions,
selection, mobile forms, drawer focus, 320–3840px widths, both themes and short
viewports. Inspect representative screenshots. Record limits of local synthetic
evidence explicitly.
