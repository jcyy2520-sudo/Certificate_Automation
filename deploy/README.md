# Deployment repository artifacts

The sole authoritative production procedure is [`../DEPLOYMENT_CHECKLIST.md`](../DEPLOYMENT_CHECKLIST.md). This directory contains reviewed templates and bounded helpers for the named phases of that runbook; it is not a second deployment path.

| Artifact | Used in | Purpose |
| --- | --- | --- |
| `apache/webinar-platform.conf.example` | Phase 8 | Dedicated Apache/PHP 8.3-FPM VirtualHost template. |
| `systemd/` | Phase 7 | Default certificate worker, email workers, scheduler, and target units. |
| `verify-restored-postgres.sh` | Phase 3 | Protected-host verifier for a provider-restored disposable PostgreSQL database. |
| `load/README.md` and `load/public-form-capacity.js` | Phase 12 | Isolated staging-only capacity procedure. |

These artifacts do not authorize a live deployment. Do not alter existing Apache sites, start systemd units, provision PostgreSQL/Redis, contact AWS/Cloudflare, send Brevo mail, or run production migrations from repository validation.
