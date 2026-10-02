# Hybrid travel scheduling

`HYBRID_TRAVEL_ENABLED=false` is the rollout default. Disabled mode retains the
existing fixed 15-minute policy. Enabling this flag never rewrites a booking,
duration snapshot, price, invoice, or calendar event.

## Rules

The shared evaluator resolves onsite visits from service/unit duration snapshots,
checks working hours and actual overlaps, then checks each affected predecessor
and successor transition for every assigned photographer. Independent visits keep
their original gaps. Only booked visits participate; home commutes are excluded.

Verified identical building addresses require no travel gap. Recognized unit,
apartment and suite labels are excluded from building identity; tower/building
identifiers remain. Coordinates and caller-provided place IDs never prove a match.
Exact server geocoding or authorized staff confirmation establishes identity.
Stored verification is signed and bound to the complete base address.

Different buildings use Google driving duration plus five minutes, rounded up to
five minutes with a 15-minute minimum. This replaces the fixed gap. An unavailable
provider uses exact property coordinates: straight-line miles × 1.3, then 15/30/45
minutes for distances up to 5/15/30 miles. Longer or unverified routes require
staff review. A definitive `ROUTE_NOT_FOUND` always requires review. Historical
departures use labelled mileage fallback rather than a historical traffic call.

## API and saves

Authenticated `POST /api/photographer/availability/feasibility` accepts the normal
booking or edited-shoot fields plus `shoot_id`, `action_mode`, and optional
`include_alternatives`. It returns `{data: {enabled, status, visits, transitions,
alternatives, can_override, can_confirm_location, policy_version,
schedule_version}}`. Status is `available`, `conflict`, or `review_required`.
At most three alternatives are returned after bounded on-demand checks.

Only the authorized edited shoot is excluded from occupancy. Neighbor identities
and addresses never appear in this response. Calendar-wide availability lists
provide onsite slots with `travel_check_required`; the selected time must pass the
shared evaluator before confirmation. Request intake remains available.
The existing authenticated `/check` and optionally authenticated `/for-booking`
endpoints also accept one `selected_visit` containing the same payload, returning
`travel_feasibility` from that evaluator without querying every displayed slot.

Explicit travel exceptions require `travel_override=true` and a reason of at
least five characters. Existing administrator or sales management access is
required. Exceptions never permit overlapping capture work or unavailable hours,
and existing blanket availability bypasses do not grant a travel exception.
Each save is freshly evaluated. Changing address, time, assignment or duration
invalidates the frontend approval. Unchanged itinerary edits retain their old
schedule without revalidating historical gaps.

Routes/geocoding resolve before a booking transaction. Confirmation acquires
per-photographer shared cache locks in ID order, rereads schedule and target
fingerprints, and commits in a short SQLite transaction. A changed neighboring
schedule is recomputed once; a changed edited booking or a second race returns
409. Normal notifications and calendar synchronization follow successful commit.

## Provider and allowance

Set the backend-only `GOOGLE_ROUTES_API_KEY` to a Routes-only key restricted to
the server's outbound IP address. Enable Routes API and billing, then verify a
real route before activation. Existing Maps credentials are not used implicitly.

The provider uses bounded 1×1 `computeRouteMatrix` requests with `DRIVE`,
`TRAFFIC_AWARE`, and the actual departure instant. Field masks contain only
indices, status, condition, duration, distance and fallback information. Each
element is validated independently. The deadline is three seconds with no
automatic retry. Request-scoped memoization deduplicates identical directed legs;
Google responses are not stored in the application's ETA cache or database.
Displayed Google estimates carry Google Maps attribution.

The `scheduling_route_usage` table atomically reserves every attempted element
before outbound access. Ambiguous attempts are never refunded. The rolling
31-day ceiling is 10,000 elements, conservatively US$100 at $0.01 each without
free credits, taxes or unrelated Maps APIs. At the cap, mileage/review takes over.
Admin feasibility responses include usage and 75/90/100 percent alert levels.
The dedicated `scheduling` log records latency, fallback reason, conflicts and
usage without credentials or precise addresses. Travel exception audit records
contain the actor, reason, affected booking IDs and transition identifiers.

## Rollout and rollback

1. Run targeted scheduling tests, full repository quality checks and frontend
   type/lint/build checks. Deploy with the feature disabled through the guarded
   prepared-release workflow.
2. Verify exact release markers, location verification and provider readiness.
   Run staff previews against real schedules without sending notifications or
   changing appointments. Verify Michael's short exterior timing, Jaz's building
   match, insufficient onward travel and unknown-route review.
3. Enable after acceptance; rebuild config and restart long-lived workers. Check
   desktop/mobile explanations, custom durations, stale requests and exceptions.
4. To roll back behavior, set `HYBRID_TRAVEL_ENABLED=false`, rebuild config and
   restart workers. No booking-data migration or duration rewrite is needed.

Google Calendar remains outbound synchronization. Personal-calendar busy import,
traffic-driven rescheduling and home travel are separate work.
