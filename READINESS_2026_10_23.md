# October 24 readiness verification

This is the October 23 source-freeze verification report. Older release reports and account handoffs contain historical deployment/schema counts. At source freeze the previous hosted artifact was `ea94bc4fce502634b91745c22c437217f95ada88`. The final release SHA, deployment, clean-launch and domain outcomes are recorded separately in the repository-root PROJECT_MEMORY.md and private operator proofs; this report is not a live deployment monitor.

## Account activation

Read-only hosted inspection established that the approved account's activation message was never attempted: pending, attempts zero, no provider message ID and no acceptance timestamp. The recorded error was `Could not verify the free provider allowance; no email attempted.` Its one-hour activation link subsequently expired. This proves a failed provider allowance lookup before transport, rather than credit exhaustion or successful email delivery.

Brevo's signed-in security page confirmed an unauthorized Render IP at the exact activation-creation time. The two current service outbound ranges were subsequently authorized; the previous six entries and unknown-IP API blocking were preserved. Free Render had also stopped its combined worker/scheduler processes while asleep. Health requests woke the service; current heartbeats recovered. Waking cannot revive an expired activation token.

The owner subsequently requested a clean launch with only the existing administrator, so the two other hosted accounts and old activation history are scheduled for backed-up deployment cleanup. No expired invitation will be replayed. Fresh account activation remains covered by isolated tests; a new controlled hosted receipt/link test requires an explicitly scoped recipient.

User Management previously omitted the delivery fields required by `statusLabel()`. As a result it could label retries/deferred messages as queued and a recorded hard bounce as provider accepted. The controller now loads the relevant metadata; the interface shows attempts, deferral timing, expired unsent invitations, missing activation records and safe recovery guidance. Synthetic regressions cover request, approval, one accepted transport, single-use setup, password creation, login and expired-link replacement.

## Exact source-file verification

All four owner-authorized original workbooks were opened read-only. Their hashes were checked before and after verification. Real data remained in isolated in-memory SQLite and a random private fake-storage adapter, with zero retained source files after teardown. No real records were imported into the owner's local application or hosted database, and no real certificate, approval, batch, email delivery or job was created.

An independent ZIP/XML decoder was compared against the application reader, HTTP preview/dry-check/confirmed import, encrypted stored originals, held originals and rendered HTML. Final source verification found zero mismatches across 7,133 source cells and 36,416 comparisons across the processing layers. Every accepted submission inspection page, every held-row detail and all 17 response-table pages were checked. All 612 timestamp cells, 250 formatted quiz scores and 300 registration cells with surrounding whitespace were included.

| File | Source responses | Accepted in isolated rehearsal | Held |
| --- | ---: | ---: | ---: |
| Registration | 238 | 205 | 33 |
| Pre-test | 127 | 84 | 43 |
| Post-test | 123 | 76 | 47 |
| Evaluation | 124 | 0 | Individual import blocked: no email/name column |

Registration holds comprise five invalid email rows, 27 duplicate rows and one identity conflict. Pre-test holds comprise nine duplicate rows and 34 unmatched rows. Post-test holds comprise 18 duplicate rows and 29 unmatched rows. These are source-data review requirements; no identity was guessed, merged by name/order, corrected silently or given fabricated eligibility. Anonymous evaluations cannot prove an individual's completion.

The original reader lost quiz denominators represented by Excel's `0" / 10"` number format. Numeric formatting now preserves these scores, zero scores, padded identifiers and exact decimal values. Unknown or lossy numeric formats produce a clear CSV-export instruction instead of silently changing values. The additional XML pass follows workbook relationships and enforces bounded rows, columns and total cells before allocation.

Original headers are retained. Response views/export display original source identity spelling and answer whitespace, while participant matching continues to use the canonical email key. Parsed timestamps/points are explicitly accompanied by source values. Choice analytics retain validated choice interpretation. Existing participant identities, historical responses and certificate records were not rewritten.

## Eligibility

All 205 accepted registration participants were checked against source-derived expectations in six isolated rule scenarios. Both PHP per-person evaluation and the SQL eligibility roster agreed, with 3,702 comparisons and zero discrepancies.

| Required evidence | Minimum points on both tests | Eligible |
| --- | ---: | ---: |
| Registration, pre-test, post-test | None | 68 |
| Registration, pre-test, post-test | 0 | 68 |
| Registration, pre-test, post-test | 8 | 60 |
| Registration, pre-test, post-test | 10 | 35 |
| Registration, pre-test, post-test, evaluation | None | 0 |
| Registration, pre-test, post-test, attendance | None | 0 |

These are rehearsal outcomes under the stated rules, not a replacement for the owner's existing roster or an instruction to alter real eligibility rules. The files contain no attributable evaluation evidence or attendance proof.

## Owner-directed authentication policy

The owner explicitly requested deployed MFA off and no existing-password reconfirmation on any action. This supersedes the historical mandatory-MFA/reconfirmation rules for this deployment. Login credentials, new-password confirmation, authorization, CSRF and audit remain applicable. Optional enrolled authenticators still require valid pairing/factor proof where relevant.

The deployed administrator's affected security state was backed up and verified privately. Enrollment was cleared, trusted devices revoked, authentication version incremented once and an attributable audit entry recorded; original password, APP_KEY, roles, ownership, other accounts and business data were preserved. Hosted `SECURITY_REQUIRE_ADMIN_TWO_FACTOR=false` and `SECURITY_REQUIRE_SENSITIVE_ACTION_PASSWORD_CONFIRMATION=false` were applied to the same existing build through Render's Save and deploy option, without rebuilding newer source. A restart alone would retain the old environment snapshot.

