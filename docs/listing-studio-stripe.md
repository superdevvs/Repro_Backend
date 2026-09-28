# Listing Studio website subscription integration

Clients open the existing Listing Studio website from the dashboard navigation. Rep, admin and super admin users retain the dashboard dialog; its **Subscriptions** tab lists synchronized website subscriptions separately from manually reviewed requests. Reps can only see subscriptions linked to their assigned clients.

The website checkout source uses the three price IDs in `config/listing-studio.php`. The dashboard integration accepts those Stripe subscriptions without a shared dashboard login token. Stripe's customer email links an eligible existing client or creates a new client after an active subscription has a confirmed paid invoice (including a fully discounted paid invoice). Existing passwords, email verification, roles, profiles and shoot-billing customer mappings are preserved. Ambiguous, staff, mixed-role, deleted or unavailable account matches require administrator attention.

The updated Lovable preview (`mat_36mkh80wsc899b7492996g8p2s`, `#plans`) was browser-checked on September 28, 2026: its Starter Subscribe button opens live Stripe Checkout for Listing Studio Starter at $49/month. No payment was completed. The dashboard client link uses that exact preview version. This dashboard release does not edit the website. Live subscription fulfillment still requires deployment, webhook activation and a signed delivery check.

New clients receive the existing secure password-setup and email-verification flow. Account setup has an encrypted, durable delivery checkpoint and a separate status visible in the staff subscription card. Failed deliveries retry; uncertain interrupted deliveries require reconciliation rather than blindly resending. No setup URLs are exposed in subscription responses.

## Configuration and activation

Use the normal guarded prepared-release workflow for backend and frontend. Run the database migration before enabling synchronization. The feature defaults to disabled:

```dotenv
LISTING_STUDIO_STRIPE_ENABLED=true
LISTING_STUDIO_STRIPE_MODE=live
LISTING_STUDIO_STRIPE_ACCOUNT_ID=acct_15OuBBLsiebrQZS4
```

`LISTING_STUDIO_STRIPE_SECRET_KEY` defaults to the existing `STRIPE_SECRET_KEY`. The existing shared `/api/webhooks/stripe` endpoint verifies its existing signing secret before dispatching Listing Studio events; shoot checkout and refund processing remain in place. Do not rotate its signing secret when adding events.

After deploying, rebuilding the configuration cache and restarting queue workers, verify the existing endpoint using the read-only default command. Use the actual endpoint ID from the account:

```bash
php artisan listing-studio:configure-stripe-webhook we_1UC8O5LsiebrQZS4jESNFh7s
php artisan listing-studio:configure-stripe-webhook we_1UC8O5LsiebrQZS4jESNFh7s --apply
```

The command checks the Stripe account, mode, recurring prices, HTTPS dashboard endpoint and migration readiness. Apply unions the required event types with existing enabled events, including shoot checkout/refund events. It does not create another endpoint, rotate secrets, create checkouts, or charge customers. A subsequent signed delivery verifies the deployed HTTP path. Confirm a real Stripe delivery in the Stripe dashboard when the first website subscription is completed.

If a separate endpoint is required, `/api/webhooks/stripe/listing-studio` uses `LISTING_STUDIO_STRIPE_WEBHOOK_SECRET`; configure its signing secret independently. Never send an unsigned browser success redirect to either webhook.

## Operations

- Keep the normal Laravel scheduler and default queue worker running. The scheduler runs `listing-studio:recover-account-setups` every minute.
- Admins and super admins use **Listing Studio → Subscriptions**. **Needs attention** includes account matching and account setup delivery failures. Manual signup/change requests remain in **Review requests**.
- Retry a canonical subscription after correcting its customer email or account conflict: `php artisan listing-studio:sync-subscriptions --subscription=sub_...`.
- Import an existing account's subscriptions one page at a time with `php artisan listing-studio:sync-subscriptions --limit=100`; follow its printed `--after` cursor when present. This can create new client accounts and enqueue account setup for confirmed enrollments, but does not mutate Stripe.
- Run `php artisan listing-studio:recover-account-setups --limit=100` to recover pending/retryable setup deliveries. For `needs_attention` after an interrupted send, inspect provider delivery first; do not clear a successful channel checkpoint or reset its setup links.
- Turning off `LISTING_STUDIO_STRIPE_ENABLED` and rebuilding the configuration cache stops new subscription synchronization. Existing billing records and accounts remain intact.

## Monthly credits and refunds

Paid monthly invoices grant Starter $60, Pro $135, or Studio $375 in non-cash Studio credits. Fully discounted paid invoices also receive the plan allowance. Grants are keyed by the canonical invoice and subscription line, so repeated events cannot grant twice. Unpaid invoices, unrelated invoice items and unmatched accounts receive no credits.

Credits expire at the end of their billing period; they do not roll over. A changed billing anchor selects the new period instead of combining an old unexpired balance with the new allowance. For mid-period changes, each signed Stripe proration line is converted using that plan's credit-to-price ratio, rounded to credit cents. The old plan's negative line reduces credits and the new plan's positive line adds them. Full-period grants remain the advertised allowance.

Succeeded refunds proportionally reduce the credited amount for their original invoice. Multiple partial refunds use the cumulative succeeded amount divided by the original amount paid, capped at a full reversal. Pending, failed and canceled refunds do not remove credits. A refund for an expired period cannot remove the next period's credits; refund and invoice events can arrive in either order. Refunding a subscription payment does not itself cancel the Stripe subscription or change a dashboard role.

The staff card displays available credits, the monthly allowance and expiry. This release records automatic grants, prorations and refunds. Existing media workflows have no Studio-credit pricing or spending integration, and this release does not introduce one or enable client AI Studio access. Shoot invoice balances and shoot refunds stay separate.

The refund resolver pins its read API version and validates the refund, charge, invoice payment and subscription identities before acknowledging the event. Unknown refunds continue through the existing shoot reconciliation guard. Website-originated subscription changes and cancellations synchronize through the canonical Stripe subscription record.

For test mode, configure the three `LISTING_STUDIO_*_TEST_PRICE` variables with that account's test prices and use a test signing secret. Never use live payments for automated tests.
