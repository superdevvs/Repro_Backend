# Google Calendar disconnect — FE contract (Sync & Settings)

**Provider:** Google Calendar only (no Outlook / Apple / CalDAV provider exists today).  
**Model:** Per-user (`google_calendar_connections.user_id` unique). Not org-wide.  
**Do not invent a parallel API** — extend `DELETE /api/google-calendar/disconnect`.

## How FE knows "connected" today

Call status (preferred for Sync & Settings):

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/api/google-calendar/status` | Sanctum + role `photographer` (self) **or** `admin` / `superadmin` / `editing_manager` with `?user_id=` for a photographer |

Response `data` fields:

| Field | Meaning |
|-------|---------|
| `connected` | `true` iff a `google_calendar_connections` row exists for the target user |
| `sync_enabled` | Connection flag; sync jobs require `true` |
| `provider_email` | Google account email, or `null` when disconnected |
| `calendar_id` | Usually `primary`, or `null` when disconnected |
| `last_synced_at` | ISO-8601 or `null` |
| `last_error` | Sanitized last failure, or `null` |
| `available` | App OAuth client configured |
| `user_id` / `user_name` | Target photographer |

**Not** on `/api/user` / profile payloads — Sync & Settings must use `/google-calendar/status` (or the disconnect response below).

After a successful disconnect, `connected` and `sync_enabled` are both `false`, and connection row/tokens/mappings for that user are gone.

## Disconnect (own connection)

| | |
|--|--|
| **Method** | `DELETE` |
| **Path** | `/api/google-calendar/disconnect` |
| **Auth** | Sanctum bearer + role **`photographer`** only (own connection) |
| **Body** | none |
| **Idempotent** | Yes — already disconnected → **200** with same success shape |

### Success response (200)

```json
{
  "success": true,
  "message": "Google Calendar disconnected.",
  "data": {
    "user_id": 123,
    "user_name": "Jane Photographer",
    "available": true,
    "connected": false,
    "provider_email": null,
    "calendar_id": null,
    "sync_enabled": false,
    "last_synced_at": null,
    "last_error": null
  }
}
```

FE can hide/disable the Disconnect control when `data.connected === false` (from status **or** this response). Show Connect when disconnected.

### Error cases

| Status | When |
|--------|------|
| `401` | Missing/invalid Sanctum token |
| `403` | Authenticated but role is not `photographer` (admins use connect/status with `user_id`; they cannot call this self-disconnect route) |

No `404` for "already disconnected" — that is success.

## Related endpoints (same feature surface)

| Method | Path | Roles | Notes |
|--------|------|-------|-------|
| `POST` | `/api/google-calendar/connect` | photographer, admin, superadmin, editing_manager | Photographer: self. Staff: require `user_id` of a photographer. Returns `{ data.authorization_url }` |
| `GET` | `/api/google-calendar/callback` | public (OAuth redirect) | Exchanges code; sets `connected` |
| `POST` | `/api/google-calendar/resync` | photographer | 404 if not connected |
| `GET` | `/api/admin/google-calendar/overview` | admin, superadmin, editing_manager | Counts only |

## Side effects of disconnect (product-safe cleanup)

For the **authenticated photographer only** (other users' calendars untouched):

1. Sets `sync_enabled = false` immediately (queued sync/resync jobs no-op).
2. Best-effort deletes that user's Google Calendar events for mapped shoots.
3. Deletes that user's `google_calendar_event_mappings` rows (forced, even if a remote delete failed).
4. Best-effort Google OAuth token revoke (`refresh_token` preferred, else `access_token`).
5. Deletes the `google_calendar_connections` row (access/refresh tokens cleared with it).
6. No webhook/watch channels are registered today — nothing to stop there.
7. Queued `SyncShootToGoogleCalendarJob` / `ResyncGoogleCalendarForUserJob` for that user become no-ops (no connection / sync disabled); they do not recreate events.

## FE Sync & Settings wiring (checklist)

1. On load: `GET /api/google-calendar/status` → if `connected`, show provider email + **Disconnect**; else show **Connect**.
2. Connect: `POST /api/google-calendar/connect` → open `authorization_url`.
3. Disconnect: `DELETE /api/google-calendar/disconnect` → treat `success && data.connected === false` as done; refresh UI from response (no required second status call).
4. Disable Disconnect while request in flight; re-enable Connect after success.
