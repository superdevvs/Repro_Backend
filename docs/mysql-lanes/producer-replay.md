# Producer replay lane (P2-03, P2-04, P2-06, P2-07, P2-08, P2-10, P2-11, P2-12)

Status: local code and offline tests only. Nothing here has run against
production, the live website, Supabase or any provider. Every flag below is
off by default. Fold the runbook and evidence sections into
`docs/mysql-production-cutover.md` ("CRM freeze and replay") and
`docs/mysql-production-readiness.md`; this file avoids editing them so the
lanes do not conflict.

Branch `claude/producer-replay-20261125`, based on `a6e799bb` (draft PR 46).

## What changed

| Item | Change | Main tests |
| --- | --- | --- |
| P2-03 | `recruit_application_receipts` gets `payload_sha256` and `attachment_sha256s` (new migration `2026_11_25_140000`). The first use of a reference records a SHA-256 of the canonical validated fields (files and empty values left out, keys sorted, scalars as text) and the ordered `kind:sha256` list of attachments. A replay with other fields, other bytes, missing files, or against a receipt from before this change returns **409** `{received:false, error:"reference_conflict"}`, stores no file and sends recruiters a `recruit_reference_conflict` notice (no applicant data in it). Exact replays still return the stored applicant. | `tests/Feature/Crm/RecruitingReplayIntegrityTest.php`, `tests/MySql/RecruitingReplayConcurrencyTest.php` |
| P2-04 | The careers route uses `crm.enabled:retry`: CRM off gives **503 + Retry-After**, not 404. Staff CRM routes still give 404. Website intake receipts are always registered, even with CRM off; `crm:website-intake` replays them once the CRM is back. | `tests/Feature/Crm/ProducerIngressHoldTest.php` |
| P2-04, P2-07 | `PRODUCER_INGRESS_HOLD` answers the producer routes with 503 + Retry-After, first in the global middleware stack (before telemetry, maintenance and controllers). Resend `email.received` with CRM off now gives 503, never 200 "ignored". A reviewed nginx snippet holds the same webhooks at the edge, and all of `/api/external/` (see the edge note under owner decisions). | `ProducerIngressHoldTest.php`, `tests/Feature/Crm/ResendRedeliveryTest.php`, `scripts/cutover/fence/test_producer_hold.py` |
| P2-06 | `scripts/cutover/website_ledger.py` turns a read-only website export into the capture ledger, a private CSV and a references file. `php artisan crm:website-ledger` reports the Dashboard side for each reference, read-only against the database it is given, and lists website bookings saved without a reference (another source's `shoot-<id>` receipt is not one). | `scripts/cutover/test_website_ledger.py`, `tests/Feature/Crm/CrmWebsiteLedgerTest.php` |
| P2-08 | `php artisan crm:calendar-reconcile --dry-run` compares every linked follow-up with Google, inside and outside the poll window, and lists a pending create Google never confirmed (`unconfirmed_create`). Done or missed follow-ups (`closed_locally`) and links without a Google connection (`no_connection`) are listed but do not fail it, because the poller never acts on them. | `tests/Feature/Crm/CrmCalendarReconcileTest.php` |
| P2-10 | `php artisan stripe:undelivered-events` lists events with pending deliveries, using a restricted read key. Tests prove a payment event sent 5 times gives one payment. | `tests/Feature/StripeReplayAfterFreezeTest.php` |
| P2-11 | `aryeo:connection` gains `--record-state=<file> --pause` and `--resume-processing=<file>`. The pause stops job claims; the resume refuses a connection changed since the recording. The Aryeo API webhook is held with the other producers. | `tests/Feature/AryeoFreezeResumeTest.php` |
| P2-12 | `CRM_OUTBOUND_HOLD` stops drips, campaigns, the follow-up digest and marketing sends, while replies, delivery events, opt-outs and website intake keep working. `crm:outbound-release-check` gates the release; `crm:send-disposition` records the review of an uncertain send. | `tests/Feature/Crm/CrmOutboundHoldTest.php` |

## Flags and settings

All default to off or empty. Set them only inside an approved window, then
rebuild the config cache.

| Setting | Default | Effect |
| --- | --- | --- |
| `PRODUCER_INGRESS_HOLD` | `false` | 503 + `Retry-After` for `api/external/recruiting/application`, `api/external/website-email`, `api/external/book-shoot`, `api/webhooks/resend`, `api/webhooks/stripe`, `api/webhooks/stripe/listing-studio`, `api/integrations/aryeo/webhooks/*`. Reads such as `/api/external/services` stay open. Telnyx is not held here (its own lane owns the capture route). |
| `PRODUCER_INGRESS_RETRY_AFTER` | `60` | Seconds in the `Retry-After` header. |
| `CRM_OUTBOUND_HOLD` | `false` | `crm:send-due` claims nothing, `crm:follow-up-digest` sends nothing, and any marketing send throws `CrmSendBlocked('outbound_hold')`. Transactional sends (reply forwards, quotes, one-off non-marketing) are not blocked. |
| `CRM_CALENDAR_SYNC_HOLD` | `false` | Added after this lane (calendar fix). The scheduler skips `crm:calendar-sync`, and a manual run exits 0 before it touches Google or the database. A save in the CRM still pushes that one follow-up at once. |
| `STRIPE_EVENTS_READ_KEY` | empty | Restricted Stripe key with Events read only, for `stripe:undelivered-events`. Never the secret key. |
| `LISTING_STUDIO_STRIPE_EVENTS_READ_KEY` | empty | The same for the Listing Studio account (`--account=listing-studio`). |

Behaviour that changes without a flag once deployed (all safe on SQLite, and
production has `CRM_ENABLED=true` today, so the CRM-off branches do not run):

- A divergent replay of a website application reference gets 409 instead of
  a silent 201. The website routes 409 to staff review.
- With `CRM_ENABLED=false`: careers gets 503 instead of 404; Resend
  `email.received` gets 503 instead of 200 "ignored"; website contact and
  booking receipts are still saved (encrypted) and replayed after the switch.
- New migration on `recruit_application_receipts` (two nullable columns).

## Runbook: freeze and replay (fold into "CRM freeze and replay")

Superseded: this lane text is folded into "CRM freeze and replay" in
[the cutover runbook](../mysql-production-cutover.md#crm-freeze-and-replay),
which is corrected against the code and the provider docs. Use the runbook
on the night; this section stays as the lane's record.

Record the freeze start time in UTC as `FREEZE_AT`. Each step that changes
production needs its own approval.

**Before the freeze**

1. Record and pause the Aryeo agent:
   `php artisan aryeo:connection "<name>" --record-state=/private/aryeo-<date>.json --pause`.
   The file is new, mode 0600, holds no secret, and records `enabled`,
   `processing_enabled`, `discover_all_clients`, `delivery_shoot_ids` and
   `client_ids` with a digest. The pause turns off job claims (so no Aryeo
   action starts) and email-less discovery. Like `--disable-processing`, it
   leaves request sync on: heartbeats, discovery with a requester email,
   inventory and provider status still write. Those are re-sent by the agent
   and are safe before the copy; during the freeze itself the writer freeze
   (admission closed before Laravel) stops every HTTP write, these included.
   Then check that no Aryeo job is still leased (Tours panel) before the
   freeze.
2. Set `CRM_OUTBOUND_HOLD=true`, `CRM_DEFER_REPLY_FORWARDING=true` and
   `CRM_CALENDAR_SYNC_HOLD=true`, rebuild the config cache, and confirm the
   three booleans in the effective config. Keep `CRM_CALENDAR_SYNC_HOLD` in
   the environment that reopens; step 10 releases it.
3. Hold the website recruiting scheduler (website change P2-05 below). Do not
   turn off `recruiting_forwarding_settings.enabled`: that stops capture.

**Freeze**

4. Hold producers at the edge: copy
   `scripts/cutover/fence/producer-hold.nginx.conf` into
   `/etc/nginx/repro-holds/`, run `nginx -t`, reload. Each Dashboard server
   block (`frontend.conf` and `laravel.conf`) must already carry
   `include /etc/nginx/repro-holds/*.conf;` once. Optionally also set
   `PRODUCER_INGRESS_HOLD=true` as the in-app second layer. Never use
   `CRM_ENABLED=false` as the freeze control; it is now safe for producers
   but it also stops reply processing and intake processing.
5. Check that every held path answers 503 with `Retry-After` (never 403, 404
   or a redirect; the website treats 3xx and 4xx other than 408/425/429 as
   permanent).

**Release, in this order**

6. Remove the nginx hold file, `nginx -t`, reload; set
   `PRODUCER_INGRESS_HOLD=false`. The website outbox, booking forwarder,
   Resend and Stripe retry on their own. Aryeo stops retrying after about
   2 minutes, so events of the hold are lost (runbook provider table).
7. Website ledger. Export the website rows read-only (export spec below) and
   run, from the repository root,
   `python3 -B -m scripts.cutover.website_ledger --export <export.json> --capture <capture.json> --private-csv <private.csv> --references <refs.json>`
   (the three output paths must be new).
   Then `php artisan crm:website-ledger --sqlite=<copy> --references=<refs.json> --json`,
   where `<copy>` is a fresh online backup of the live SQLite file.
   `config/database.php` defines no read-only connection, so
   `--connection` has nothing safe to name. Replay only through the same reference:
   `not_in_dashboard` bookings through forward-booking-v2 with the frozen
   quote; `not_received` and `not_delivered` contacts through website-email
   with the same reference; `uncertain` contacts only after the provider log
   shows the outcome; `submission_without_shoot`, `other_source_only` (the
   reference belongs only to another integration's booking; bookings match
   on source `reprophotos.com` and the reference) and every
   `manual_reconciliation` entry by a person. An unreadable `--since` exits 2.
   Each replay POST needs
   approval per reference. Compare the capture and replay ledgers with
   `verify_website_replay`.
8. Resend. In the Resend dashboard, replay the failed messages of the window
   (approval per batch). The webhook accepts a fresh signature with the
   original `svix-id` and applies it once; a stale signature is refused.
   Then set `CRM_DEFER_REPLY_FORWARDING=false` and request the replay of the
   recorded replies, so each is forwarded once
   (`crm-reply-fwd-{send}-{md5(email_id)}`). Forwards are transactional and
   are not blocked by the outbound hold.
9. `php artisan crm:outbound-release-check --since=$FREEZE_AT`. It is
   read-only and exits 1 while any of these is non-zero: replies not yet
   forwarded; permanent bounces or complaints without a suppression;
   uncertain or queued sends without a reviewed disposition; due drip steps
   that would reach someone who replied, bounced or opted out. For each
   uncertain send, check the provider log and run
   `php artisan crm:send-disposition <send-id> delivered|not_delivered|resend_approved|abandoned --note="..."`.
   A reply counts as forwarded only while its forward send (`reply_fwd`) has
   status `sent`. A forward that failed, was blocked, was only logged or is
   still queued stays blocking until you check that the rep has the reply
   (Conversations shows it) and record a disposition on the forward send. A
   reply with no forward send at all (no rep, or no valid address) takes the
   disposition on the send it answered.
   When it exits 0, set `CRM_OUTBOUND_HOLD=false`.
10. `php artisan crm:calendar-reconcile --dry-run --json`. It reads Google
    and writes nothing; connections whose token needs a refresh are listed as
    `token_refresh_needed` (add `--allow-token-refresh` only if approved, as a
    refresh saves the new token). Decide each `moved_in_google`,
    `deleted_in_google`, `push_pending` (a pending push overwrites Google),
    `unconfirmed_create`, `cancelled_still_in_google`, `remote_without_time`
    and `error` row. `unconfirmed_create` is a pending push with no Google id:
    an earlier create may have reached Google with its response lost, and the
    release then patches that event with the CRM time (a planned follow-up)
    or leaves it in Google (a cancelled one). Look in the rep's calendar at
    `local_starts_at` and match the CRM to Google before release. It
    exits 1 until none is left. `closed_locally` (done or missed follow-ups,
    which the sync leaves in Google on purpose) and `no_connection` (the rep
    disconnected Google) are listed for information and do not fail it.
    When it exits 0, set `CRM_CALENDAR_SYNC_HOLD=false` and rebuild the
    config cache. A planned follow-up that shows `push_pending` clears when
    it is saved again in the CRM. A cancelled one clears only when the sync
    runs (the CRM refuses to edit a follow-up that is not planned): settle
    every other row, release `CRM_CALENDAR_SYNC_HOLD`, let one sync run,
    then rerun `crm:calendar-reconcile --dry-run`, which must exit 0.
11. Stripe at +30 minutes and +2 hours:
    `php artisan stripe:undelivered-events --since=$FREEZE_AT --json` and the
    same with `--account=listing-studio`. Exit 1 means something is still
    pending; resend it from the Stripe dashboard with approval. A repeated
    event is harmless (tested 5 times on both endpoints).
12. Resume Aryeo last:
    `php artisan aryeo:connection "<name>" --resume-processing=/private/aryeo-<date>.json`.
    It refuses a file for another connection or an edited file, prints what
    it changed, and the agent should be seen again within a minute. It turns
    back on only what the pause turned off: if anything else changed after
    the recording (a credential revoked, client or shoot scope narrowed), it
    refuses, names the settings and changes nothing. Settle those by hand.

**Provider table rows to add**

| Provider | During the freeze | Afterwards |
| --- | --- | --- |
| Aryeo API webhook (`/api/integrations/aryeo/webhooks/{installation}`) | Held with 503 + Retry-After. Signatures expire after 300 s, so only a fresh delivery from Aryeo can be accepted. Deduped by `event_id` with a body hash. | Aryeo retries or redelivers; same event gives one `aryeo_api_webhooks` row and one `ReconcileAryeoWebhook` job (tested). **Aryeo's retry window is not confirmed**: ask Aryeo or read their docs before a real freeze. |
| Shared Resend webhook | Held with 503 + Retry-After (edge and in-app). | Dashboard replay under the original `svix-id`. **Not verified on Resend yet**: that a dashboard replay keeps the `svix-id`, re-signs with a fresh timestamp, and the retry schedule covers a 60-minute hold. |

## Website change spec (not done in this lane)

These changes belong to the website repo (Lovable project "RE Pro Photos",
reviewed at `4011d53a`). Publishing them and applying the Supabase migration
are production writes that need approval.

### P2-02: snapshot the recruiting payload at capture, and keep it on delete

Files: `supabase/pending/20261124150000_recruiting_outbox.sql` (or a new
migration applied after it), `src/lib/recruiting/recruitingOutbox.ts`,
`src/routes/api/public/recruiting-outbox.ts`.

1. Add to `recruiting_outbox`: `payload jsonb`, `payload_text text`,
   `payload_sha256 text CHECK (payload_sha256 ~ '^[0-9a-f]{64}$')`,
   `captured_at timestamptz`, and `source_deleted_at timestamptz`.
2. Add `'review_mismatch'` to the `status` CHECK list.
3. In `enqueue_recruiting_application()`, build the payload in SQL from
   `NEW` with exactly the fields `buildRecruitingPayload` sends today
   (`external_reference`, `role`, `name`, `email`, `phone`, `city`,
   `state` from `state_region`, `metros` from `primary_markets`,
   `portfolio_url` when it starts with http(s), `gear`, `flies_drone`), and
   store it in `payload`. Simpler and safer: keep the trigger as is, and have
   the worker fill the snapshot on the first claim, in the same transaction
   as the claim, only when `payload IS NULL` (`captured_at = now()`). Either
   way the snapshot is written once and never updated.
4. `payload_text` is the exact string later sent as the request body
   (`JSON.stringify(payload)`), and `payload_sha256` its SHA-256. Every retry
   sends `payload_text` byte for byte, never a rebuild from the current row.
5. Before each send, rebuild the payload from the current application row.
   If the row exists and its canonical payload differs from the snapshot,
   set `status = 'review_mismatch'` and do not send. Staff resolve it.
6. Change the foreign key from `ON DELETE CASCADE` to `ON DELETE SET NULL`
   (make `application_id` nullable). Add a `BEFORE DELETE` trigger on
   `photographer_applications` that stamps `source_deleted_at` on the outbox
   row. A row with a snapshot and no application still replays the snapshot;
   a row without a snapshot moves to `review_mismatch`.
7. Attachments: the website sends none today (`cv_url` is not forwarded). If
   CV forwarding is added later, store the original bytes in a private
   Storage bucket keyed by SHA-256 at submit time, add
   `attachment_sha256s text[]` to the snapshot, and send exactly those
   bytes. The Dashboard now refuses changed bytes with 409 (P2-03).
8. Backfill: the one delivered row needs nothing. Rows pending at deploy time
   get a snapshot from the current row, marked
   `last_error_code = 'snapshot_backfilled'` so a person confirms it before
   it is sent.
9. Tests: extend `rehearse-producers.mjs` (isolated Postgres, no network):
   edit after capture gives `review_mismatch` and no POST; delete after
   capture keeps the snapshot and replays it once; an unchanged row replays a
   `payload_sha256` equal to the capture ledger. Extend
   `recruitingOutbox.test.ts` likewise.

### P2-05: a scheduler hold that keeps capture on

1. Add `worker_paused boolean NOT NULL DEFAULT false` and
   `worker_paused_at timestamptz` to `recruiting_forwarding_settings`.
2. `runOutbox` reads `worker_paused` in `store.settings()` and, when true,
   returns `{ paused: true, ... }` before `claim()`. The route returns 200
   with that body so the outside scheduler does not alarm.
3. The insert trigger still checks only `enabled`, so new applications are
   captured while the worker is paused.
4. Freeze: set `worker_paused = true` (one row update, approval). Drain: wait
   until no row has `status = 'sending'` with `lease_until > now()` (leases
   are 5 minutes). Release: set it back to false.
5. Holding the worker uses no attempts. Without it, about 127 minutes of 503s
   (8 attempts, 1 to 64 minute backoff) turn rows into `failed_exhausted`.
   Add a reviewed reset query for that case:
   `UPDATE recruiting_outbox SET status = 'pending', attempts = 0, next_attempt_at = now() WHERE status = 'failed_exhausted' AND id = ANY(:ids)`.
6. Test in the isolated Postgres rehearsal: paused worker returns
   `{paused:true}` without claiming while an insert is still captured;
   interrupt mid-lease, unpause after expiry, one reclaim and one Dashboard
   effect.

### P2-06: website export for the operator ledger

A read-only, service-role-only server route or SQL view that returns JSON for
`scripts/cutover/website_ledger.py`:

```json
{"site_commit": "<40-hex published commit>", "freeze_sha256": "<64-hex freeze id>",
 "rows": [{"producer": "booking|contact|recruiting", "reference": "<original reference>",
           "state": "<state>", "request": "<exact original request body>",
           "attachment_sha256s": []}]}
```

- Bookings: every booking row created since the freeze minus a margin. State
  is `confirmed` only when the Dashboard shoot id is stored; otherwise
  `pending_with_quote`, `pending_without_quote` (fenced catalog, no frozen
  quote, no POST), `reconciliation_pending`, `sync_failed` or `conflict`.
  `request` is the exact body forward-booking-v2 sends with the frozen quote.
- Contacts: every saved inquiry whose delivery outcome is not `delivered`
  with `status_recorded`. `request` is the exact website-email body.
- Recruiting: every outbox row not `delivered`, with `request` =
  `payload_text` (after P2-02).
- The exporter keeps only rows whose state is not settled
  (`confirmed` for bookings, `delivered` otherwise). It writes hashes to the
  shared ledger and raw references only to the private CSV.

## Owner decisions and provider checks

- Receipts written before this change have no recorded identity. A replay of
  one gets 409 for staff review (safest default). Only one delivered row
  exists in production.
- Aryeo retry behaviour for 503 on the API webhook is unconfirmed. If Aryeo
  does not retry, held deliveries need a manual redelivery request.
- Resend: confirm in the dashboard (read-only) that a replayed message keeps
  its `svix-id` and gets a fresh signature, and how long retries last.
- Stripe: create two restricted keys with Events read only, stored like the
  other secrets.
- The nginx include line must be added once to both Dashboard vhosts as a
  reviewed root nginx change before any freeze. Deploy-App never touches
  nginx (cutover runbook, Release and target preparation step 10).
- The edge snippet is wider than `PRODUCER_INGRESS_HOLD`: it answers 503
  for everything under `/api/external/`, so the website's catalog read
  (`GET /api/external/services`), `POST /api/external/check-client` and the
  website's Listing Studio guest-trial bridge (`/api/external/listing-studio/*`)
  are held too. That suits a full write freeze, and a held catalog read is the
  known cause of website bookings saved without a frozen quote (the P2-06
  ledger lists them). Confirm the website's guest-trial flow shows a retry
  message on 503, or narrow the snippet to the producer paths, before a real
  freeze.

## Notes for the integrator

- New migration `2026_11_25_140000_add_replay_identity_to_recruit_application_receipts`.
  Regenerate `database/schema/mysql-schema.sql` once all lanes have landed
  their migrations (done after the merge by the schema lane, `5e0cf502`).
  The table stays in the 328-table scope.
- New config file `config/producer_replay.php`; new keys in `config/crm.php`
  (`outbound_hold`).
- `bootstrap/app.php` gains one `prepend(HoldProducerIngress)` line. The
  Telnyx lane's receiver also belongs before maintenance; keep both first.
- `routes/crm-public.php`: `crm.enabled` becomes `crm.enabled:retry` on the
  careers route only.
- Not held by this lane, in the app or at the edge: Telnyx (its own lane
  owns the capture route), iGUIDE (`/iguide_webhook.php`), CubiCasa
  (`/cubicasa_webhook.php`), Cakemail (`/api/webhooks/cakemail`),
  Autoenhance (`/api/webhooks/autoenhance`) and Vapi (`/api/webhooks/vapi`). The
  website's Listing Studio bridge (`/api/external/listing-studio/*`) is held
  only by the edge snippet. The writer-freeze lane should confirm each is
  fenced or safe to drop during a freeze.
- The edge snippet's `Retry-After` is fixed at 60 (nginx cannot read
  `PRODUCER_INGRESS_RETRY_AFTER`); edit it if that value changes. Its
  `add_header` lines replace any inherited server-level `add_header` (HSTS,
  for example) on the held 503s only. Neither affects producer retries.
- `tests/MySql/RecruitingReplayConcurrencyTest.php` truncates tables (like
  the other process tests). Run it after the feature tests, as CI's sorted
  file order already does.

## Evidence

All runs were offline: Docker `--network none`, the pinned image
`repro-mysql-rehearsal-qa:20261122` (PHP 8.3.33), the shared read-only
vendor volume, and for MySQL a disposable `mysql:8.4` (8.4.13, image
`sha256:a81d0439…`) container with its socket on a tmpfs volume. No
provider, website or production system was contacted. Each new behaviour
was seen failing first for the expected reason (missing column, 404 or 200
instead of 503, missing command or option), then passing.

| Run | Result |
| --- | --- |
| New and touched PHP tests on SQLite (`RecruitingReplayIntegrityTest`, `ProducerIngressHoldTest`, `ResendRedeliveryTest`, `CrmOutboundHoldTest`, `CrmCalendarReconcileTest`, `CrmWebsiteLedgerTest`, `AryeoFreezeResumeTest`, `StripeReplayAfterFreezeTest`) | All pass |
| Regression files on SQLite: `tests/Feature/Crm`, `WebsiteEmailTest`, `ExternalBookingControllerTest`, `ExternalBookingReleaseContractTest` | 139 tests, 1116 assertions, OK (before P2-12 and later items) |
| Aryeo and Stripe neighbours on SQLite: `AryeoIntegrationTest`, `AryeoApiTest`, `StripeWebhookOwnershipTest`, `ListingStudioStripeSyncTest` | OK |
| MySQL profile (`phpunit.mysql.xml`): `tests/Feature/Crm` plus the Aryeo, Stripe, Listing Studio, website email and external booking files above | 308 tests, 2158 assertions, 2 errors, both in the SQLite-only copy fixture of `CrmWebsiteLedgerTest`. Fixed in `59198581`; that file then gave 3 tests, 1 skipped (SQLite copy only), OK |
| MySQL process tests: `tests/MySql/RecruitingReplayConcurrencyTest.php` | OK: two overlapping replays, one applicant, one file |
| `tests/MySql/CrmWebsiteIntakeConcurrencyTest.php` (unchanged) | Timed out waiting for its worker once while the full SQLite suite was using the CPU; passed when rerun alone |
| `python -B -m unittest scripts.cutover.test_website_ledger scripts.cutover.test_verify_website_replay` (Windows and the Linux image) | 8 tests OK |
| `scripts/cutover/fence/test_producer_hold.py` in the Linux image (real nginx) | 2 tests OK; fails as expected when the snippet returns 404 or drops `Retry-After`; skips on Windows |
| Full SQLite suite (`php vendor/bin/phpunit`, code at `9cae88c6`, the tree copied into a container tmpfs) | 9623 tests, 320499 assertions, 2 errors, 2 failures, 3 skipped, 140 minutes. The four are `ListingStudioPaidStorageTest` and `ListingStudioPrivateStorageTest` (`test_fpm_upload_and_separate_worker_can_share_private_files`), `WorkspacePhotoLogoPermissionsTest::test_php_can_upload_a_logo_when_worker_outputs_are_not_group_writable` and `IguideLabImportTest::test_concurrent_retry_cannot_enter_publication_window_or_delete_canonical_chunk`. Each drops to a non-root user that cannot read the copied vendor tree in this container. All four fail the same way on the base `a6e799bb` with the same runner, so they are pre-existing here and unrelated to this lane. The later commit `59198581` only changed `CrmWebsiteLedgerTest`, rerun separately on both engines. |

## Independent review (2026-11-25)

A second session reviewed `a6e799bb..36e06e47` against the open items and
reran everything offline with the same images.

- Fixed in `c309af4c`: `crm:calendar-reconcile` failed forever on production
  data. `push()` keeps done and missed follow-ups in Google on purpose and the
  poller never reads them, yet each one was a blocking `local_not_planned`
  row; links left by a rep who disconnected Google blocked too. They are now
  `closed_locally` and `no_connection`, listed but not blocking. A cancelled
  follow-up still in Google is the blocking `cancelled_still_in_google`.
- Doc fix in `d2274365`: the edge snippet holds all of `/api/external/`.

| Run (review, code at `c309af4c`) | Result |
| --- | --- |
| Lane PHP tests on SQLite (the eight files above) | 35 tests, 254 assertions, OK before the fix; `CrmCalendarReconcileTest` 5 tests OK after it (both new tests failed first on `local_not_planned`) |
| MySQL 8.4.13 profile after `migrate:fresh`: `tests/Feature/Crm` plus the Aryeo, Stripe, Listing Studio, website email and external booking files | 311 tests, 2183 assertions, OK, 1 skipped (the SQLite-copy-only ledger test) |
| MySQL process tests `RecruitingReplayConcurrencyTest`, `CrmWebsiteIntakeConcurrencyTest` | 2 tests OK |
| `test_producer_hold`, `test_website_ledger`, `test_verify_website_replay` in the Linux image (real nginx) | 10 tests OK; on Windows 10 tests OK, 2 skipped (no nginx) |
| `pint --test` on all 35 PHP files the branch adds or changes | Pass |
| Full SQLite suite, worktree copied into a container tmpfs with `chmod -R a+rX` | 9626 tests, 320848 assertions, 0 failures, 3 skipped, 68 minutes |

The four failures in the earlier full run came from the runner: the shared
vendor volume is mode 0700 root, so tests that drop to a non-root user could
not read it. With the copy made readable, all four pass (4 tests, 31
assertions).

## Codex review round (25 Nov)

Codex reviewed `dd506deb`; each finding was rechecked at `a60c2d86`. Fixes
were test-first (each new test seen failing for the stated reason).

- producer-replay#1 (P2), pending calendar creates skipped: fixed in
  `ec801269`. Links with `push_pending` and no Google id are now the blocking
  `unconfirmed_create`, with no Google call
  (`CrmCalendarReconcileTest::test_a_create_google_never_confirmed_needs_a_decision`).
- producer-replay#2 (P2), ledger booking lookups not scoped to the website:
  fixed in `7a55ec84`. Both lookups require source `reprophotos.com`; a
  reference held only by another source is the blocking `other_source_only`
  (`CrmWebsiteLedgerTest`, both engines).
- producer-replay#3 (P2), done or missed follow-ups block forever: already
  fixed in `c309af4c` (`closed_locally`, not blocking), pinned by
  `test_closed_follow_ups_and_disconnected_calendars_are_listed_without_blocking_the_release`.
- producer-replay#4 (P2), failed, blocked or logged reply forwards counted as
  forwarded: fixed in `18b43feb`. Only an accepted forward (`sent`) or a
  recorded disposition settles a reply
  (`CrmOutboundHoldTest::test_a_reply_blocks_until_its_forward_is_accepted_or_reviewed`,
  driving the real inbound reply and forward).
- reviewer#1, token refresh with an empty access token: not a defect. The
  column is `text` NOT NULL and both writers (`GoogleCalendarController`
  callback, `getValidAccessToken`) store only a token that
  `parseTokenResponse` has checked is non-empty, so the path is unreachable.
- reviewer#2, unreadable `--since` in `crm:website-ledger`: fixed in
  `f78d585a`; it now exits 2 before the database is touched.
- reviewer#3, edge `Retry-After` fixed at 60 and `add_header` inheritance:
  documented in the snippet and under "Notes for the integrator"; no effect
  on producer retries (`test_producer_hold` still passes with real nginx).
- reviewer#4, `--resume-processing` restores `enabled`: by design ("restore
  exactly"); runbook step 12 now says not to resume a connection disabled on
  purpose during the freeze.
- reviewer#5, webhooks outside this lane: listed under "Notes for the
  integrator" for the writer-freeze lane to confirm.
- reviewer#6, provider, website and owner work: unchanged owner decisions,
  listed under "Website change spec" and "Owner decisions and provider
  checks".

| Run (code at `18b43feb`) | Result |
| --- | --- |
| SQLite: `CrmCalendarReconcileTest`, `CrmWebsiteLedgerTest`, `CrmOutboundHoldTest` | 6, 4 and 8 tests, OK |
| SQLite: all eight lane PHP test files listed under Evidence | 40 tests, 286 assertions, OK |
| MySQL 8.4.13 (`phpunit.mysql.xml`, disposable server on an internal Docker network, after `migrate:fresh`): the same three files | 18 tests, 103 assertions, OK, 1 skipped (SQLite-copy-only ledger test) |
| `test_producer_hold` in the Linux image (real nginx) after the comment change | 2 tests OK |
| `pint --test` on the six changed PHP files | Pass |

### Round 2 (Codex on `a4395bcc`)

Codex reviewed the whole branch again; its two findings and the earlier
dismissals were rechecked. Fixes were test-first.

- r2#1 (P1), `--pause` leaves worker writes on: documented in `7e4c4fd0`.
  Like `--disable-processing` (`docs/aryeo-flow.md`), the pause stops job
  claims, delivery and request acceptance, so no Aryeo action starts;
  heartbeats, discovery with a requester email and inventory are idempotent
  sync the agent re-sends. HTTP writes during the freeze are stopped by the
  writer freeze (admission closed before Laravel), not by this command. The
  command, its help and runbook step 1 now say this, and
  `AryeoFreezeResumeTest::test_a_paused_worker_cannot_claim_a_job_and_the_command_says_what_continues`
  pins the claim refusal.
- r2#2 (P2), other sources' `shoot-<id>` receipts in manual reconciliation:
  fixed in `7ff872b6`. A receipt counts only when its shoot is a website
  booking without a reference
  (`CrmWebsiteLedgerTest::test_only_website_bookings_without_a_reference_need_manual_reconciliation`,
  both engines).
- producer-replay#3: still fixed; done and missed follow-ups are never
  pushed or polled, so `closed_locally` stays non-blocking.
- reviewer#1: still not a defect. The only `access_token` writers store a
  token `parseTokenResponse` checked is non-empty (the third mention, in
  `assertCalendarPermission`, only reads it).
- reviewer#3, reviewer#5: still documented; nothing new.
- reviewer#4: reopened and fixed in `f658d66c`. The warning did not stop the
  resume from re-enabling a credential revoked during the freeze or
  re-widening a narrowed scope. The resume now turns back on only what the
  pause turned off and otherwise refuses, changing nothing
  (`AryeoFreezeResumeTest::test_resume_refuses_a_connection_changed_during_the_freeze`).

| Run (code at `7e4c4fd0`) | Result |
| --- | --- |
| SQLite: each new test before its fix | Failed for the stated reason (manual list `[1, 2, 999999]`; resume of a revoked connection exit 0; pause output) |
| SQLite: `CrmWebsiteLedgerTest`, `AryeoFreezeResumeTest`, `AryeoIntegrationTest` | 74 tests, 539 assertions, OK |
| MySQL 8.4.13 (`phpunit.mysql.xml`, disposable server on an internal Docker network, after `migrate:fresh`): the same three files | 74 tests, 521 assertions, OK, 1 skipped (SQLite-copy-only ledger test) |
| `pint --test` on the four changed PHP files | Pass |
