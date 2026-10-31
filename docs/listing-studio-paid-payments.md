# Listing Studio payment adapters

Paid creation is disabled unless `listing-studio.paid_enabled`, `paid_checkout_enabled`, and existing `stripe_enabled` are all true. Existing subscription synchronization, refunds, private history, and billing management remain separate from new-purchase availability. No new real charge/refund is needed to run the tests.

`routes/listing-studio-payments.php` belongs inside the normal authenticated, email-verification-enforced `listing-studio/client` group. It additionally requires `EnsureListingStudioClient`, including rejection of staff impersonation. No phase-one verification exemption is extended.

## Client contract

- `POST orders/{orderId}/checkout`, UUID `Idempotency-Key`.
- `POST subscription-checkout`, `{planCode: starter|pro|studio}`, UUID `Idempotency-Key`.
- `POST billing-portal`, no client-supplied customer or return URL.

Success uses `{success:true,data:{orderId,status,url,expiresAt,nextAction}}`. A funded order returns its current safe `order` DTO, `url:null`, and `nextAction`; redirect never settles money. URLs are restricted to HTTPS `checkout.stripe.com` or `billing.stripe.com` respectively. Failure uses `{success:false,code,message,data}` with sanitized copy.

The client retains its attempt key across refresh/uncertainty. `checkout_expired` and `checkout_failed` with `data.retryWithNewKey:true` are the only responses permitting a new key. `awaiting_reconciliation`, `checkout_in_progress`, and generic transport errors retain the same key and order. A different payload under the same key conflicts. A different key cannot supersede an unresolved attempt.

## Ownership and financial boundaries

The authenticated client, configured Stripe account/mode, immutable order total/currency, exact customer binding, checkout attempt/session, line item, and successful PaymentIntent must agree. Subscription creation additionally checks the canonical configured monthly price and rejects local or remote active/incomplete subscriptions. New customer bindings use a random durable ID and authenticated client ID; changed Stripe email never transfers dashboard ownership. Legacy subscription synchronization remains intact for older customers without a new authenticated binding.

Short `LockedWrite` transactions persist the customer/create/refund marker and exact request before any network call. Database cache locks serialize client checkouts and order refunds. Stripe calls use bounded timeouts and no automatic POST retries. The same durable Stripe key and exact body can be retried for less than 23 hours; after that, only canonical retrieval/reconciliation is allowed. A cached provider 500 is not treated as proof that no checkout/refund exists.

`StudioPaymentSync` is called only after existing webhook signature verification. It handles locally owned checkout/refund events before the existing subscription and shoot handlers. It retrieves canonical objects, handles asynchronous payment completion, and records processed event IDs. The core's `confirmPayment`/`confirmRefund` methods provide economic ID deduplication and own all monetary counters/outbox transitions. Browser retrieval can only report awaiting_webhook and never settles or dispatches editing. Only verified events enter core settlement. No artificial shoot/payment is created.

`StudioRefundService` implements the core refund dispatcher. One failed order creates one persisted operation tied to the durable outbox UUID. Pending/`requires_action` refunds remain pending; failed/canceled refunds do not report returned funds. Only canonical `succeeded` receipts increase refunds. Partial/external Stripe refunds are bound to a known paid PaymentIntent and exact account/customer/currency. Repeat delivery and recovery reuse the same operation/refund ID.

Before a verified purchase can fund fulfillment, the adapter reads every canonical refund page for the exact PaymentIntent with expanded charge/payment ownership. The required core evidence is `refundsVerified:true` and `refunds:[...]`, using the existing refund-evidence shape with persisted UUID operation/attempt IDs. At most 100 distinct refunds and 10 pages are accepted; incomplete, duplicate, excessive, or mismatched data fails closed. All network reads finish before the core atomically applies payment and refund receipts and decides whether processing is allowed. This catches an external refund delivered before the payment webhook without using the refund event to infer purchase success. A pre-fulfillment pending/succeeded refund blocks queue creation; failed/canceled refunds do not remove money. Existing completed delivery state remains independent of refund state.

Checkout attempt allocation rechecks the owned pending card order and its awaiting-funding item inside the same write transaction as attempt creation. Safe unpaid cancellation therefore either wins before allocation, or sees the persisted unresolved attempt and refuses cancellation.

Ambiguous subscriptions block every new purchase and first checkout submission. Exact replay of a saved session may retrieve it with a canonical GET; an uncertain submission without a session ID stays held without another create POST. The billing check runs again immediately before the durable create checkpoint. Existing billing management, verified payment events, and read-only reconciliation remain available. Active primary clients may have secondary roles; new authenticated billing bindings preserve this eligibility on later subscription synchronization, while historical email-only enrollment retains its existing checks.

## Reconciliation

`php artisan listing-studio:reconcile-payments --limit=50` inspects canonical Checkout objects using GETs and performs zero database writes, including no cache-lock writes. It reports counts of pending verified events or unresolved ownership; it never settles, dispatches, or creates a new idempotency key.

Explicit `--event=evt_...` recovery retrieves that Stripe event through the authenticated API, verifies account/mode and exact event ID, then runs the same durable event handler and economic deduplication used by signed webhooks. This write mode is explicit. Older unresolved customer/refund requests remain reserved for review; no blind reissue after Stripe idempotency retention. No automatic schedule is installed by this patch.

## Verification

Run `ListingStudioPaidPaymentsTest`, existing `ListingStudioStripeSyncTest`, and `ListingStudioSubscriptionRefundSyncTest`. The fake gateway rejects provider calls inside business write transactions, and tests cover timeout replay, stale keys, signed/forged events, account/mode/customer/amount mismatch, concurrent checkout locks, expired sessions, duplicate deliveries, refund states, subscription price checking, and email-independent ownership. The financial core's integrated tests additionally verify monetary effect uniqueness and reservation/outbox behavior.

References checked against the installed Stripe PHP 19.4-compatible SDK and API version `2025-09-30.clover`:

- [Checkout fulfillment](https://docs.stripe.com/checkout/fulfillment)
- [Idempotent requests](https://docs.stripe.com/api/idempotent_requests)
- [Refund object](https://docs.stripe.com/api/refunds/object)
- [Create refund](https://docs.stripe.com/api/refunds/create)
- [Portal sessions](https://docs.stripe.com/api/customer_portal/sessions/create)
- [Refund list pagination](https://docs.stripe.com/api/refunds/list)
- [Expanding list objects](https://docs.stripe.com/expand)
