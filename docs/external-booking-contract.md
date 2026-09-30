# Marketing website booking contract

`POST /api/external/book-shoot` is called by the marketing site's server-side
proxy. Authenticate with `X-API-Key`; never expose that key in browser code.

Required fields: `client_name`, `client_email`, `address`, `city`, two-letter
`state`, `zip`, and `services: [{id, quantity?}]`. Service IDs must come from the
dashboard catalog. Each service may appear once. Quantity defaults to one;
values above one require the catalog service's `allow_multiple` setting.

## Request identity and retries

Send the original booking UUID as `external_reference` (string, max 100) and
`source: "reprophotos.com"`. Save/reuse this pair and the same payload when retrying
an uncertain network result. The unique pair is reserved in the same transaction
as the shoot. The first successful request returns HTTP 201. A matching replay
returns HTTP 200 with `idempotent_replay: true` and the original `data` snapshot;
it creates no new shoot, account setup messages, or shoot-request notification job.
Reusing the pair with different validated input returns HTTP 409 and a validation
error on `external_reference`. Start a new reference only for a new booking.

The response continues to use `data.shoot_id` (not `data.id`):

```json
{
  "data": {
    "shoot_id": 123,
    "status": "requested",
    "client_id": 456,
    "is_new_client": true,
    "account_created": true,
    "account_setup_required": true,
    "is_guest_booking": false,
    "total_quote": "325.00"
  }
}
```

`external_reference` remains optional for older clients. Requests without it are
not deduplicated. The new migration must be deployed before enabling reference
based retries. A successful booking means the request was saved for review,
not that an appointment has been approved or payment collected.

## Pricing and property access

Send the property's actual `sqft` as a nonnegative integer. For a variable-price
service with configured ranges, the server selects the one inclusive range that
contains it. Missing or overlapping matches return HTTP 422; the entire request
rolls back. Fixed-price services use their catalog price. Older callers omitting
`sqft`, and services without ranges, retain base catalog pricing. Never send or
trust a browser price: the quote and persisted service lines use the same resolved
server prices, followed by the client's configured discount and applicable tax.

Optional `lockbox_code` (max 100) and `lockbox_location` (max 255) are appended to
private shoot notes alongside `notes` (max 5000). The raw payload is retained for
operational review. Do not log booking bodies or these access details in the proxy.

## Notifications

`create_account` defaults to true. A newly created dashboard account triggers the
existing account setup email, verification email and configured SMS automation.
`create_account: false` creates a locked guest record and skips those setup
messages. Both modes queue the existing shoot-request notification workflow;
provider success is separate from persistence. Replays do not repeat these effects.
