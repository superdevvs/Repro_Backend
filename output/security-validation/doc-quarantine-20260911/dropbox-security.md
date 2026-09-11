# Historical Dropbox connection hardening — audit #5

**Historical record, superseded by Dropbox retirement on 7 September 2026.** The live backend is `80672ae7cc5f0a846bcbab2c5a4c7ac8af184f6f` and frontend is `c6f8f1c16d3e6062a533ec0e6ca617a79e4f0370`. Connection UI, OAuth callbacks, provider routes, webhook and connection commands have been removed. The former reconnect, callback-configuration, migration and disconnect-recovery instructions are no longer operational procedures. Do not reconfigure or reconnect the retired integration. See [Dropbox retirement](dropbox-retirement.md) for the current implementation and acceptance record.

## Earlier hardening release

Before the retirement decision, the reviewed release restricted studio connection management to active primary administrators/superadministrators outside impersonation. It used one-use encrypted browser-bound OAuth state, PKCE, initiating-session/token checks, connection versions and shared cache locks. Status omitted tokens and secrets. New credentials were encrypted using the existing APP_KEY, with ciphertext-compatible rollout and a legacy migration command. The production migration found zero studio OAuth rows to encrypt.

That release rejected generic settings writes to Dropbox credentials, closed shared upload-source rebind paths, retired browser token/debug helpers and required exact-body webhook signatures. Failed provider revocation kept local access disabled and retained encrypted retry state; operator acknowledgement required independently verified provider revocation. These controls describe the former implementation, not routes or commands available after retirement.

The original combined backend validation passed **346 tests / 3,474 assertions**, including OAuth/state/cookie/replay, credential migration/recovery/race, settings, webhook, upload-source, shoot-access and MMM regressions. The frontend connection suite passed **25 tests**; TypeScript, production build, lint baseline and changed PHP syntax checks passed. Those tests used isolated SQLite, fake HTTP and fake queues. No provider account was contacted. Real Dropbox browser consent was not performed, and is no longer an acceptance requirement for a retired integration.

Provider contracts for that historical implementation were checked against the [Dropbox OAuth guide](https://developers.dropbox.com/oauth-guide), [official SDK PKCE exchange](https://github.com/dropbox/dropbox-sdk-python/blob/main/dropbox/oauth.py), [webhook reference](https://www.dropbox.com/developers/reference/webhooks), and [offline access/revocation guide](https://dropbox.tech/developers/using-oauth-2-0-with-offline-access).

## Current acceptance limits

Retirement CI and production media/endpoint checks passed, and the browser renders existing raw/edited thumbnails and the large image viewer. **Finding #5 is closed for the application integration.** The user restored the existing default worker with approval to resume its queued work; browser RAW, MLS and Print flows passed, both edited ZIPs returned HTTPS 200 with verified contents, the backlog cleared and no new failed jobs appeared. Refer to the retirement record for the acceptance evidence. Historical provider grants remain unverified under #3.

No Dropbox account, provider app or cloud files were deleted. Active application credentials were removed from runtime configuration, but historical provider grants and archived credentials have not been verified revoked. That separate work remains open under #3. Quarantine stays closed, APP_KEY cutover stays deferred, and paused findings #7 and #8 remain unchanged.