The newer source also makes direct account/security forms follow the password-reconfirmation policy and retains session revocation through the existing authentication-version field. Final review reproduced and fixed a concurrent-password-reset race: a verified MFA mutation now retains its exact authentication version instead of adopting a later version or rehashing a concurrently changed password. The regression passes with both reconfirmation policies. Legacy sessions predating a credential-version change may need one fresh sign-in. The Free deployment blueprint now records the owner's two false policy flags, selected sender name and public sender address; ordinary application security defaults remain unchanged.

## Certimate branding

The owner supplied the official Certimate artwork and chose the name as Certificate + Automate. Its background was extracted with the built-in image tool and the selected transparent result was trimmed and resized into lossless WebP, PNG fallbacks, an icon-only multi-size ICO, 16/32-pixel favicons and 180/192/512-pixel application icons. The original supplied file and its hash were preserved. The interface assets have genuine transparency, removing the white rectangle in light mode. Browser checks verify transparent image corners, loaded artwork, viewport fit and no horizontal overflow at 320, 390, 768 and 1280 pixels in both themes; mobile light and desktop dark screenshots were visually reviewed. The iOS home-screen icon has an intentional opaque backing for that platform. Existing colors, layouts and custom webinar certificate-email presentation remain.

A dedicated branding configuration changes display labels without changing APP_NAME-derived session/cache infrastructure. Shared logo and metadata views cover navigation, authentication, public forms, verification, error pages and Studio. Account/shared email copy uses Certimate; explicit custom sender names remain honored.

The owner selected the email sender display name `Webinar Platform Communication` and the new public sender `webinarplatformcommunication@gmail.com`. Brevo read-back confirmed that sender active after the owner completed verification; the existing API connection and Free tier remain. Local MAIL_FROM_ADDRESS and the deployment template now use the public mailbox, with the final hosted setting included in the operator release. Ordinary account/shared email bodies do not automatically print the administrator's address and no explicit personal Reply-To is configured. The From address remains visible in protocol headers, so it must be the approved public mailbox. No mailbox password, new SMTP credentials, paid plan or new API key was needed.

Publication privacy review scanned 552 files against 248 privately derived source/personal email needles and 281 raw/trimmed full-name variants. Zero participant/personal-email or full-name matches remained after correcting the historical Free blueprint. The approved public mailbox was explicitly allowed. A separate known-secret scan also found no matching secrets or private paths in publication scope.

The owner selected `certimate.johnchristiann.com`. Its Cloudflare CNAME points to the existing Render service with DNS-only routing; the existing apex, www and stash records were preserved. At source freeze Render's Custom Domains panel was still loading, so Render attachment/TLS was not yet confirmed. Keep the working Render canonical APP_URL until that verification succeeds, and retain both exact hosts in APP_TRUSTED_HOSTS. DNS alone does not complete domain attachment.

## Final verification and release decision

The final full integration pass completed 706 PHP tests / 7,963 assertions, with the opt-in real-file test skipped in that general run. Full Pint and the production Vite build passed in the required order. The 16 JavaScript tests and 33 static Chromium scenarios also passed. Composer metadata is valid and locked Composer/npm dependency audits reported zero advisories. The real-file opt-in test separately passed 9,241 assertions. All 24 real-HTTP workflow scenarios have passing evidence: 22 in the last full run, plus successful targeted reruns of the 220-navigation responsive check and the four-form import/Studio scenario after increasing their overall time budgets. No assertions were removed. The Studio style scenarios now use dedicated synthetic webinars rather than depending on earlier roster state. The final concurrent-reset fix separately passed 73 focused account/security tests before the full 706-test pass.

Synthetic PostgreSQL 17.11 rehearsal passed 114 checks across 722 guarded queries, including all 43 migrations in a disposable private schema, exact source preservation, quiz scores, held conflicts, matching PHP/SQL eligibility and account token expiry. The private schema was removed and all 14 important public business-table counts were unchanged. No original XLSX data was uploaded to PostgreSQL.

Brevo remains Free. The shared release budget is 250 messages/day, with a hard application ceiling of 300 and a 30/minute allowance. Account, participant and certificate emails share that allowance. Provider acceptance, recipient-server delivery and actual inbox receipt are separate outcomes.

Brevo's actual blocked-origin configuration has been repaired. Actual Render-origin provider acceptance and a fresh received-mailbox activation/link exercise remain unverified. The general participant-access provider was still configured as log at the read-only audit. Free Render sleep prevents a continuous-background-processing guarantee. The built-in production checker retains stricter Redis/backup/logging/MFA requirements, some intentionally outside the owner's selected Free/staging profile.

The clean-launch preparation captured and verified an encrypted snapshot of all 36 hosted tables, schema metadata, current administrator/security state and matching recovery key. Private S3 and database storage catalogs both confirmed zero objects in the private dedicated bucket. The child-first cleanup rehearsal preserves the original administrator byte for byte and all 43 migration records; all previous business/account-request/mail/token/audit/session data is removed from the proposed launch state. Actual cleanup has not run yet. Local real data, files and certificates must never be copied into this launch.

Source verification is complete for the owner-authorized Free-profile release, and the public sender is verified. The final operational gate is the pinned release becoming healthy, followed by the fresh quiesced backup/rehearsal/cleanup/resume checks. Strict production-profile requirements and actual inbox/Render-origin transport remain separate limitations described above. No actual-file production import or real certificate send is implied by this report.

Aggregate-only private proof: `storage/framework/testing/real-response-accuracy-proof.json`. Its held-row entries identify original worksheet row numbers and issue categories, without names, emails or answer values.
