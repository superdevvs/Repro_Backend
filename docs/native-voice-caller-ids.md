# Native outbound caller IDs

After mobile voice bootstrap, fetch `GET /api/voice/mobile/sessions/{session}/caller-ids` using the same authenticated login that owns that session. The response is private and must not be cached:

```json
{
  "caller_ids": [{"number": "+12025550100", "label": "Office"}],
  "default_caller_id": "+12025550100",
  "selection_required": false
}
```

Only configured Telnyx company sources with an existing non-SMS voice policy and current caller permission appear. The endpoint performs no provider calls. A configured global default is retained only when it passes those checks. Otherwise a sole available line is selected automatically. Multiple alternatives have a null default and `selection_required: true`; display a caller-ID picker. An empty list means outgoing calling is unavailable for this session's user. The SMS default and incoming routing are never changed.

Send the selected E.164 `from` with `to` and UUID `idempotency_key` to the existing `POST /api/voice/mobile/sessions/{session}/outbound`. `from` is optional only when the server can resolve a valid default or sole choice. Keep the selected caller ID fixed for the lifetime of that request key. The existing ticket response, SDK destination and proof fields remain unchanged.

Missing/invalid selection returns 422 with `message: "Choose an available voice business line."`; refresh options when a previously selected line becomes unavailable. Changed destination or explicit caller ID for an existing key returns 409. Retrying without `from` preserves the original ticket's source even if defaults changed. Session ownership/token failures remain 401/403/404 as before.

The server rechecks the exact ticket caller ID, policy, assignment, company-number source, session, permission and outbound-mode restrictions when the signed SDK call event arrives. Removing a policy or caller permission cannot turn a pending ticket into an unscoped call. Tests fake the provider; release acceptance still requires a separately authorized handset call.
