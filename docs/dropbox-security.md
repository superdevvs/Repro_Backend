# Dropbox studio connection security — audit #5

Implemented locally on 2026-09-06. No production deployment, provider account change, credential rotation, or production database command has been performed.

## Result

Only an active primary admin/superadmin outside impersonation can manage the studio connection. Starting and disconnecting require an explicit bearer API credential. Status responses contain connection flags, an account label and a non-secret connection version. They do not return application secrets or access/refresh tokens.

| Surface | Behavior |
| --- | --- |
| GET /api/dropbox/config | Authenticated administrator; safe configuration/connection flags only. |
| POST /api/dropbox/connect | Authenticated administrator; throttled; returns an authorization URL and sets a browser-binding cookie. |
| GET /api/dropbox/connect | Retired; 405. |
| GET /api/dropbox/callback | Public provider callback with mandatory one-use state and browser binding; rechecks initiating administrator/token and connection version. |
| POST /api/dropbox/disconnect | Authenticated administrator; requires current connection_version; stale requests get 409. |
| GET /api/dropbox/webhook | Public bounded plain-text verification challenge with nosniff. |
| POST /api/dropbox/webhook | Mandatory exact-body HMAC-SHA256 in X-Dropbox-Signature; missing secret fails closed. |
| Legacy TestDropboxController HTTP routes | Removed in every environment. Use the secure connection UI and CLI diagnostics. |
| Generic settings Dropbox keys | Reads no longer expose stored values; writes cannot replace credentials. |

The OAuth flow has ten-minute, encrypted cache state and a random HttpOnly, host-only cookie. The cookie uses Secure in production and SameSite=Lax for the provider redirect. PKCE S256 binds the code exchange to the initiating flow. State is consumed before exchange; logout, token expiry/revocation, password change, loss of admin access, or a changed studio connection prevents completion. Authentication is checked again under the connection lock after provider calls. Local/debug callbacks never print tokens.

An ordinary reconnect is pinned to the existing provider-verified Dropbox account. To switch accounts, disconnect the existing studio account first. All studio credential writes, refreshes and disconnects use a shared cache lock and connection version checks.

New studio credentials are encrypted with the application's existing encryption key. Legacy plaintext rows remain readable for rollout and have a migration command below. A disconnected record persists so old environment values cannot silently restore access. If Dropbox revocation fails, local studio access stays disabled and encrypted retry credentials are retained. Reconnection is blocked until revocation is resolved. Encryption does not invalidate credentials in quarantined archives; audit #3 remains pending.

The alternate upload-source endpoint cannot create or complete a shared Dropbox connection, including old shared OAuth states. Generic studio-folder browsing/import is administrator-only; assigned shoot workflows keep their existing access path. Personal upload-source OAuth remains separate. The obsolete frontend token picker/code exchange has been retired, and its old localStorage token is removed when those surfaces mount.

Webhook notifications are authenticated before parsing. Payloads are bounded and validated; logs contain aggregate counts only. The endpoint still acknowledges notifications without dispatching file-sync jobs. Identical account lists can represent distinct changes, so they are not suppressed by payload hash.

## Release steps

1. Review the implementation against the real repository base. Backend and frontend Git metadata is available. The release is prepared in an isolated checkout against the verified live base, with only reviewed changes. Ship the matching backend and frontend changes together; keep the unrelated #8 draft webhook helpers/configuration out of this release.
2. Retain the existing APP_KEY and a protected backup. Configure a lock-capable cache shared by every web/worker node (for example shared database or Redis), including the cache lock tables when applicable. Do not use a per-process array/null store in production. This state and lock infrastructure is required for OAuth and credential concurrency.
3. Confirm APP_URL and APP_FRONTEND_URL/FRONTEND_URL point to the intended HTTPS API and frontend. The existing studio callback is APP_URL + /api/dropbox/callback and must match the Dropbox App Console exactly. Frontend/API requests must permit credentialed cookies for the intended frontend origin. Local HTTP is accepted only for loopback development URLs; TLS certificate verification remains enabled for Dropbox requests.
4. Confirm the app's enabled scopes cover current workflows: account_info.read, files.metadata.read, files.metadata.write, files.content.read, files.content.write, sharing.read and sharing.write. If the optional personal upload-source feature is enabled, also register APP_URL + /api/upload-sources/dropbox/callback. This is separate from the studio callback.
5. On the intended deployed database, inspect then encrypt legacy studio rows:

~~~text
php artisan dropbox:encrypt-studio-credentials
php artisan dropbox:encrypt-studio-credentials --apply
~~~

The first command is a dry run. The apply operation encrypts only legacy shared Dropbox credential fields; it preserves connection versions/metadata and leaves personal/other-provider rows alone. Output contains counts, not credentials. It does not rotate credentials or call Dropbox. Test and deploy the command with the new token model before running it.

6. Use Settings → Integrations → Dropbox as a primary administrator. Verify safe status, successful same-account authorization and normal studio workflow access. In a test account, verify logout before callback, callback replay, a different account, and stale disconnect requests are rejected. Verify non-admins cannot use the controls and unsigned webhook POSTs are rejected. The current storage configuration still controls whether workflows use Dropbox or R2.

## Failed or already-revoked provider grants

Use Retry disconnect when provider revocation is pending. Fix provider connectivity/configuration first. The application does not claim provider revocation succeeded merely because a token refresh failed.

If the grant was already revoked outside the application and retries cannot verify that state, an operator must verify the app's revocation in the relevant Dropbox account. Then inspect the current pending connection version from the administrator status endpoint and run:

~~~text
php artisan dropbox:acknowledge-revocation <connection_version>
php artisan dropbox:acknowledge-revocation <connection_version> --apply --confirm-provider-revoked
~~~

The default is a dry run. Applying requires both explicit flags and the exact current pending-disconnect version. It clears retained retry credentials, preserves local disconnection, changes the version, and records the operator acknowledgement. It does not contact Dropbox or revoke access itself. A new account can then be connected through the administrator UI. Do not use this acknowledgement without verifying revocation in Dropbox.

## Validation

Final combined backend validation: **346 tests, 3,474 assertions passed**, with warnings/risky tests treated as failures. This includes OAuth/state/cookie/replay tests, credential migration/recovery/race tests, settings and webhook tests, existing upload-source checks, the #2 authorization regression set and existing MMM return/punchout tests. Tests used isolated SQLite, fake HTTP and fake queues; no provider account was contacted.

The frontend connection suite passed **25 tests**. TypeScript checking, the production frontend build and the lint baseline gate passed. All 18 PHP files changed/added in this step passed syntax checks. Browser consent against a real Dropbox account remains a deployment smoke check.

Provider contracts were checked against the [Dropbox OAuth guide](https://developers.dropbox.com/oauth-guide), [official SDK PKCE exchange](https://github.com/dropbox/dropbox-sdk-python/blob/main/dropbox/oauth.py), [webhook reference](https://www.dropbox.com/developers/reference/webhooks), and [offline access/revocation guide](https://dropbox.tech/developers/using-oauth-2-0-with-offline-access).
