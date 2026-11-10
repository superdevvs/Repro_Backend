# RePro × Zillow/Aryeo delivery implementation

Implementation baseline: 2026-11-10. This is a staff-assisted, disabled-by-default integration. It is not evidence of partner qualification or production readiness. The separate [kickoff brief](aryeo-api-kickoff-2026-11-10.md) contains meeting-specific information; the [qualification register](aryeo-api-qualification.md) contains launch gates.

## Ownership and boundaries

RePro owns bookings, prices, payments, client delivery, release overrides and approval. Neither a provider webhook nor a successful Aryeo delivery changes a RePro invoice. The API and existing Showcase connector use the same `AryeoCatalog` release decision. Whole-shoot overrides release approved assets; service overrides release only that service's assets. Unreleased components, cancelled services, hidden/raw/quarantined/reference files remain excluded. Approved extras retain the existing explicit policy. Distribution authorization is independent of financial release.

The API currently requires a **verified existing Job, Place and legacy Aryeo listing relationship**. It validates company/Job/Place relationships through provider reads and requires staff evidence for the customer, recipient, unit and public listing relationship. The published v2beta1 surface does not expose the missing customer-association flow. Creating a Job alone does not create that relationship. Automatic Job/Place/customer provisioning is deliberately not wired into the executable path. Job/Place recovery specifications are retained for a future qualified provisioning adapter.

Existing Showcase requests retain their current bridge. New API sends require that request's verified identity and requirements. Neither a heartbeat nor historical receipts count as current executor qualification. A receipted API destination requires an explicit reconciled handoff before the bridge can use it. Destination ownership also blocks another shoot targeting the same provider Job/Place.

## Records

| Record | Purpose |
| --- | --- |
| Installation | Environment, immutable company identity, encrypted delegated credentials, connection generation, enrollment, pilot allowlists, pause controls, qualification register and media matrix |
| Destination | Shoot/unit/client, workflow/request, exact provider identities, recipients, public listing URL, consent evidence, hold and executor ownership |
| Export | Immutable snapshot and revision hash, complete pre-export remote inventory, approval, editing window, independent outcomes, receipt and metrics |
| Asset | Exact file/link revision, original SHA-256 and size, MIME format, presentation/order, provider mapping, ownership and private download grant |
| Operation | Durable intent, fingerprint, encrypted request/baseline/result, sent/unknown/confirmed state, sanitized request ID |
| Evidence | Append-only feature observation tied to exact listing/revision, source, verifier and observation time |
| Case | Cancellation, correction/removal, uncertain operation, integrity incident or audited executor/approval action |
| Attempt | Every staff submission/resumption, start/end, result and blocker, including failed attempts |
| Alert | In-app unknown/stalled/overdue/bridge-executor alert, accountable owner and acknowledgement |

Credentials, operation request/results and original source URLs are encrypted at rest with Laravel's application key and omitted from staff JSON. Support packets omit snapshot recipient PII and download grants. Preserve the application encryption key and encrypted history through rollback.

## Staff workflow

1. Settings → Integrations → **Aryeo delivery**: create a paused sandbox connection using the verified company UUID. Install the application if needed, then authorize the delegated user. Reconnect always pauses writes and increments the authorization generation; it cannot repoint existing destinations.
2. Record actual demonstrations, named owners, deadlines and evidence. Set program enrollment and explicit client/shoot pilot allowlists. Qualify each MIME format or tour provider separately for ingestion, listing distribution and product eligibility. A static floor-plan image does not establish an interactive floor plan; a generic tour does not establish Showcase eligibility.
3. In the shoot's Tours tab, select the correct unit and bind the verified destination. Record exact customer ID/email, public listing URL, relationship evidence, distribution permission and disclosed dual-email behavior. An awaiting-listing record holds work; bind a new verified destination when the listing exists.
4. Preview approved originals. The snapshot binds identities, authorization generation, file hashes/revisions/order, links, consent and release state, purpose, recipient and email/order effects. Preview also records every page of provider inventory. Unexplained undelivered media blocks approval.
5. Review the package and acknowledge a named no-external-editing window. The UI defaults to two supervised hours; the API caps a window at eight hours. This reduces outside-edit risk; it is **not atomic protection**. Review again to renew a window, keeping the same revision. Material changes require a new revision and cleanup of any earlier staged package.
6. Submit. A dedicated worker advances one file/attachment or bounded delivery step at a time. It persists intent before each write, polls ingestion, preserves order, compares complete inventory, rechecks original hashes and provider relationships, then sends all undelivered media on that Place. Verify the resulting Delivery and exact associations.
7. Record separate evidence for listing association, visible media, branding, HD, staging and notification behavior. Aryeo delivery alone never marks those outcomes verified. Preserve prior evidence when a later revision is approved.
8. Record operator minutes, recovery actions, notification counts, live-listing time and benefit-verification time. Export the support packet for every exception. Account for all attempted submissions rather than reporting only successful shoots.

