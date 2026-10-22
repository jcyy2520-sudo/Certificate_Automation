# Real-record transfer plan - approval required

Status, 22 October final verification: investigation, verified backups, isolated
restores and additive upgrade rehearsals are complete. No real-record transfer
or reconciliation correction has been applied. The owner approved preparation
but requested final development and source-file review before deployment. Present
the exact ownership/conflict/correction manifest before requesting import approval.
Never run a reset, destructive migration, or seeder against either real database.

## Why the hosted records are missing

The first deployment deliberately used a fresh Supabase PostgreSQL database.
The existing local SQLite database was not uploaded. These are separate data
stores, with different application encryption keys and different migration
histories. Git publication and schema migration do not copy application records.

Read-only inspection on 22 October 2026 found:

| Record | Local | Hosted at investigation |
|---|---:|---:|
| Users | 2 | 2 |
| Webinars | 1 | 0 |
| Forms | 4 | 0 |
| Participants | 217 | 0 |
| Submissions | 408 | 0 |
| Certificates | 1 | 0 |
| Email deliveries | 3 | 1 |
| Applied migrations | 34 | 39 |

Hosted access remains one active approved Administrator (ID1) and one inactive
regular account awaiting verification (ID2). Neither local account has an exact
normalized-email match to either hosted account. Local webinar 1 belongs to local
user 1. An equal numeric ID is not proof of identity: ownership and historical
audit actors MUST NOT be mapped by numeric ID alone. Preserve both databases'
identities and historical attribution until the explicit mapping is approved.

No duplicate non-null participant email groups within a webinar were found.
Local foreign-key checks and participant/submission/webinar-owner association
checks passed; the hosted association checks also passed. These checks do not
authorize merging people with similar names or overwriting future hosted data.

## Latest verified backup and restore evidence

The fresh local archive at the ignored
`storage/framework/testing/data-backups/20261022-200024/local-recovery.zip`
contains 71 entries, including a consistent database, its matching environment/key
and private application files. Archive/entry hashes and ZIP integrity passed. A
separate extraction verified all 71 file hashes, SQLite integrity, relationships
and all recorded table counts. Keep every recovery artifact private.

A fresh hosted logical archive was verified entry by entry and replayed into a
new disposable PostgreSQL schema using the matching hosted key. All 30 legacy
application-table projections matched, 43 forward migrations were present and
four encrypted fields were readable. The disposable schema was removed; public
webinar/participant counts stayed unchanged. This is an application-level logical
restore rehearsal, not Supabase PITR or a complete pg_dump/schema-object restore.

An isolated local copy also upgraded from 34 to 43 migrations without changing
original row/column projections; integrity and foreign-key checks passed.
PostgreSQL 43-migration rollback/reapply and parallel daily-quota reservation
checks passed in disposable schemas. Original schemas remain 34/39 respectively.

Private proof files are under `storage/framework/testing/final-readiness/`;
`hosted-backup-path.txt` points to the protected hosted archive. Refresh these
backups again immediately before any approved important-database operation.

## Earlier backup and upgrade evidence (historical)

A consistent SQLite online backup, original environment/key, and 35 private
application files were bundled locally under the Git-ignored
`storage/framework/testing/data-backups/20261022-123351/` directory. The archive
contains 37 entries in total. SQLite integrity and foreign keys passed, record
counts matched, ZIP integrity passed, and every archived entry's SHA-256 was
verified against its source snapshot. This private archive contains real data
and secrets: do not publish it, upload it to GitHub, or paste it into chat.

A separate restored SQLite copy upgraded from migration 34 to 39 using its
original application key. Counts of users, webinars, forms, participants,
submissions, certificates, and email deliveries stayed unchanged. The actual
local database was not migrated. An initial rehearsal with the hosted key failed
closed on encrypted import-issue content, confirming that using the correct key
is mandatory; the original-key rehearsal succeeded.

All 39 schema migrations, including a rollback/reapply of the new additive
migration, were also rehearsed in a newly created isolated PostgreSQL schema.
That schema contained no real records and was removed after verification. The
public schema's webinar count remained unchanged. This is schema portability
evidence, not a validated real-record cross-engine import or full recovery drill.

## Transfer procedure to prepare after approval of scope

1. Agree on a short maintenance window and identify the exact source snapshot.
   Stop local writes before the final consistent backup; do not run local queue
   workers, the scheduler, issuance, erasure or mail while preparing the snapshot.
