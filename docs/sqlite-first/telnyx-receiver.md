# Telnyx texts during maintenance, and the scheduler hold

Operator note. Ships while production is still on SQLite. Both features are
off until their switch is turned on in `.env`.

## What it does

**Telnyx receiver.** While the app is in maintenance mode (every deploy, and
the cutover freeze), Telnyx text webhooks used to get 503. Telnyx gives up
after a few quick retries, so texts were lost. With the receiver on:

| Route | During maintenance |
| --- | --- |
| `POST /api/webhooks/telnyx/messaging` | Saved, answered 200, replayed later |
| `POST /api/webhooks/telnyx/status` | Saved, answered 200, replayed later |
| `POST /api/webhooks/telnyx/shared-mms/{uuid}` | Saved, answered 200, replayed later |
| `POST /api/webhooks/telnyx/voice` | Saved for the missed-call list, still 503, never replayed |
| `POST /api/webhooks/vapi` | Same as voice, and only when `VAPI_SERVER_SECRET` is set and matches |
| Everything else, including `/api/telnyx-ai/tools/*` | Normal 503 |

The receiver is the first global middleware, so it runs before the
maintenance check and before the API group's telemetry. It checks the Ed25519
signature and the 300-second freshness on arrival. It writes the exact body,
the two signature headers and the arrival time to an encrypted file, flushes
it to disk, and only then answers 200. It never touches the database. If
anything fails (bad signature, old timestamp, body over 1 MB, disk error), the
request gets the normal 503 and Telnyx retries as before.

Voice and Vapi keep their 503 on purpose, so any provider retry or failover
behaves exactly as today.

**Replay.** `php artisan telnyx:replay-spool` sends the saved texts through the
normal handlers, oldest first, with the saved arrival time (used for
`sent_at`, `delivered_at`, `failed_at` and the thread's last message time,
and as `created_at` of a replayed incoming text, which clients show as its
time and sort by). A
replayed text never moves a thread's last message back past a newer one. It
checks each saved signature again. Each Telnyx event id, and each inbound
message id, is handled once, including messages that already reached the app
live. Forged or damaged files go to `rejected/`. A file that fails stays for
the next run. It prints counts only, never message text or numbers.

**Missed calls.** `php artisan telnyx:missed-calls` lists one row per call
that reached the app during maintenance: first seen (Eastern time),
provider, from, to, and the events seen.

**Scheduler hold.** `schedule:run` skips every task named in
`scheduler-hold.<owner>.json` in the hold folder (`storage/framework` unless
`SCHEDULER_HOLD_DIRECTORY` says otherwise). `"*"` means all tasks. Each
owner has its own file, so a deploy never clears a cutover hold. Every hold
ends by itself at `expires_at`, and never later than 12 hours after its file
was written. A broken file holds nothing.

## During a deploy

`scripts/deploy/Deploy-App.ps1` now does this on the server:

1. Asks the app for its hold folder, writes the `deploy` hold there (all
   tasks, 30 minutes), then `php artisan down --retry=60`.
2. Copies code, runs Composer, migrations and caches, as before.
3. Last step before `up`: `telnyx:replay-spool`, with umask 002 in the shell
   that already runs as the `www-data` group.
4. `php artisan up`, a second replay for texts that arrived meanwhile, then
   removes the `deploy` hold.

Every replay first waits (up to 30 seconds) for any capture that is still
saving. Each capture holds a shared lock on `.capture.lock` in the spool
folder while it saves, and checks maintenance mode again once it has the
lock. So the replay after `up` sees every text that was answered 200, and a
text that reaches the receiver after that finds the app up and is handled
live. If a capture is still saving after 30 seconds, the replay still runs
but reports failure (`telnyx_spool_replay=failed` in a deploy); run it again.

If the deploy fails, the cleanup step replays, runs `up`, replays again, and
removes the hold. A hold or replay problem prints `scheduler_hold=unavailable` or
`telnyx_spool_replay=failed` and never fails the deploy.

Never use `php artisan down --render`. A prerendered page answers before
Laravel starts, so the receiver cannot run.

## Switches

| `.env` key | Default | Meaning |
| --- | --- | --- |
| `TELNYX_MAINTENANCE_CAPTURE_ENABLED` | `false` | Turns the receiver on |
| `SCHEDULER_HOLD_ENABLED` | `false` | Makes `schedule:run` honour hold files |
| `TELNYX_MAINTENANCE_SPOOL_PATH` | `storage/app/telnyx-spool` | Spool folder, outside any web or storage disk root. Use an absolute path: PHP-FPM and the deploy run from different folders |
| `TELNYX_SPOOL_REPLAY_GROUP` | `www-data` | The replay refuses to run under another group. Empty turns the check off |
| `TELNYX_SPOOL_KEEP_DAYS` | `14` | Replayed, rejected and call files are deleted this many days after they were saved or moved |
| `SCHEDULER_HOLD_DIRECTORY` | `storage/framework` | Where hold files live; a relative path is inside the app folder. The deploy asks the app for this folder (`php artisan scheduler:hold path`), so its hold lands where the scheduler reads |
| `SCHEDULER_HOLD_MAX_MINUTES` | `720` | Longest any hold can last |

Turning a switch on in production is a config change and needs approval.
Run `php artisan config:cache` after the change.

## Spool folder

PHP-FPM creates it on the first capture: `texts/`, `calls/`, `replayed/` and
`rejected/`, all mode 2770, files 0640. The deploy user reads and moves them
through the shared `www-data` group. `storage/app` must be writable by
`www-data`. Files are encrypted with the app key; keep the key in the
recovery kit.

## By hand

```bash
cd /var/www/backend
sg www-data -c 'umask 0002; php artisan telnyx:replay-spool --dry-run'
sg www-data -c 'umask 0002; php artisan telnyx:replay-spool'
php artisan telnyx:missed-calls
php artisan scheduler:hold status
php artisan scheduler:hold path      # the folder the scheduler reads
php artisan scheduler:hold set cutover --minutes=240 --reason="cutover freeze"
php artisan scheduler:hold set nightly --only=model:prune --only=crm:nightly --minutes=720
php artisan scheduler:hold release cutover
```

After any manual `php artisan up`, or a deploy that failed and rolled back to
code without the replay command, run the replay by hand. Texts wait in
`texts/` until something replays them.

## Limits

- Laravel must start to capture. If a deploy breaks start-up, Telnyx sees an
  error and retries, as before.
- The 200 is sent after the file is on the local disk. There is no off-server
  copy first.
- A replayed text that arrived more than 10 minutes before the replay gets
  no Groundwire phone push: the push service skips texts that old. It still
  shows as unread in the inbox.
- Appointment reminders look back five minutes. A hold or maintenance window
  longer than that can still skip a reminder.
- Cross-check a long window against Telnyx's own failed-delivery list.

## Tests

```bash
php vendor/bin/phpunit tests/Feature/TelnyxMaintenanceCaptureTest.php \
  tests/Feature/TelnyxSpoolReplayTest.php tests/Feature/TelnyxWebhookArrivalTimeTest.php \
  tests/Feature/SchedulerHoldTest.php
python3 scripts/deploy/test_deploy_maintenance_window.py   # Linux only
```