## Authorization, downloads and webhooks

Delegated authorization uses S256 PKCE, browser-bound hashed single-use state, a short-lived RS512 application assertion and rotating refresh credentials. `/me` must confirm the expected company, user principal, application actor and effective scopes. Credential rotation has a short SQLite reservation; a lost response requires reconnection. An old refresh response cannot overwrite a new authorization generation. Install callbacks without offline authorization need the separate delegated-user flow.

Original grants are opaque, repeatable HTTPS URLs, privately retained for exact intent reconciliation. Grant creation freezes a SHA-256-verified private copy under `storage/app/private/aryeo-api/`; subsequent source replacement cannot alter its bytes. Initial lifetime: 48 hours. GET/HEAD and a single byte range are supported. Each request rechecks release, distribution permission, connection and pilot gates; streamed originals recheck bytes and periodically recheck revocation. Cancellation/holds/pause stop future access. Bytes already transferred cannot be recalled. Expiration never creates another ingest intent; reconcile the original intent first.

Webhook receiver: `/api/integrations/aryeo/webhooks/{installation UUID}`. Configure the exact partner-confirmed CloudEvents source and a distinct signing secret per installation. It verifies timestamp/HMAC against the raw body, persists and deduplicates events, then queues read-only reconciliation. `delivery.created` is a trigger, never delivery/publication evidence. Duplicate events with a changed body or installation are rejected. Registration and activation of the provider webhook endpoint remain an operator setup step.

## Recovery and correction runbook

| Situation | Owner / next action | Escalation / release condition |
| --- | --- | --- |
| Write response lost | Shubham; reconcile the exact recorded operation | Immediate in-app alert; staff sends support packet to partner after 30 minutes unresolved |
| Empty lookup or multiple candidates | Shubham; leave Outcome unknown; inspect provider visibility and ownership | No retry or second executor until conclusively resolved |
| Worker unavailable or lease expired | Primary operator; stop prior process, reconcile in-flight writes, record evidence | An expired lease alone cannot release ownership |
| Queued Showcase work without executor | Primary operator, backup appointed by Ajay; restore qualified executor/session | In-app alert after two minutes; UI shows waiting state |
| Staged package cancelled/revised | Shubham; use partner-supported abort/cleanup procedure on verified RePro-owned objects | No later export until pending intents and undelivered leftovers are accounted for |
| Already delivered/published media | Ajay/customer owner and Shubham; arrange supported correction/removal | Record downstream evidence; deletion of a Place Asset is not proof of retraction |
| Wrong destination, unauthorized release, duplicate delivery | Shubham investigates; Ajay owns customer decision | Workflow paused; resolution requires fix, regression evidence and two distinct successful supervised-run evidence links; hold/pause remain until explicit review |
| Benefits overdue after delivery | Shubham; check eligibility/live listing, then prepare packet | Partner escalation with exact revision, provider IDs, timestamps and feature evidence |

Recovery lookups: Jobs by opaque internal-note marker and company; Places by known Job plus exact property; ingest intents by exact private source URL; Links by URL/type and pre-operation inventory; attachments by Place/asset/section/presentation; undelivered Delivery by known Job under the supervised editing window; send by known Delivery status and exact media associations. Empty results never authorize recreation. Recovery must be demonstrated in sandbox before qualification is recorded.

Correction cases intentionally use **staff/partner-assisted provider cleanup**. This release does not expose an automatic destructive delete/abort/retraction path. The operator must confirm ownership, references, delivery history and downstream result. Case resolution requires evidence, no unknown operation or active executor, and a fresh inventory without undelivered leftovers. Known provider-created foreign media is preserved. Remote retained original files may remain even after attachment cleanup; record those objects in the evidence.

The pause switches stop subsequent writes and future grant access while allowing read-only reconciliation. They cannot undo a request already in flight. Global `ARYEO_API_WRITES_ENABLED=false` is the mutation rollback switch. Keep mappings, operations and receipts; never roll back by deleting them. Returning a destination to the bridge uses explicit handoff with stopped-worker evidence and complete inventory reconciliation.

## Configuration and deployment

Defaults: provider access, writes and production all disabled; installations initially paused. Never infer a sandbox URL.

