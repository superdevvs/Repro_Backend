# Production QA Fixes — Deploy & Configuration Note

This note documents the operational steps and configuration keys introduced by the
`production-qa-fixes` work. It covers the production deploy/cache-refresh procedure that
restores the voice routes, the configurable QA outbound test number, and the Telnyx
messaging environment keys (including the external toll-free verification task).

The voice routes (`voice/schedule/state`, `voice/llm-usage`) and the `messages.failed_at`
column already exist in the repository. The production 404s and the SMS SQL 500 were
caused by **production schema and route/config cache drift**, not by missing source. The
deploy procedure below rebuilds the caches so production matches the repository, and the
SMS path degrades gracefully (clean 4xx, never a 500) while a Telnyx number is unverified.

---

## 1. Deploy & cache-refresh procedure (restores the voice routes)

Use the **Backend desktop shortcut** for production releases. The tracked
`backend/deploy.sh` legacy entry point is intentionally disabled so it cannot bypass the
validated deployment workflow. The shortcut runs local and hosted quality gates, creates
verified rollback backups, applies migrations, rebuilds config/route/view caches, restarts
the queue worker, and verifies application health.

| Step | Command | Purpose |
|------|---------|---------|
| 1 | `php artisan migrate --force` | Applies pending migrations in production, including the idempotent `messages.failed_at` schema-guard migration. |
| 2 | `php artisan config:cache` | Rebuilds the config cache so new keys (`services.qa.outbound_test_number`, Telnyx env) are picked up. |
| 3 | `php artisan route:cache` | Rebuilds the route cache so the voice routes register and stop returning 404. |
| 4 | `php artisan view:cache` | Rebuilds compiled Blade views. |
| 5 | `php artisan queue:restart` | Restarts queue workers so they run against the new code. |
| 6 | Health and worker verification | Confirms the queue worker actually restarted, no migrations remain pending, the schedule loads, and the TLS-verified `/up` endpoint returns HTTP 200. |

### Post-deploy voice-route verification (assert HTTP 200)

After the caches rebuild, confirm both voice routes are registered and reachable. A
non-200 means the cache refresh did not take — re-run the cache-refresh steps (2–6) and
re-verify.

1. Confirm the routes are registered:

   ```bash
   php artisan route:list | grep 'voice/schedule/state'
   php artisan route:list | grep 'voice/llm-usage'
   ```

2. Probe each route with an authenticated request carrying the `voice-calls` permission
   and assert an **HTTP 200** response:

   - `GET voice/schedule/state` → `200`
   - `GET voice/llm-usage` → `200`

   Example (substitute a valid bearer token for a `voice-calls`-permissioned user):

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' \
     -H "Authorization: Bearer <token>" \
     https://api.reprodashboard.com/api/voice/schedule/state
   ```

   A `200` confirms the route cache rebuild succeeded. A `404` indicates the route cache is
   still stale — re-run `php artisan route:cache` (and the remaining cache steps) and probe
   again.

---

## 2. QA outbound test number configuration

The outbound destination number used by QA/test scripts is sourced from configuration
rather than a hardcoded literal.

| Item | Value |
|------|-------|
| Environment variable | `QA_OUTBOUND_TEST_NUMBER` |
| Config path | `config('services.qa.outbound_test_number')` |
| Documented valid default | `+12025550100` |

- Consumers (QA test scripts and tests) MUST read `config('services.qa.outbound_test_number')`
  instead of embedding a literal number.
- When `QA_OUTBOUND_TEST_NUMBER` is **unset**, the backend falls back to the documented
  valid default `+12025550100` — a valid E.164-formatted North American number in the
  reserved `555-01xx` test range.
- Override per environment by setting `QA_OUTBOUND_TEST_NUMBER` in `.env` to a valid, owned
  E.164 number, then re-run `php artisan config:cache`.

Example `.env` entry:

```dotenv
QA_OUTBOUND_TEST_NUMBER=+12025550100
```

---

## 3. Telnyx messaging environment keys

The Telnyx SMS sender is configured through the following environment keys (all read in
`backend/config/services.php` under the `telnyx` block):

| Environment key | Config path | Purpose |
|-----------------|-------------|---------|
| `TELNYX_FROM_NUMBER` | `config('services.telnyx.from_number')` | The sending (from) number used for outbound SMS. |
| `TELNYX_MESSAGING_PROFILE_ID` | `config('services.telnyx.messaging_profile_id')` | The Telnyx messaging profile that owns the sending number. |
| `TELNYX_PHONE_NUMBER_ID` | `config('services.telnyx.phone_number_id')` | Optional default Telnyx `phone_number` id (UUID-like string from `/v2/phone_numbers`). |

After changing any of these in `.env`, re-run `php artisan config:cache` so the cached
config picks up the new values.

### Toll-free number verification is an EXTERNAL Telnyx account task

Toll-free number verification is performed in the **Telnyx account/portal**, not in this
codebase. It is an external operational task and is **out of scope for code execution**.

Until the sending number is verified:

- SMS sends **degrade gracefully** via the Requirement 2 handling. A send against an
  unverified number returns a **clean 4xx (HTTP 422)** structured error
  (`{ "success": false, "error": "sms_send_failed", "message": ... }`) and **never an
  unhandled HTTP 500**.
- When the provider error indicates an unverified/toll-free condition, the response message
  states that the Telnyx sending number is not verified.
- The failure is recorded on `messages.failed_at` (status `FAILED`); if that failure-write
  itself errors due to schema drift, it is logged and swallowed so it cannot escalate into
  a 500.

Once the toll-free number is verified in the Telnyx account, SMS sends proceed normally
with no code change required.

---

## Requirement traceability

- **Req 3.4** — documents the deploy and cache-refresh steps required to restore the voice
  routes in production (Section 1), including the post-deploy voice-route 200 verification.
- **Req 4.4** — documents the `QA_OUTBOUND_TEST_NUMBER` configuration key and its valid
  default value `+12025550100` (Section 2).
