# Guided adaptive response imports

The user approved the five proposed improvements on 2026-10-24: a saved workspace for evaluations without identities, a guided import, optional table controls, reusable reviewed mappings, and focused held-row review. This specification makes that accepted scope concrete. Implementation is authorized; publication and changes to the real database are separate operations.

Later the same day the owner explicitly expanded authorization: finish implementation and broad account/email/security/functionality/performance verification, then publish and deploy the reviewed release autonomously, using free services only. This supersedes the earlier publication/deployment hold for this release. Preserve existing live/local records and keys, use fresh encrypted recovery assets and disposable PostgreSQL proof before additive migration, and never repeat historical clean-launch deletion. Controlled delivery checks are within the new request; unrelated participant sends, bulk issuance and transfer of the private source workbooks are outside the verification operation.

## Behaviour

- Keep the existing Blade/Laravel architecture, visual language, email-only participant matching, preview, dry-check binding and explicit final confirmation.
- Use four visible steps: Upload, Confirm columns, Review results, Import. Save confirmed choices on the pending import; retain them when navigating previews or correcting errors. A changed mapping invalidates its prior dry check. Give each step one primary action.
- Individual responses remain the default. Evaluation uploads may explicitly choose “Unattributed evaluation” to save original response cells without creating participants, submissions, completion, approvals, certificates or deliveries. Preserve identical answers from different rows as separate responses. Store response values encrypted, paginate them, summarize literal answers and export their originals. Apply existing webinar retention/deletion to these new records.
- Reuse a mapping only after a user chooses their own saved profile for the same form type and exactly identical ordered headers. For changed headers, show what changed and ask for fresh confirmation. Profiles store schema/mapping preferences only, never response values, filenames, source descriptions or confirmation/review tokens. Never silently map a reordered column by its old position.
- Provide optional question-column search/selection and Comfortable/Compact density on saved response tables. Default to Comfortable and all questions shown. Identity/source/actions remain available. Display the number of question columns shown, a reset action, and an accessible no-match message. Preferences and searches contain no respondent values and remain in page memory.
- Held-row review has filters for All, Duplicates, Unmatched emails, Invalid values and Identity conflicts. Keep source cells, interpretation and reason together. Export an authorized correction CSV with original row numbers and source cells; include an exact original-cell JSON field alongside spreadsheet-safe display cells. Never change the original file or merge identities automatically.

## Accuracy and usability

Literal cell values and original header spelling remain the evidence. Syntax detection is descriptive; it cannot infer a person, a score meaning, or a completion rule from arbitrary text. Ambiguous/unsupported input produces an actionable review or rejection rather than guessed data. Document the supported bounds instead of promising every possible Excel feature.

Use existing fields, buttons, panels and responsive table/record styles. Avoid competing actions, mandatory advanced options when they are irrelevant, and jargon where a plain label works. Retain no-JavaScript form/navigation fallbacks. Verify keyboard operation, narrow layouts and browser zoom with synthetic records; do not imply physical-device or assistive-technology certification.

## Isolation

Only additive migrations are authored. Do not migrate the real local database or hosted environment. Do not start mail/issuance workers, commit, push or deploy. Read the four user-owned workbooks only in the opt-in private verifier; no real values in repository fixtures, screenshots, logs or external services.
