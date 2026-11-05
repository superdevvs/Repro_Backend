# Zillow / Aryeo partner registration

Public setup: https://reprodashboard.com/api/integrations/aryeo/setup

Registration JSON: https://reprodashboard.com/api/integrations/aryeo/setup.json

Exact production redirect URI:

```
https://reprodashboard.com/api/integrations/aryeo/oauth/callback
```

## Current capability

This is a completed registration handoff, **not an OAuth connection**. The public
page and JSON expose only fixed registration details. They do not expose secrets,
client IDs, existing worker connections, customer records, or environment values.
The callback is reachable but always fails closed: it does not read, store,
exchange, log, or reflect callback parameters, and responds with a 303 redirect to
the fixed setup page explaining that connection is not enabled. It cannot create
an account link. No start/authorize, token, refresh, webhook, or new data-sync
endpoint is implied. Existing Aryeo worker authentication/delivery is unchanged.

The identifiers intentionally use the canonical production origin, independent
of Host, forwarded headers, request parameters, and development configuration.
The JSON is a registration note, not OAuth or OpenID discovery metadata.

## Partner information required

Confirm whether this particular partner offering uses OAuth. Aryeo's public
Company API documents bearer API keys:
https://docs.aryeo.com/docs/2-authentication . A private Zillow/Aryeo partner API
may have a different specification.

Ask the partner for its API/OAuth specification, registered client ID, secure
client-secret delivery, authorization/token endpoints, supported grant and
client-authentication methods, scopes/consent, company/account identifiers,
PKCE/refresh/revocation requirements, signed-webhook contract, sandbox access,
and production approval process. Do not guess provider endpoints or scopes.

## Before enabling account connection

Implement the documented authorization flow in a separate reviewed release:

- An authenticated, authorized non-impersonated administrator starts a connection
  for an explicitly selected company/account.
- Use short-lived, unpredictable one-time state, stored hashed and bound to that
  actor/account and browser session. Use PKCE when supported/required. Atomically
  consume state and prevent replay before accepting a callback.
- Keep credentials and encrypted access/refresh tokens server-side. Allow only
  validated partner endpoints and the exact registered callback. No redirect
  targets or API endpoints may come from callback input.
- Exchange codes only following state/expiry/ownership checks. Check the provider
  account identity and scope before attaching it. Reject conflict, consent-denial,
  expired, replayed, malformed, and uncertain responses without duplicate linking.
- Ensure nginx/proxy/request telemetry redacts callback query parameters before
  any live authorization. This controller itself never logs them; infrastructure
  query logging still needs review before enablement. Retain no authorization
  codes in application storage, reports, screenshots, or browser telemetry.
- Test authorization, expiry, refresh, revoked access, account conflicts, repeated
  callbacks, denied roles, isolation, and redaction in the provider's sandbox.

No credentials, provider requests, emails, or live data writes are needed to view
or verify the registration handoff.
