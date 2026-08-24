# Public form capacity test

Use this only against an isolated staging environment configured like production: managed PostgreSQL, Redis sessions/cache/queues, the intended PHP-FPM/web replica count, and production-size form definitions. Never load-test the public production URL.

Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/), create a dedicated published webinar, and choose one mode:

- `access` tests the default secure flow up to durable creation and queueing of verification emails. Use a staging mail sink or mock transactional provider; do not send load-test mail through Brevo or to invented addresses.
- `submit` tests durable form submissions. It requires a dedicated staging webinar with verification disabled and a form containing representative fields/questions.

Start small and ramp only while every threshold and infrastructure metric stays healthy:

```bash
FORM_URL=https://staging.example/f/replace-with-test-token MODE=access RATE=25 DURATION=2m k6 run deploy/load/public-form-capacity.js
FORM_URL=https://staging.example/f/replace-with-test-token MODE=access RATE=50 DURATION=2m k6 run deploy/load/public-form-capacity.js
FORM_URL=https://staging.example/f/replace-with-test-token MODE=access RATE=100 DURATION=2m k6 run deploy/load/public-form-capacity.js
```

Run from enough source IPs, or use a controlled edge bypass available only to the load-test environment, so intentional per-IP abuse limits do not turn the exercise into a rate-limiter test. Never trust a client-supplied header to provide that bypass.

## Pass criteria

- Less than 1% unexpected HTTP failures; no HTTP 500 responses.
- No dropped iterations.
- p95 below 1 second and p99 below 2 seconds at the required arrival rate.
- PostgreSQL has no sustained lock waits, connection exhaustion, replica lag, or CPU saturation.
- Redis has no evictions, connection exhaustion, or sustained latency.
- Application CPU, memory, and PHP-FPM queues stay below alert thresholds.
- Email queue age returns to zero after the burst; no failed jobs or stale pending deliveries remain.
- The staging mail sink receives exactly one queued message per accepted unique access request.

HTTP 429 responses are expected only when intentionally testing configured abuse limits. Stop the test on database errors, Redis errors, rising queue age that does not recover, or any privacy/identity mismatch.

Brevo should be tested separately with a small number of authorized recipient addresses. The application uses idempotency keys and queue retries; provider throughput and account credits must be monitored without generating unsolicited mail.