2. Refresh both inventories and create a complete consistent hosted backup,
   including schema, encrypted data, private objects, and the hosted application
   key. Verify backup integrity and a restore into an isolated PostgreSQL target
   before importing real records. Supabase Free automatic backup availability
   must not be assumed.
3. Upgrade only an isolated restored source copy. Compare every table, key,
   relationship, migration version, retention/deletion state, certificate code,
   public identifier, active-delivery claim and storage path against the hosted
   target. Produce a private conflict report without disclosing participant data
   in public logs or source control. Record source row counts/checksums.
4. Map existing local owners explicitly to approved hosted accounts. Preserve
   the current hosted administrator and MFA/password/session state. Do not copy
   local users, account/reset tokens, sessions, queue jobs, locks, cache values,
   or pending executable work into the live system. Do not silently adopt a
   second local user or change access privileges.
5. Build a bounded importer that preserves source relationships through an
   explicit ID map. Check unique slugs, participant mailbox keys, public IDs,
   certificate verification codes, overrides and email idempotency keys before
   any write. Abort on conflicts; never merge solely on a person's name.
6. Decrypt each encrypted field with the source key only inside the protected
   importer and re-encrypt with the hosted key. Do not replace the hosted APP_KEY:
   it protects the existing administrator's MFA and other hosted encrypted data.
   Identify every encrypted model cast and historical encoding, including
   imported response snapshots and held import-issue content. No plaintext
   staging exports or debug output.
7. Copy private certificate PDFs, template artwork and retained import evidence
   into the private Supabase bucket. Validate safe destinations, hash equality,
   object counts and private access. Translate disk/path references explicitly.
   Reconcile missing source files before approval. Preserve existing certificate
   IDs/codes and issuance/sent/revoked history; do not regenerate or send them.
8. Rehearse the complete transfer on isolated copies, retaining historical
   retention deadlines and tombstones. Report overdue records before enabling
   the hosted scheduler: old deadlines can immediately trigger existing erasure
   tasks. Do not extend retention or bypass erasure silently.
9. Present the final conflict report, mapped owners, table/file counts, privacy
   decisions, recovery proof and exact import operation for the owner's explicit
   approval. This document alone is not import authorization.
10. With approval, keep the application in maintenance mode and workers/scheduler
    stopped; stage files and apply the validated record mapping transactionally.
    Record a transfer manifest that makes retries detect already-imported rows.
    Keep email in log mode. Roll back the transaction on validation failure;
    retain all source material and prior hosted backups.
11. Verify counts, every foreign key, per-webinar associations, ciphertext
    readability, owner isolation, certificate/file hashes, unique codes and
    email-log history. Obtain the owner's acceptance before resuming jobs or
    enabling any live delivery. Leave temporary import material private and
    remove it only under the agreed retention/recovery procedure.

The conflict/relationship preflight and schema upgrade rehearsal are complete.
An end-to-end cross-engine real-record importer, encrypted re-key rehearsal,
final identity mapping, final maintenance window and import approval remain open.

## Independent 86-recipient reconciliation hold

The read-only comparison found all 86 audited recipients already among the 217
local participants, matched only by normalized email within webinar 1. Their
registration/pre/post evidence and ordinary eligibility agree. No new86-row
import is needed. Five certificate-name cases remain for organizer confirmation;
13 post-before-pre sequences match the original timestamps and are not a
disqualification under the current rules. Preserve timestamps and eligibility.

The system's87th recipient is an existing manual exception (participant 146),
not an extra ordinary match. Preserve it pending the owner's explicit decision.
There are 52 held import issues; never dismiss them merely to unlock sending.
Use the private dry-run report in
`storage/app/private/reconciliation-20261022/DRY_RUN_RECONCILIATION_REPORT.md`
for exact IDs and evidence. No certificate or real email may be sent during
reconciliation; the owner has prohibited all sending in this verification phase.

## Verification incident and preservation

A browser-test launcher lost its isolated database settings during PHP's
development-server reload. The account insert failed, but14 synthetic failed-login
audit entries were appended to the original local audit table (IDs 94–107).
All 93 original audit entries and every other original table remained unchanged;
integrity passed with zero FK errors. These entries were preserved, not erased.
The replacement test router rejects any real database or live mail configuration
before Laravel boots; the repaired20-workflow browser run passed. Record this
incident in the final transfer manifest rather than silently rewriting history.
