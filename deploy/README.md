# Deployment repository artifacts

The sole authoritative production procedure is [`../DEPLOYMENT_CHECKLIST.md`](../DEPLOYMENT_CHECKLIST.md). This directory contains reviewed templates and bounded helpers for the named phases of that runbook; it is not a second deployment path.

| Artifact | Used in | Purpose |
| --- | --- | --- |
| `render/FREE_DEPLOYMENT.md` and `../render.free.yaml` | Separate empty demonstration | Owner-authorized $0 Render/Supabase profile; does not clear production or real-data gates. |
| `apache/webinar-platform.conf.example` | Phase 8 | Dedicated Apache/PHP 8.3-FPM VirtualHost template. |
| `systemd/` | Phase 7 | Default certificate worker, email workers, scheduler, and target units. |
| `verify-restored-postgres.sh` | Phase 3 | Protected-host verifier for a provider-restored disposable PostgreSQL database. |
| `load/README.md` and `load/public-form-capacity.js` | Phase 12 | Isolated staging-only capacity procedure. |
| `ec2/PREPARATION.md` and `ec2/` templates | Selected profile for existing phases | Single-node credit-only EC2 plan, secret-free runtime, one-email-worker target and candidate PHP/Redis settings. |
| `prepare-ec2-package.py` | Phase 1 | Creates/verifies an allowlisted current-source/built-assets ZIP without data, secrets or local runtime state. |

These artifacts do not authorize a live deployment. Do not alter existing Apache sites, start systemd units, provision PostgreSQL/Redis, contact AWS/Cloudflare, send Brevo mail, or run production migrations from repository validation.
