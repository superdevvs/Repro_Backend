# Photographer picker map — FE contract (v6)

**Consumers:** `SchedulingPhotographerSection` + overview twin (book/edit photographer picker).  
**Backend ship:** enrich existing booking picker — **do not invent a parallel list endpoint**.

## Endpoint

| | |
|--|--|
| **Method** | `POST` |
| **Path** | `/api/photographer/availability/for-booking` |
| **Auth** | Optional Sanctum. **Map / neighbor pins require a privileged staff token** (`admin`, `superadmin`, `editing_manager`, `salesRep` / `rep` / `representative`, `photographer`, `editor`). Anonymous + `client` get `map: null` (fail-open; distance fields unchanged). |
| **BC** | Additive only. Existing fields (`distance`, `distance_from`, slots, etc.) unchanged. |

Availability **booked blocks stay 120** minutes; shoot **duration default stays 60**. Job window for last/next = job start → job start + `duration_minutes` (request or default 60).

## Request (unchanged + coords)

Existing body. For map pins FE should send:

| Field | Notes |
|-------|--------|
| `date`, `time`, `duration_minutes` | Job window |
| `shoot_address`, `shoot_city`, `shoot_state`, `shoot_zip` | Required address parts as today |
| `shoot_latitude`, `shoot_longitude` | Strongly recommended so FE/BE share the same job pin |
| `photographer_ids` | Optional filter |

## Response additions

### Top-level `job` (always)

Echo of the selected property pin (fail-open nulls):

```json
"job": {
  "lat": 38.8462,
  "lng": -77.3064,
  "address": "500 Booking Avenue",
  "city": "Fairfax",
  "state": "VA",
  "zip": "22030"
}
```

### Per photographer

| Field | Type | Notes |
|-------|------|--------|
| `miles_to_job` | `number \| null` | Same value as existing `distance` (miles). Prefer this for the strip. |
| `map` | `object \| null` | Full map payload for privileged callers; `null` otherwise. FE feature-detects. |

#### `map` object (snake_case, matches this endpoint)

```json
"map": {
  "home": { "lat": 38.89, "lng": -77.08 },
  "job": { "lat": 38.8462, "lng": -77.3064 },
  "last_shoot": {
    "shoot_id": 1001,
    "address": "12 Previous Client Street, Alexandria, VA 22314",
    "lat": 38.81,
    "lng": -77.06,
    "ends_at": "2026-09-15T10:00:00-04:00"
  },
  "next_shoot": {
    "shoot_id": 1002,
    "address": "88 Later Lane, Arlington, VA 22201",
    "lat": 38.88,
    "lng": -77.10,
    "starts_at": "2026-09-15T15:00:00-04:00"
  },
  "drive_minutes": {
    "last_to_job": 18,
    "job_to_next": 22,
    "source": "google_distance_matrix",
    "is_estimate": false
  },
  "miles_to_job": 12.4,
  "travel_risk": {
    "last_to_job": "tight",
    "job_to_next": "early",
    "buffer_minutes": 15,
    "slack_minutes": { "last_to_job": 8, "job_to_next": 40 }
  }
}
```

Any nested object may be `null` when data is missing — **fail open**; still list the photographer; FE hides route / strip bits.

### Field meanings for UI

| UI need | Field |
|---------|--------|
| Photographer pin | `map.home` |
| Current job pin | `map.job` **or** top-level `job` |
| Last job pin | `map.last_shoot` (`lat`/`lng`/`address`/`ends_at`) |
| Next job pin | `map.next_shoot` (`lat`/`lng`/`address`/`starts_at`) |
| Miles to job | `miles_to_job` or `map.miles_to_job` |
| last→job / job→next travel minutes | `map.drive_minutes.last_to_job` / `.job_to_next` |
| Strip pill early \| tight \| late | `map.travel_risk.last_to_job` / `.job_to_next` |
| Buffer used for pill | `map.travel_risk.buffer_minutes` |
| Slack (gap − drive) | `map.travel_risk.slack_minutes.*` |

**Risk labels**

- `early` — slack > buffer  
- `tight` — `0 ≤ slack ≤ buffer`  
- `late` — slack < 0  
- `null` — drive or neighbor time missing  

**Drive source**

- `google_distance_matrix` + `is_estimate: false` when Google Distance Matrix succeeded (existing Maps stack / `GOOGLE_MAPS_API_KEY`).  
- `estimate` + `is_estimate: true` for haversine / approx fallback. No second Maps vendor.

### CamelCase aliases (FE-local)

If the Dashboard prefers the v6 design names, map locally:

| Contract (API) | v6 design |
|----------------|-----------|
| `map.home` | `home` |
| `map.last_shoot` | `lastShoot` |
| `map.next_shoot` | `nextShoot` |
| `map.drive_minutes` | `driveMinutes` |
| `map.miles_to_job` | `milesToJob` |
| `map.travel_risk` | `travelRisk` / buffer pill |
| `last_shoot.shoot_id` | `shootId` |
| `last_shoot.ends_at` | `endsAt` |
| `next_shoot.starts_at` | `startsAt` |
| `drive_minutes.last_to_job` | `lastToJob` |
| `drive_minutes.job_to_next` | `jobToNext` |

## Sample privileged snippet (shape only)

```json
{
  "data": [
    {
      "id": 42,
      "name": "Alex Photographer",
      "distance": 12.4,
      "miles_to_job": 12.4,
      "distance_from": "previous_shoot",
      "previous_shoot_id": 1001,
      "is_available_at_time": true,
      "map": {
        "home": { "lat": 38.89, "lng": -77.08 },
        "job": { "lat": 38.8462, "lng": -77.3064 },
        "last_shoot": {
          "shoot_id": 1001,
          "address": "12 Previous Client Street, Alexandria, VA 22314",
          "lat": 38.81,
          "lng": -77.06,
          "ends_at": "2026-09-15T10:00:00-04:00"
        },
        "next_shoot": {
          "shoot_id": 1002,
          "address": "88 Later Lane, Arlington, VA 22201",
          "lat": 38.88,
          "lng": -77.10,
          "starts_at": "2026-09-15T15:00:00-04:00"
        },
        "drive_minutes": {
          "last_to_job": 18,
          "job_to_next": 22,
          "source": "google_distance_matrix",
          "is_estimate": false
        },
        "miles_to_job": 12.4,
        "travel_risk": {
          "last_to_job": "tight",
          "job_to_next": "early",
          "buffer_minutes": 15,
          "slack_minutes": { "last_to_job": 8, "job_to_next": 40 }
        }
      }
    }
  ],
  "job": {
    "lat": 38.8462,
    "lng": -77.3064,
    "address": "500 Booking Avenue",
    "city": "Fairfax",
    "state": "VA",
    "zip": "22030"
  },
  "hybrid_travel_enabled": false,
  "travel_feasibility": null
}
```

## Out of scope / unchanged

- No new Maps vendor.  
- No geocode of photographer home unless coords already on `users.metadata` (`lat`/`latitude`, `lng`/`longitude`).  
- `/available-photographers` not enriched (picker uses `/for-booking`).  
- Hybrid travel `/feasibility` evaluator unchanged.
