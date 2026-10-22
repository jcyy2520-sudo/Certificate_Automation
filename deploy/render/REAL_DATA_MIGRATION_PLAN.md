# Real-record transfer plan - approval required

Status: investigation and isolated upgrade rehearsal complete. No real-record
transfer has been performed. The owner authorized a code update; import remains
separately approval-gated. Never run a reset, destructive migration, or seeder
against either real database.

## Why the hosted records are missing

The first deployment deliberately used a fresh Supabase PostgreSQL database.
The existing local SQLite database was not uploaded. These are separate data
stores, with different application encryption keys and different migration
histories. Git publication and schema migration do not copy application records.

Read-only inspection on 22 October 2026 found:

| Record | Local | Hosted at investigation |
|---|---:|---:|
| Users | 2 | 1 |
| Webinars | 1 | 0 |
| Forms | 4 | 0 |
| Participants | 217 | 0 |
| Submissions | 408 | 0 |
| Certificates | 1 | 0 |
| Email deliveries | 3 | 0 |
| Applied migrations | 34 | 38 |

No duplicate non-null participant email groups within a webinar were found.
Local foreign-key checks and participant/submission/webinar-owner association
checks passed; the hosted association checks also passed. These checks do not
authorize merging people with similar names or overwriting future hosted data.

## Backup and upgrade rehearsal already performed

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
hosted recovery drill, final maintenance window and import approval remain open.
