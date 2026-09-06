# Authentication hardening — audit #9

Live backend `3ad628da4e833b70f744dc03c811137b86a11720` and frontend `318fd061106a97419c573da388a77a4502f80991` were released from clean isolated checkouts after full CI. The verification pilot is active: start `2026-09-06T18:48:48Z`, enforcement `2026-09-20T18:48:48Z` (21 September 00:18:48 Asia/Kolkata). No seeder, provisioning default, provider credential or APP_KEY changes occurred.

## Passwords and request limits

Registration, emailed password reset, self-service password change and administrator password reset require at least eight Unicode characters and at most 72 UTF-8 bytes. Null characters are rejected with field validation because bcrypt cannot hash them. Passwords are not trimmed. Existing login credentials keep working; the configured hash driver is unchanged.

The shared database stores hashed account/IP counter identifiers, counts and expiry times. Short transactions reserve attempts atomically across application processes. SQLite acquires a write reservation before reading a mutable counter. Successful login and password-only MFA challenges refund only their own account reservation in the same window; earlier and concurrent failures remain counted until expiry.

| Operation | IP limit | Account limit |
| --- | --- | --- |
| Login, including MFA attempts | 10 requests / minute | 10 failed or in-flight attempts / 15 minutes |
| Password reset/change, including manual administrator reset | 10 requests / 15 minutes | 10 requests / 15 minutes |
| Forgot/reset-link sending | 10 requests / hour | 3 requests / hour |
| Verification resend | 10 requests / hour | 3 requests / hour |
| MFA/session-security mutations and email correction | 10 requests / 15 minutes | 10 requests / 15 minutes |

Public unknown-account recovery responses remain generic, including mail transport failures. Sending paths share quotas with the administrator alternatives. Limits return 429 with `code=auth_rate_limited`, `retry_after` and the `Retry-After` header. Normalized account identifiers share a counter across case variants and IPs. Configure trusted proxies narrowly so the client IP used by these limits is trustworthy.

Malformed email values still consume quota but use a fixed invalid-input counter identity; normal validation returns 422 instead of an array-to-string warning. The IP quota also counts these malformed requests.

The production peer check observed Cloudflare edge addresses connecting directly to nginx's public listeners; nginx passes the original peer as `REMOTE_ADDR` without real-IP rewriting. `config/trusted_proxies.php` pins the official [IPv4](https://www.cloudflare.com/ips-v4) and [IPv6](https://www.cloudflare.com/ips-v6) edge ranges verified on 2026-09-06. Laravel trusts forwarded headers only from those peers, preserving the existing forwarded-header bitmask. Direct, loopback and private-network callers cannot choose their rate-limit identity through `X-Forwarded-For`. Development does not need forwarded client IPs. Review the pinned list against the official sources during releases and when Cloudflare announces changes; update the list and rebuild the application configuration cache together. If the origin topology changes, verify its immediate peers before changing this boundary. Do not replace it with wildcard, broad private-network or guessed tunnel trust.

The first exceeded counter in each window emits a bounded notice containing only its scope and server-generated request ID, including signed-out requests. Further blocks for that counter/window do not create repeated log entries. Passwords, codes, tokens, raw email/IP values and request bodies are excluded from this event.

`auth:prune-security-limits --limit=1000` removes only a bounded batch of expired rows, rechecking expiry before deletion. The Laravel hourly schedule is wired, but the production operator must verify that a scheduler actually runs. The current production check found no active scheduler; install a dedicated hourly deploy-user invocation of this cleanup command after release rather than enabling unrelated scheduled tasks. Expiry enforcement works independently of cleanup. The command never removes a live reservation. Increase the bounded batch/frequency if expiry backlog monitoring shows it is needed.

## Credential and MFA mutation boundaries

Reset token issuance, consumption, credential replacement and token revocation use the same user-row locking order. The first transactional user access is a no-op write, since SQLite does not implement `SELECT FOR UPDATE`. Hashing happens before reset consumption takes the writer reservation; the transaction rechecks the token snapshot, current account/email and expiry before saving. Only one concurrent request can consume the token. Failure to revoke sessions rolls back the password and reset-token consumption together.

MFA remains optional. Pending setup is encrypted in cache for ten minutes and bound to the initiating persisted API bearer session and password hash version. These API flows issue bearer tokens; unsupported ambient web sessions cannot mutate security settings. Confirming from another browser, after expiry, after revocation or after a password change fails. All MFA and session-security mutations serialize on the user row and revalidate the current account, token and verified password version. TOTP steps and recovery codes remain single-use. Enabling/disabling MFA revokes other sessions; recovery-code regeneration replaces previous codes atomically. Emailed password recovery does not disable MFA. Mail/provider notification work runs after the credential/verification transaction releases its write lock.

