# Listing Studio delegated billing

The existing client API remains the owner of client purchases and private media. Delegated routes add an explicit staff actor; they do not impersonate the client or provide staff access to the client's Stripe Checkout, billing portal, saved payment details, or unrelated photos.

## Authority and rollout

- Only an active primary sales representative (existing salesRep/salesrep/rep/representative aliases) or superadmin can use /api/listing-studio/staff. Secondary roles never grant financial delegation. Ordinary admins are excluded.
- Either authorized role may select any active primary client, upload a photo for that client, and spend that client's existing editing credit after explicit named-client confirmation. Superadmin alone manages subscription changes.
- The original auth and email verification policy applies. No paid route receives the trial claim exemption. Impersonation headers and resolved impersonation state are rejected.
- LISTING_STUDIO_DELEGATED_ENABLED defaults false. Staff list/account/catalog/own history remain readable while new uploads, quotes, orders, requests, email sends, and new delegated Checkout submissions are disabled. Exact accepted-order replay and known-session retrieval retain the existing identity.
- LISTING_STUDIO_SUBSCRIPTION_CONTROLS_ENABLED separately defaults false. The subscription service controls first upgrades and renewal changes; accepted operations use canonical read-only recovery.
- Existing paid, per-service, Checkout and Stripe configuration gates still apply. No credit packs are introduced.

## API contracts

All JSON uses {success:true,data:...}, private no-store responses, authenticated same-origin routes. Staff endpoints below are under /api/listing-studio/staff/clients/{clientId}:

- GET /api/listing-studio/staff/clients?q=&page=: {clients:[{id,name,email}],pagination:{page,perPage,total,hasMore}}. Search treats percent and underscore literally.
- GET /catalog and /account: the existing catalog/account data. Account adds client:{id,name,email}, management:{canPurchase,canManageSubscription,subscription:{pendingChange,capabilities}}, and pendingBilling:{count,requests}. Staff card/portal capabilities are false. Own client account also includes subscriptionManagement:{pendingChange,capabilities}; pending change is visible, while all administrative capabilities remain false.
- POST /uploads: multipart photo, UUID Idempotency-Key. GET /uploads/{id}/preview is restricted to this actor's upload for this client.
- POST /quotes: existing quote payload; sources must be this actor's client upload or completed order. Delegation does not open private claimed trials or unrelated client photos.
- POST /orders: existing quoteId/fundingMethod plus creditConsent:true for credits, UUID Idempotency-Key. Uses the existing reservation/outbox pipeline. A card order remains awaiting_funding.
- GET /orders and /orders/{id}: only this actor's orders for the target client. Existing order.actions adds canRequestPayment; canCheckout/canCancel are false. GET /orders/{id}/preview is equally scoped. Full downloads remain in the client's existing area.
- POST /billing-requests with UUID Idempotency-Key: {kind:'order_payment',orderId}, {kind:'subscription_purchase',planCode}, or superadmin {kind:'subscription_upgrade',planCode}. Returns {request,created}. Creates only local intent; no Stripe call or payment.
- GET /billing-requests and /billing-requests/{id}: only actor-owned requests. Lists use {requests,pagination}; detail uses {request}.
- POST /billing-requests/{id}/email with UUID Idempotency-Key: durable registered-email delivery; returns 202 {request,delivery:{status,created}}. Caller-supplied recipients are ignored.
- POST /billing-requests/{id}/cancel: cancels only a request with no unresolved confirmation; {request}. Cancellation shares the confirmation mutex and checks durable subscription submission state.
- POST /subscription-actions with UUID Idempotency-Key: superadmin action schedule_downgrade|cancel_renewal|undo_cancel|undo_downgrade and optional planCode. Returns the subscription service's {status,action,nextAction}.

Client-owned review routes are /api/listing-studio/client/billing-requests/{id} GET, /confirm POST, and /cancel POST. GET returns {request,review}; review includes reviewId, confirmationToken, amountCents, currency, planCode and expiresAt, plus canonical upgrade details when applicable. GET does not charge. POST confirm requires {reviewId,confirmationToken,acceptCharges:true}; it uses the request's stable server checkout key rather than accepting a new client payment key. Only the authenticated target client receives the resulting approved Stripe redirect URL. Upgrade GET recovery may return a canonical existing invoice URL for an explicit Continue payment action; it never starts a new charge. Safe subscription/Checkout error codes and HTTP statuses are preserved by the new controllers. A copied app link /listing-studio/payment-requests/{id} is an identifier, not an authorization token.

Request DTO: id, kind, status, client:{id,name,email}, actor:{id,name}, orderId, planCode, amountCents (null until canonical upgrade review), currency, expiresAt, createdAt, completedAt, appUrl, actions:{canShare,canEmail,canCancel,canConfirm}, email:{status,lastSentAt}. Request statuses: pending, processing, completed, expired, cancelled, needs_attention. Staff canConfirm is always false. Email status is null, queued, submitting, sent or needs_attention.

## Durability and privacy

- Requests expire 24 hours after creation. Unsubmitted price reviews expire 10 minutes. A submitted operation is preserved beyond link expiry and reconciles using its existing checkout/action identity. An allocated but conclusively unsubmitted operation can be cancelled or expired only under the confirmation mutex, after rechecking all submission/session markers; its unused attempt is closed locally. A busy confirmation is left unchanged. Creation replay precedes expiry, feature and quota checks.
- An order payment request also recognizes accepted Checkout attempts started through the client's own order screen. Its cancellation/expiry checks share the client Checkout mutex, and any accepted or ambiguous same-order submission is held. The request shows `processing` / `await_payment` with no second confirmation action; the existing order payment route remains the recovery entry. Verified order payment converges even an older cancelled/expired request to `completed`, without inventing consent to that request. Email admission and delivery both recheck order eligibility.
- Existing quote/order/upload/checkout rows gain nullable actor_id. Checkout attempts snapshot the request actor for a delegated payment and the client for a self-service checkout; exact replay preserves that attribution. The client remains owner; accepted items, reservations, private source descriptors and worker authorization keep their original client linkage. Actor scope is rechecked inside quote/order transactions, not only HTTP controllers.
- New request admission is bounded to 20 active intents/client. Existing order/source caps remain. The first admission SQL acquires SQLite's writer before taking a replay/quota snapshot.
- Confirmation rechecks the initiating actor's current role/status and beneficiary. Checkout checks this again after canonical network reads, inside its final submission-marker write. No external call occurs inside a financial transaction.
- Email is limited to 3 intents per client in a rolling 24 hours, with a 60 minute cooldown, shared across staff actors and requests. Exact email-key replay returns the same delivery. The worker verifies the registered address still matches, uses the existing branded email layout and MessagingService, and records submission before external delivery. Unknown sends are held for review, never automatically resent.
- listing-studio:billing-emails-dispatch --limit=100 recovers bounded queued intents every 5 minutes through the existing Laravel scheduler; stale submitting intents become needs_attention. It does not create purchases or process provider images.
- No payment-review token, private storage path, Stripe identity, or provider exception text is serialized in staff DTOs. Existing user activity records preserve actor/client/request/order attribution.

## Validation

Targeted tests cover client and actor isolation, primary roles and impersonation, active-client checks, exact financial replay, real private uploads and credit reservations, client consent and real Checkout adapter recovery, actor revocation during canonical customer lookup, price drift and expiry, registered-email rendering/dedupe/unknown outcomes, and independent file-SQLite writers contending for request and email limits. Existing financial/payment/processing/upload-race suites remain required. All tests use local fakes; no live Stripe or image-provider calls are part of this change.
