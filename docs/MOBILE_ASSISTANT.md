# Siri and Shortcuts integration

This feature adds an authenticated, typed assistant surface for the native R/E Pro app. It is disabled by default. It does not replace the existing permissions, booking, messaging, calling or media workflows.

## Role coverage

Discovery uses `RolePermissionService` and the current account's effective permissions. Every operation also uses the existing target-specific authorization. Secondary roles and overrides can affect capabilities; domain rules that depend on the primary role remain authoritative.

| Primary role | Main Siri experiences |
| --- | --- |
| Superadmin | Workspace briefing, shoots, availability, confirmed scheduling and notes, authorized calling and messages, invoices and reporting |
| Admin | Workspace briefing, shoots, availability, confirmed scheduling and notes, authorized calling and messages, invoices |
| Editing manager | Production queue, assigned editing work and delivery review, authorized communications and scheduling |
| Sales representative | Briefing for assigned clients, permitted shoot lookup, notes, scheduling, availability and booking review |
| Photographer | Assigned appointments, directions, personal availability and blocking, permitted notes/messages, earnings and submission review |
| Editor | Assigned editing queue, including unfinished work after an earlier delivery; permitted media and submission review; support |
| Client | Own appointments and booking review, permitted linked/received shoots, client-visible notes, change-request review, invoices and support |

All seven roles have support and Robbie entry points when their permissions allow them. Calling, SMS, email and reports are not granted merely because Siri is enabled. In particular, account overrides do not bypass primary-role restrictions in the existing email, reporting and photographer-invoice routes.

## API contract

The `/api/mobile/assistant` routes use Sanctum, the existing active-account gates, rate limits and `private, no-store` responses. Impersonation is rejected.

| Method and suffix | Purpose |
| --- | --- |
| `GET capabilities` | Versioned owner, scope, timezone and allowed actions |
| `POST query` | Typed read, scoped entity lookup, or Robbie request |
| `POST actions/prepare` | Freeze parameters, audience, sender, record version and explicit spoken confirmation |
| `POST actions/{uuid}/confirm` | Execute once after `confirmed: true` |
| `GET actions/{uuid}` | Read/reconcile the owned receipt without repeating the action |
| `DELETE actions/{uuid}` | Cancel a still-prepared action |
| `POST actions/{uuid}/claim-handoff` | Atomically consume an approved native calling handoff once |

Responses have `schema_version: 1` and an `owner_id`. Parameters are action-specific, not arbitrary controller paths. `AssistantEndpoint` dispatches a fixed existing route through its middleware, restoring the outer request afterwards. Voice responses never forward full email channel configuration, provider metadata, attachments or payment internals.

### Confirmation and retries

Proposals expire after five minutes. `request_id` is a UUID unique per account; reusing it with different parameters is a conflict. Dictated parameters and results are encrypted at rest. Record versions, permissions, recipients and sender selection are checked again before execution. Availability requires the authoritative calendar timezone and rejects nonexistent or ambiguous local times without an exact instant.

An atomic state claim prevents concurrent confirmations from executing twice. Notes and availability writes commit with their receipt. Provider calls and scheduling services execute outside SQLite write transactions. Existing route conflict, notification, quiet-hours, opt-out and sender policies remain in force.

SMS/email records receive an internal receipt correlation attribute before dispatch. A timeout or repeat confirmation reads that record; it never resends automatically. Group SMS freezes the linked members' current numbers, excludes synthetic group inbox copies from delivery counts, and reports partial outcomes honestly. A queued message is never described as delivered.

Supported receipt states are `prepared`, `executing`, `completed`, `pending`, `partial`, `failed`, `uncertain`, `cancelled`, `expired`, `handoff` and `consumed`. Pending/partial/uncertain outcomes direct the user to the app. Do not delete action rows as a retry mechanism: their unique request IDs prevent duplicate writes.

Booking, client change requests, media edits, submissions, deliveries and appointments with multiple visits continue through their existing in-app review. Preparing those actions never performs the final business change. Calls start only through the existing native voice coordinator after an approved handoff is claimed.

### Robbie

Siri uses a separate, bounded tool selector. It does not enter the general transactional AI dispatcher and cannot confirm its own proposals. Deterministic briefing/queue requests work without an AI provider. Missing details, complex requests and model timeouts save the owned conversation and hand off to Robbie in the app. No generated answer is treated as proof that a business operation succeeded.

## Native behavior

The native implementation uses App Intents, ten App Shortcuts, scoped App Entities and in-app SiriKit call/message handlers. It targets the existing iOS 17 baseline. Settings provides account-specific opt-in, limited access while locked, role examples and the preferred business calling line.

Only the limited next-appointment and directions intents may run while locked, after first device unlock and when the user permits limited access. Notes, contact details, payment information and messages require device authentication. SiriKit communication handoffs require app review when the system confirmation cannot fully state the business sender and audience.

Owned URLs contain only the account and record/receipt identifier. Cold starts wait for authentication, permissions and legal gates. Account changes invalidate entities and in-flight sessions. Preview and simulator fixtures use an isolated transport and must never load the production Keychain.

## Rollout

1. Validate the migration and targeted tests against a non-production database.
2. Deploy backend code with `MOBILE_ASSISTANT_ENABLED=false`.
3. Build and sign the app with Siri capability, the supported-intent plist entries and the `repro` URL scheme. Preserve the existing app entitlements.
4. On physical devices, verify Siri spoken confirmation, cancellation, locked/unlocked behavior, account switching, cold launch, network interruption and business-line handoff. Simulator tests cannot establish these system-level behaviors.
5. Enable a named pilot using `MOBILE_ASSISTANT_ENABLED=true` and `MOBILE_ASSISTANT_PILOT_USER_IDS=...`, then refresh the configuration cache. An empty pilot list permits all otherwise-eligible accounts; it does not bypass in-app opt-in.
6. Expand the pilot only after role acceptance. Disable the feature flag for rollback; retain receipts for reconciliation and duplicate prevention.

No rollout, production migration or real communications are part of local implementation validation.

## Verification

`tests/Feature/MobileAssistantTest.php` covers all seven role capability boundaries, feature/pilot/authentication gates, record and note visibility, linked accounts, assignment revocation, encrypted proposals, expiry, cancellation, conflicting request IDs, calendar windows and DST, calling disambiguation and one-use handoffs, confirmation idempotency, partial group delivery, uncertain-message recovery, normalized read responses, scheduling conflict guards and Robbie conversation ownership.

Run it together with the existing SMS inbox, notes security, native availability and scheduling-permission suites. Provider tests use fake transports and prevent stray HTTP requests. Native contract and simulator evidence is recorded in the Siri candidate validation package, separately from physical-device or release evidence.
