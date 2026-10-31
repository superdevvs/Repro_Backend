# Paid Listing Studio processing

Paid processing is separate from the anonymous trial allowance and the three-free-team offer. New paid purchases, each AI service, and human revisions remain disabled by default. Existing dashboard authentication and email-verification policy applies to these routes. Client requests cannot supply provider routes, prompts, storage paths, assignee lists, or team scope.

## Ownership and media

`POST /api/listing-studio/client/uploads` accepts one multipart `photo` and a UUID `Idempotency-Key`. JPEG, PNG and WebP are limited to 10 MiB and 40 megapixels and are fully decoded before acceptance. Exact same-key/bytes replay returns the same upload; changed bytes conflict. The configurable active-source cap defaults to 20 per client and counts unused active uploads plus distinct sources pinned by unpaid card orders, even after upload expiry. Reusing one source occupies one source slot; the financial core separately caps active unpaid card orders. Funded history is excluded. Never-paid cancelled/failed sources stay counted until their expired bytes are safely pruned. Unused uploads expire after 24 hours.

The private `listing_studio_orders` disk is outside the public storage link. Deployment provisions only an absent root with group `www-data`, mode 2770. Files use 0660; the helper explicitly prepares each new directory so FPM umask 0022 cannot remove worker group-write access. Existing unsafe directories and symlinks are rejected, never recursively repaired. Real-adapter tests use separate FPM and worker UIDs in the shared group.

The source resolver validates client ownership and readiness before quoting, then rechecks database ownership/expiry inside financial acceptance. An accepted item pins its upload. Input bytes are copied outside transactions to `items/{itemId}/input.*` and the property original to `property-original.*`; the core verifies unchanged hashes before replacing the snapshot. Human revisions snapshot an owned completed paid item or privately claimed completed trial. Their service is inherited from the source for pricing.

Preview/download responses require authenticated ownership and use `private, no-store`. DTOs expose availability flags, never storage paths or provider URLs. Client original preview means the current order input; team original means the property original, while team result means the prior delivered edit being revised.

## AI execution and recovery

The financial outbox calls `ListingStudioPaidFulfillment` after its transaction. A stable attempt creates or finds exactly one reciprocally linked workspace. A short preparation lease has a separate random lease token; only that token can release it. Queue uncertainty can republish the same workspace/operation. The Studio worker lock serializes provider work.

Four fixed recipes reuse the validated trial processors: staging with furniture removal off and one variation; grass enhancement; preservation-focused decluttering; bright early-blue-hour twilight. The two specialist edits pin Nano Banana Pro 2K. Ordinary Studio routing is unchanged. Paid gates, funded state and active client eligibility are checked before every new provider submission. Accepted checkpoint retrieval can finish while new starts are paused.

Provider submission markers are persisted before requests. Unknown acceptance is held, never converted to a fresh render or released credit. Local output/preview failure is also held. Completed bytes are validated privately before the core captures a reservation and completes the item. The core outcome guard checks reciprocal workspace/output or exact human delivery ownership before settlement.

`php artisan listing-studio:paid-resume ITEM_UUID` is operator recovery of the same held workspace, a terminal workspace with unfinished item finalization, or a processing workspace stale for 150 minutes. It refuses an active worker/lease and unconfirmed submission markers. It does not replace the order, operation, route, or source. A completed workspace with a missing preview retries only local finalization.

## Human revision team

`/api/listing-studio/team/revisions` serves the central paid queue. Admin, superadmin and editing manager can manage null-team tasks; a trusted non-null team restricts access and assignee choices. Editors see only assigned tasks. Sales representatives retain the separate requests/subscriptions workflow.

List/detail return current version, client/assignee, instructions, preview availability, delivery constraints, and server-derived actions. Assignment uses `PATCH /{id}/assignment`; start/hold/fail/cancel use POST. Each mutation requires `expectedVersion` and a UUID idempotency key. Hold/fail/cancel accept fixed reason codes (`quality_review`, `source_issue`, `instructions_unclear`, `unable_to_complete`, `client_cancelled`) and an optional note up to 1000 characters. Only managers can fail/cancel; the financial core rejects any unresolved accepted output.

`POST /{id}/deliver` accepts multipart `photo`, `expectedVersion`, and a UUID idempotency key. It saves one private candidate and durable finalization intent, returning 202 `{task,created}`. Exact replay is checked before stale-version or terminal rejection. A changed file/version cannot reuse the key.

The local finalizer creates the preview and completes item/task/delivery together under one short financial transaction. A failure holds the saved attempt. `POST /{id}/delivery-attempts/{attemptId}/resume` uses current `expectedVersion`, requires no active finalizer, and reuses the same candidate and durable intent. It performs no provider call or new purchase. Task states are queued/assigned/in_progress/delivered/cancelled; exceptional fulfillment state belongs to the item.

Team preview variants are original/result/delivery. Full reference download supports original/result. All media remains private and role-scoped.

## Retention and release checks

`listing-studio:paid-uploads-prune --limit=100` removes expired unused uploads and expired sources whose every accepted reference is definitively cancelled/failed and never paid. It preserves every row and refuses funded references, workspaces, revision tasks/deliveries, or unresolved checkouts; an absent payment schema also blocks accepted-source pruning. The same per-upload lock and short database write guard prevent new source acceptance while cleanup occurs. Filesystem deletion is outside the transaction, and media_purged_at is set only after success. Failed deletion remains counted and retryable; protected rows rotate through the bounded sweep.

Before enabling paid gates: integrate financial and payment adapters, pass the required full backend suite and frontend checks, review the final release, and perform separately authorized payment/provider validation. Unit/feature tests fake provider responses; they do not prove a live charge or live edit.