Use a shared cache for pending setup across web nodes and keep APP_KEY consistent. There is no new operator MFA bypass, recovery password or seeder path. Preserve the separately approved workstation recovery runbook. Recovery-code hashes currently depend on APP_KEY; the separate #3 key-rotation runbook must account for code reissue.

## Email verification pilot

The additive migration creates a durable rollout record and dedicated per-user ownership/cohort fields. The pilot is inactive until an operator intentionally applies the command:

```text
php artisan auth:start-email-verification-pilot
php artisan auth:start-email-verification-pilot --apply
```

The first command is a dry run. Applying records one immutable start timestamp. Repeating it reports that same start and deadline. It does not enroll unchanged existing accounts retroactively.

New accounts and accounts whose email changes after that start are enrolled. For fourteen days the frontend shows a reminder. At the recorded start plus fourteen days, server middleware requires proof of ownership of the current email for enrolled accounts, including existing sessions. Email corrections preserve enrollment and the original deadline. Delivery/bounce status is independent: a later bounce does not remove ownership proof, and a delivery status cannot create it.

Existing unchanged unverified accounts receive a reminder once the pilot starts and retain dashboard access. Their reminder has no enforcement deadline or access-loss wording. The server exposes this separately as `reminder`, so prompting does not depend on enrollment or delivery status.

The server continues to allow session status, logout, email verification/resend, a narrow password-confirmed email correction endpoint, password recovery and the existing account security controls. Other profile/business changes remain gated. Public tour and provider callback routes remain public. Verification token issue/consume and email persistence share the user lock, preventing an old-address callback from verifying a concurrently changed address.

Impersonation applies the same active-account and verification middleware to the original administrator before swapping identities. Downstream middleware still checks the target. An inactive or enrolled/unverified administrator cannot use a grandfathered target to bypass their own gate; recovery uses the same allowlist.

The frontend refreshes authoritative status on mount/focus and periodically. A server `email_verification_required` event immediately hides protected content while retaining correction, resend, recheck, logout and account-security controls. It does not discard the session simply because verification is required.

## Release and validation

Run the additive migration with the reviewed backend/frontend release, configure the scheduled counter cleanup and verify the middleware ordering with a real bearer session. Keep the pilot inactive until delivery and correction smoke checks pass on the intended deployment. Then record the pilot start/deadline and monitor enrollment, blocked requests, resend outcomes and expiry-counter backlog without recording passwords, OTPs, tokens or raw cache values.

Focused backend validation passed **57 tests, 472 assertions**, with warnings/risky tests treated as failures. It covers rate limits and bounded logging, password boundaries, MFA browser/credential binding, account ownership gating, narrow correction, reset rollback/reuse, existing login/profile/admin behavior, notifications outside write transactions, and a real two-process SQLite reset race. Tests use isolated databases and fake mail; no provider account is contacted. The focused frontend suite passed **7 tests** covering UTF-8 password boundaries, reminders, immediate enforcement and recovery controls.

Additional focused regressions for malformed email quota consumption and both sides of impersonation passed **3 tests, 92 assertions**. They use real persisted test bearer tokens and preserve allowed recovery access.

Final isolated-candidate focused validation passed **26 backend tests / 318 assertions** and **14 frontend tests**, plus TypeScript and the lint gate. Existing email-health tests passed **24 tests / 207 assertions** after correcting two fixtures that simultaneously claimed verified ownership and unverified delivery status. Production verification continues to use ownership proof. Full CI is required on the committed candidate before deployment.

Production acceptance: 90 read-only checks passed before and after activation. The real loopback HTTP burst produced ten 401 responses followed by 429 while forged IP headers were ignored; a separate request after expiry returned the normal 401 and reset the minute window. Counter cleanup is installed through the dedicated hourly cron. One authorized verification-template test reached the user, who confirmed it displays correctly; the test used a harmless dashboard link because the existing account was already verified. Real ownership-token/correction behavior remains covered by feature tests and deployed synthetic checks, without changing a real account. Full CI passed 3,172 backend tests / 87,940 assertions; frontend quality passed 1,343 tests plus other gates.

A production observation found the global log threshold is `error`, so the default-channel bounded throttle notice is suppressed. The follow-up candidate routes the existing bounded throttle event to a separate restricted daily `auth-security` channel at notice severity, with the privacy processor, mode 0660 and 14-day retention. The default error-only threshold stays unchanged. Focused tests passed 26 tests / 331 assertions, including actual file output with secret/identifier canaries and one notice across repeated blocks.
