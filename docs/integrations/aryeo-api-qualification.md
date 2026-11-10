# Aryeo qualification and pilot acceptance register

Owner: Shubham. Initial state as of implementation on 2026-11-10: **all partner demonstrations below are unconfirmed**. Unit tests, code, public product guidance and documentation do not advance a row to a partner demonstration. Requested partner names are not assumed assignees. Agree dates at kickoff; mirror accepted evidence in Integrations.

| Requirement | RePro owner / requested counterpart | Proposed deadline | Status / evidence | Blocks |
| --- | --- | --- | --- | --- |
| Background authorization | Shubham / Ben | November 13 | Unconfirmed / — | Unattended work; test authorized operation, refresh, revoked access, scope limits |
| Ordinary customer/property/listing relationships | Shubham / Ben | November 13 | Unconfirmed / — | Ordinary API delivery and automatic provisioning |
| Existing Showcase fulfillment | Shubham / Ben | November 13 | Unconfirmed / — | Showcase API path unless the bridge is proven |
| Exact asset, recipient, order and concurrent-edit effects | Shubham / Ben | November 13 | Unconfirmed / — | Production sends |
| Recovery for every write | Shubham / Ben | Before November 23 qualification | Unconfirmed / — | Automatic recovery for unproven operations |
| Media packages, eligibility and Zillow outcomes | Shubham / Ben and Rachel | Before pilot approval | Unconfirmed / — | Benefit claims |
| Notifications, cost, rights and support | Ajay / Matt and Rachel | Before pilot approval | Unconfirmed / — | Pilot acceptance |
| Emergency correction/removal | Shubham / Ben and Rachel | Before pilot approval | Unconfirmed / — | First production pilot |
| Bridge and primary/backup operator readiness | Shubham; Ajay appoints operators | Before pilot approval | Unconfirmed / — | Unsupported Showcase steps |
| Infrastructure logs, queue, shared rate limiter and alerts | Shubham | Before pilot approval | Unconfirmed / — | Provider activation |

Allowed evidence states: **Unconfirmed → Demonstrated in sandbox → Verified in production**. Every advancement includes exact environment, scenario, responsible person, observed time, IDs/revision where applicable, expected versus actual result, and a stable evidence link. Do not use documentation links as demonstration evidence.

## Capability matrix

Create separate rows for each workflow and format/provider. Initial rows are all unconfirmed.

| Capability | Aryeo ingestion/storage | Intended Zillow listing | Requested product requirement | Additional proof |
| --- | --- | --- | --- | --- |
| JPEG / PNG photos, separately | Unconfirmed | Unconfirmed | Unconfirmed | Exact ordering, quality/HD behavior, branding |
| JPEG / PNG floor-plan images, separately | Unconfirmed | Unconfirmed | Unconfirmed | Does not establish interactive floor plan |
| MP4 and other video formats, separately | Unconfirmed | Unconfirmed | Unconfirmed | Size, processing, playback, update behavior |
| Zillow 3D Home tour | Unconfirmed | Unconfirmed | Unconfirmed | Existing tour ownership, interactive plan, Showcase package |
| Matterport / iGUIDE, branded and MLS URLs separately | Unconfirmed | Unconfirmed | Unconfirmed | Each URL/provider/product combination |
| Staging | Not a generic file transfer claim | Unconfirmed | Unconfirmed | Eligibility, room classification, processing and observable availability |

No automatic sending is enabled by a successful row. Production qualification still requires the full register, explicit pilot allowlists, distribution permission and supervised window.

## Recovery qualification

For each operation, retain pre-operation inventory and interrupt the response **after acceptance**. Demonstrate exactly one matching result and no repeated email/action. Test delayed visibility and multiple candidates; neither is a safe retry signal.

| Operation | Intended reconciliation evidence | Result |
| --- | --- | --- |
| Job creation (future provisioning adapter) | Unique opaque internal-note marker in paginated company Jobs, verified company/property | Unconfirmed |
| Place creation (future provisioning adapter) | Known Job, exact property, unique Place | Unconfirmed |
| Ingest intent | Exact private operation-specific source URL across all pages | Unconfirmed |
| Link | Known mapping or exact URL/type/company and pre-write inventory | Unconfirmed |
| Place Asset | Known Place/asset, correct section/presentation/order | Unconfirmed |
| Delivery creation | Known Job, undelivered Delivery, baseline and no concurrent activity | Unconfirmed |
| Delivery execution | Recorded Delivery is delivered, exact expected asset associations; email evidence separately | Unconfirmed |

## Required combined scenarios

- Provider accepts delivery, response disappears, worker restarts, bridge is available: reconcile without second delivery.
- Ingestion completes, then permission/override is revoked: stop final delivery and future downloads.
- Approval followed by recipient/client/unit/company-generation change: invalidate old approval.
- Old export resumes after cancellation or newer media: block and preserve receipts/history.
- Grant expires while intent is pending: reconcile original intent; no blind recreation.
- Outside actor adds, deletes, moves or replaces media between review and delivery: block when detected; document residual unobservable race.
- Partial staging followed by a revised package: account for every old staged object before proceeding.
- Duplicate/out-of-order webhook, revoked token, concurrent refresh, provider 429 and SQLite contention: no duplicate write.
- Pause/resume, unavailable Mac, expired browser session, manual executor handoff: verify owner, next action and alerts.
- Cancellation before transfer, during ingestion, after Aryeo delivery and after Zillow publication: prove appropriate correction/removal evidence.

## Pilot ledger and acceptance

Ten qualifying shoots: five ordinary, five existing Showcase; at least five business days of observation. Record every attempted export and resumption, including failures. Use export attempts, case history, evidence and metrics; keep linked evidence for provider-assisted work.

| Per case | Required observation |
| --- | --- |
| Identity | Correct customer, recipient, property/unit, request/listing, environment and exact media revision |
| Package | Approved versus delivered asset IDs/types/order/hashes; retained baseline media accounted for |
| Effort | First attempt result, every recovery, operator minutes |
| Timing | Approval → Aryeo, time awaiting live listing, eligible listing → verified benefit |
| Customer experience | Expected and actual emails, portal/account access, any charge/order effect |
| Benefits | Actual verified result among eligible listings; separate unavailable/not eligible outcomes |

Wrong destination, unauthorized release or duplicate delivery pauses that workflow. Reopening requires a documented fix, targeted regression and **two distinct fresh successful supervised runs** covering the failure. Case resolution does not automatically unpause. Report individual cases/durations/exceptions; ten shoots do not establish a population reliability rate.

Milestone reviews: November 16 (contract/access), November 23 (sandbox/production pilot readiness), November 30 (pilot evidence review), all conditional on partner readiness. Pilot acceptance and automation acceptance are separate. Automation additionally requires enforceable provider package protection, proven recovery/authorization, no unresolved integrity incidents and independent client/workflow/media-capability controls. The current adapter keeps automation disabled.

## Commercial sign-off — Ajay

Written exclusivity scope (feature/geography/duration/claims), account/listing/media/delivery/resend charges, sandbox/pilot billing, media rights and withdrawal, support ownership and beta-change notice. Before expansion, approve either demonstrated email suppression or continued disclosed dual-email behavior. Future suppression is not assumed.
