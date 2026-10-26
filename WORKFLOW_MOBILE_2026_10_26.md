# Webinar workflow and mobile navigation refinement

The 26 October change retains the Laravel/Blade application, existing routes,
permissions, fonts, palette, table content and certificate behavior. The owner
delegated the workflow and phone improvements as part of the upload repair.

Overview now leads the webinar navigation. The standard overview and assigned
manager overview share an ordered guide: Forms, Responses, Eligibility,
Certificate setup, Send. Each destination uses the existing permission checks.
The previous completion checklist inferred readiness from one registration or
one issued certificate; the guide makes no completion claim. The separate live
release checks remain. Review requirements now opens the actual Eligibility
Settings view in Form Responses, rather than certificate artwork.

Creation describes the actual two modes: Responses and certificates, or Private
file storage only. Attachments retain their existing upload/download actions.
The documents page says that storing an Excel file does not create response
tables or import answers. File-storage workspaces offer the existing full
webinar creation route to users who can create webinars, and do not expose
response, import, roster or certificate destinations. Existing modes and stored
data are unchanged.

Below 1024px, a persistent labeled bar supplies common permitted actions and
More. More reuses the existing accessible drawer, including Escape, focus
restoration and the full navigation. The bar uses the established partial-page
persistence and does not add a JavaScript lifecycle. Main content and toasts
reserve room above the bar, and the header and bar respect device safe areas.
Studio keeps its separate layout, canvas and drawers; its viewport and edge
padding account for safe areas. Existing phone input sizing, touch targets and
complete answer disclosures remain in place.

## Verification recorded by the UI implementer

- RED: five initial workflow/navigation cases failed because the guide and
  common phone navigation were absent. A separate requirement-link regression
  failed with the actual artwork URL instead of the expected response settings
  URL.
- GREEN: `php artisan test tests/Feature/WebinarWorkflowNavigationTest.php
  --compact`: 6 tests, 21 assertions.
- Existing focused regression run: `MobileNavigationLayoutTest`,
  `WebinarCreationDestinationTest`, `RequestedImprovementsTest`,
  `InvitationsAndAuditTest`, `ShareLinkAndExportTest`: 41 tests, 479 assertions.
- Tests used the configured SQLite in-memory database and isolated fake/log
  transports. No owner data, provider, hosted operation or worker was involved.
- `node --check tests/workflow/workflow-mobile.spec.js` and `git diff --check`
  passed. The one required Impeccable detector pass reported nine warnings, all
  on inherited font, rail width transitions or conditional color patterns. The
  pinned Inter identity and unrelated surfaces were preserved. This is not a
  zero-finding visual audit.

The integration owner runs the full PHP / Pint / build sequence and the guarded
browser fixture after the UI and accuracy work are both ready. The new browser
file is `tests/workflow/workflow-mobile.spec.js`; run it with the existing
`playwright.workflow.config.js`. It reuses the synthetic guarded fixture and
covers links, 320/390/768px in both themes, common-navigation persistence,
complete later answers, unobscured last controls, More focus behavior,
file-storage creation guidance, assigned managers and both Studio drawers.
Integration verification is complete: the five new scenarios and the affected
response-review journey passed together after the existing test locator was
scoped to its navigation dialog. All 33 static Chromium scenarios passed.
The full workflow run had 48 passes and that one stale-locator failure; these
results are passing evidence across the full and focused runs, not a claim of
one all-49-green invocation.

The settled Studio captures exposed a real 15px closed-drawer strip caused by
the global root scrollbar gutter. A Studio-only CSS override removes that
gutter while its existing panels scroll. The final responsive run passed four
scenarios, including both drawer bounds at 320/390/768px in both themes,
partial-navigation entry/exit with normal gutter restoration, phone navigation
and complete answers, tablet fit and the wide data layout. Emulated widths are
not physical-device evidence.
