# Outbound SMS pause / resume (Telnyx)

## Storm fix (2026-09-30)

- Failed schedule attempts (including Telnyx 409 Invalid destination region) count in
  `ScheduledAutomationDispatcher::alreadyDispatched` — **no every-minute re-blast**.
- Property-contact / invoice reminder cadence is `dailyAt` (not `everyMinute`).
- FAILED SMS rows stay visible (`hidden_from_inbox=false`) with staff-facing `error_message`;
  storm automation retries may be soft-hidden after cleanup.
- Messaging overview counts SMS failures (`total_failed_today`, `sms_failed_today`).

## Ops pause while deploying

- `.env`: `TELNYX_SMS_PAUSED=true`
- Cache: `Cache::forever('ops.telnyx_sms_paused', true)`
- PROPERTY_CONTACT_REMINDER rules 5–10: `is_active=0` until resume

## Safe resume (after durable SHA is on `/var/www/backend`)

1. Confirm deploy-meta commit matches the dedupe SHA.
2. Clear pause:
   ```bash
   cd /var/www/backend
   # set TELNYX_SMS_PAUSED=false (or remove) in .env
   php artisan tinker --execute="Cache::forget('ops.telnyx_sms_paused');"
   php artisan optimize:clear && php artisan config:cache && php artisan queue:restart
   ```
3. Re-enable PROPERTY_CONTACT_REMINDER rules (email+SMS) with **daily** cadence already
   in bootstrap (`dailyAt('09:00')`). Do **not** restore `everyMinute`.
4. Watch `messages` / `automation_runs` for several minutes — no new PC SMS storm.
5. Canada / invalid-region numbers still fail **once** and stay failed (visible in Messages).
