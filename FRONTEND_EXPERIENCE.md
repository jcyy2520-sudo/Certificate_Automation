# Frontend experience ? 2026-10-21

## Implementation

The application remains Laravel controllers/services, Blade, Tailwind and small
JavaScript modules. No new frontend dependencies, SPA framework, alternate API,
private-page cache, background worker or database migration was introduced.

`FrontendNavigation` is an opt-in response representation of the existing Blade
view. Requests still pass through the existing routes, sessions, ownership,
approval, webinar protection, CSRF, validation and password-confirmation gates.
Normal requests and JavaScript-disabled pages keep the existing HTML response.
The opt-in response preserves existing security/no-store headers and adds Vary.

The transport sends a structured tree built from the server's escaped view.
The browser creates elements, attributes and text nodes; it does not parse HTML
strings, execute fetched scripts, create a Trusted Types policy or alter CSP.
Scripts, event attributes and executable embedding elements are excluded. The
original same-origin module remains in the document head throughout navigation.
This avoids a framework migration under the existing Trusted Types policy.

`navigation.js` owns link/form enhancement, request cancellation for superseded
reads, history, scroll restoration, duplicate protection, failed-request recovery,
unsaved edits and navigation announcements. It honors the existing form encoding,
CSRF field, method override, submitter and file upload. Mutations are never
replayed automatically after a timeout or ambiguous response. A link clicked
during a mutation waits until that mutation succeeds; validation/network failure
keeps the current screen. Downloads, previews in another tab, sign-in/sign-out,
webinar unlocks and security boundaries retain document navigation when needed.
Asset-version changes also request a fresh document.

The current document and matching desktop sidebar element persist. Sidebar
content is refreshed from the authorized server response so names, status,
counts, permissions and active links cannot become stale. There is no private
HTML/data cache. Searches containing participant identity retain the existing
server-session privacy convention; form type, response view, sort/page and other
non-sensitive existing query parameters keep their normal URLs. Forms and Form
Responses link to the matching active form type. Direct links and refreshes work
without the navigation transport.

Each page has an AbortController lifecycle. Before replacement it removes global
listeners, disconnects certificate ResizeObservers/font listeners and cancels
summary/status polling. The response summary refreshes using a background request
instead of location.reload. Background refresh pauses while hidden, busy, editing
or displaying a confirmation. Remote participant searches are debounced and IME
aware, preserve focus/caret and serialize session-filter writes. Newer input is
resubmitted after the prior write, rather than racing server-session updates.

No drafts or participant data are written to browser storage/history. Meaningful
form deltas and recipient-design changes trigger an in-app leave dialog; true
unload uses the browser's beforeunload mechanism. Failed validation retains the
original unsaved baseline, and asynchronous upload validation retains the selected
file in memory. Saving while another form has edits requires an explicit choice
before discarding those other edits. Sensitive fields retain existing server
non-flashing rules. Native survey submissions retain document transitions and
have unsaved-answer and duplicate-submit protection.

## Shared responsive and accessibility treatment

- Shared page headers, content widths, breadcrumb wrapping, touch controls,
  keyboard focus, textarea growth, error associations and loading semantics.
- Mobile drawers close on navigation/Escape, trap focus, restore the opener,
  isolate background interaction and fit dynamic viewport/safe areas.
- Wide tables retain local scrolling and keyboard access; their scroll containers
  contain visually hidden table labels as well as visible cells. Existing mobile
  response summaries and full inspection retain access to every imported field.
- Account, recovery, pending/verification, public survey, verification and error
  pages use the same theme preference reader and runtime toggle. Password reveal
  controls are available on legacy inline password fields as well as shared fields.
- Certificate controls retain original percentage coordinates and artwork aspect
  ratio. Template editing now also exposes numeric Top/Left percentages. Studio
  accepts zero coordinates, retains keyboard/drag/preset alternatives and has
  explicit styles for previously unstyled editor primitives in both themes.
  Preview PDF and Export ZIP remain available inside the mobile editor panel;
  the toolbar prioritizes actions without wrapping labels at laptop widths.
- Modal bounds, reduced-motion behavior, safe-area padding, summary bars and
  screen-reader announcements are shared instead of copied per page.

## Verification scope

`tests/Feature/FrontendNavigationTest.php` checks the opt-in representation,
security/no-store headers, escaped Unicode/markup, ownership denial,
authentication boundary and validation behavior.

`tests/workflow/navigation.spec.js` checks real-HTTP fetch navigation without
new documents, persistent sidebar identity, Back/Forward, offline recovery,
unsaved Back cancellation, validation recovery, duplicate-submit protection,
mobile drawer keyboard behavior, themes, polling disposal and direct URLs. Its width matrix visits
22 management routes at 320, 390, 768, 1280 and 1920 CSS pixels in both themes
(220 route/theme/width combinations), plus narrow auth,
recovery and error routes. Existing workflow tests cover account approval and
password setup, webinar ownership/password locks, native collection, CSV/XLSX
mapping/dry-check/import, adaptive response details, eligibility, approval and
Certificate Setup/sample outbox behavior. Snapshot browser tests separately cover
certificate coordinates, sizing, formatting, selection and confirmations. A
separate toolbar test checks overflow and control intersections at
320/390/640/768/1024/1280/1920px.

These are desktop Chromium and synthetic SQLite/log/fake-mail checks. They do
not establish physical-phone keyboard/touch behavior, assistive-technology
acceptance, PostgreSQL behavior, production performance or live email delivery.
The transport still renders the full authorized Blade view on the server; it is
not a component-level data API. Production latency depends on the existing
queries, server, data and compression. The existing lazy PDF.js chunk advisory
remains. Follow MULTI_USER_PLATFORM.md, ADAPTIVE_RESPONSE_IMPORTS.md and the
existing deployment checklist for the pending real-database release procedure.


## Final local results

| Check | Result |
|---|---|
| composer run test | 431 passed / 4,844 assertions |
| npm run test:js | 8 passed |
| npm run test:e2e | 31 passed |
| npm run test:workflow | 8 passed |
| Final navigation/Studio workflow rerun | 5 passed |
| Pint, Vite build, git diff --check | Passed; existing lazy PDF.js size advisory |
| Strict UI contract audit | 0 errors, warnings or violations |

Machine-readable evidence is in
`storage/framework/testing/frontend-verification-2026-10-21.json`.
Screenshots `frontend-studio-desktop-{light,dark}.png`,
`frontend-studio-phone-dark.png`, `frontend-phone-dark.png` and
`frontend-auth-phone-dark.png` are in the same testing directory.