```dotenv
ARYEO_API_ENABLED=false
ARYEO_API_WRITES_ENABLED=false
ARYEO_API_PRODUCTION_ENABLED=false
ARYEO_API_SANDBOX_URL=
ARYEO_APPLICATION_CLIENT_ID=
ARYEO_APPLICATION_KEY_ID=
ARYEO_APPLICATION_PRIVATE_KEY_PATH=
ARYEO_API_WEBHOOK_SECRETS={"installation-uuid":"unique-provider-secret"}
ARYEO_API_WEBHOOK_SOURCE=
ARYEO_API_PRIMARY_OPERATOR=Shubham
```

Register `https://reprodashboard.com/api/integrations/aryeo/oauth/callback`. Provision the signing key outside the source/public tree with minimum required permissions. Production API base is fixed at `https://api.aryeo.com/v2beta1`; obtain the sandbox endpoint from the partner. Application credentials never go to the browser.

Before any provider activation, configure nginx/proxy/APM to omit OAuth query strings and `/integrations/aryeo/download/*` opaque grants from access logs and request captures. Application telemetry excludes authorization/download routes and redacts credential/source-URL fields. Verify those infrastructure controls with a disposable grant. No production infrastructure or secrets are changed by this branch.

Provision the private original-copy directory for the queue worker and PHP-FPM with group `www-data`, directory mode 2770 and file mode 0660 (`ARYEO_API_STORAGE_GROUP` overrides the group). Qualify disk capacity with representative media. The monitor removes private copies seven days after revocation for completed/cancelled exports; unresolved exports are retained.

Run `php artisan queue:work database --queue=aryeo-api --tries=1 --timeout=180` under the normal service manager; database `retry_after` must exceed 180 seconds (the current repository default is 2100). Start `aryeo:monitor-api` via the existing every-minute Laravel scheduler. Alerts are in-app and must be watched by the named operator; this branch does not send Slack/email/pages. Test alert visibility and primary/backup access before pilot acceptance. Shared credential rate limiting uses the configured shared Laravel cache; **do not use a process-local array cache in production**.

Use clean worktrees and the project's `.devin/workflows/deploy.md` guarded `Deploy-App.ps1 -PreparedRelease` process, including Linux checks, migrations, CI and behavior verification. Do not use raw sync as a deployment shortcut. Activate sandbox first; activate a selected production pilot only after the qualification register and customer terms pass. Automation remains unavailable because this v2beta1 adapter has no enforceable package protection. A database capability flag cannot enable it.

## Provider-dependent work still required

- Demonstrate authorization/rotation/revocation, exact ordinary customer/listing association and existing Showcase fulfillment using partner access.
- Implement any additional provisioning/relationship adapter only after that flow is demonstrated. The executable path currently binds pre-existing verified destinations.
- Qualify all recovery lookups with responses interrupted after acceptance, real URL ingestion/expiration, MIME behavior, ordering, complete inventory and notification effects.
- Establish real correction/abort/removal procedure and any retained-source-file cleanup; staff-assisted cleanup is currently required.
- Prove Mac executor availability and both operators' access for every unsupported Showcase step. API compatibility does not establish bridge readiness.
- Verify real webhooks, infrastructure secret redaction, credential cache sharing and alerts under the deployed queue/scheduler.
- Obtain costs, rights, exclusivity and permitted claims in writing. Run ten qualifying shoots and observe at least five business days.
- Add a future protected-package adapter and capability/client/workflow automation controls only when the provider supports enforceable delivery scope and qualification passes. No unattended sends are implemented here.

## Source contract

Reviewed beta OpenAPI generated 2026-11-04, retrieved 2026-11-10: [reference](https://beta-docs.aryeo.com/apis/vendor-platform/openapi), [idempotency](https://beta-docs.aryeo.com/apis/vendor-platform/guides/fundamentals/idempotency), [concurrency](https://beta-docs.aryeo.com/apis/vendor-platform/guides/fundamentals/concurrency-control), [delegated authorization](https://beta-docs.aryeo.com/apis/vendor-platform/guides/authentication/tokens/application-user-token), [webhook events](https://beta-docs.aryeo.com/apis/vendor-platform/guides/webhooks/events). The contract has no request idempotency/conditional writes and sends every undelivered Place Asset. Public [Media Advantage](https://www.aryeo.com/products/zillow-media-advantage) benefits do not prove RePro exclusivity. [Showcase requirements](https://grow.zillow.com/agents/showcase-getting-started-guide/creating-listings) and [media/staging guidance](https://grow.zillow.com/agents/showcase-getting-started-guide/booking-media) are product guidance, not API qualification.
