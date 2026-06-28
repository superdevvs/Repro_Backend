# Requirements Document

## Introduction

This feature upgrades the existing Google Calendar integration so that synced shoot events carry clean, readable, photographer-oriented information. The work is intentionally scoped to event content and sync behavior only: event title, location, description, duration, reminders, color coding, optional per-service timing, cancellation handling, update detection, and duplicate prevention.

All existing OAuth, connection, dispatch, and job plumbing remains unchanged. No database schema changes are introduced; all derived description fields are sourced from existing shoot and client data. The work reuses the existing services (`GoogleCalendarService`, `GoogleCalendarShootSyncService`, `GoogleCalendarEventPayloadBuilder`, `GoogleCalendarSyncDispatcher` and jobs), models (`GoogleCalendarConnection`, `GoogleCalendarEventMapping`), and helpers (`ShootMutationSupportService::formatFullAddress`, `ShootMutationSupportService::calculateShootDurationFromShoot`).

The current per-photographer, per-event model is preserved.

## Glossary

- **Calendar_Sync**: The system behavior that builds and writes Google Calendar event payloads for a shoot, implemented across `GoogleCalendarShootSyncService` and `GoogleCalendarEventPayloadBuilder`.
- **Event_Payload_Builder**: `GoogleCalendarEventPayloadBuilder`, responsible for producing the title, location, description, timing, reminders, and color of a calendar event.
- **Calendar_Event**: A single Google Calendar event corresponding to one shoot on one photographer's calendar.
- **Event_Mapping**: A `GoogleCalendarEventMapping` record (fields include `shoot_id`, `user_id`, `shoot_service_id`, `calendar_id`, `google_event_id`, `sync_fingerprint`, `last_synced_at`) used as the source of truth linking a shoot to its created Google Calendar event.
- **Sync_Fingerprint**: The `sync_fingerprint` value stored on an `Event_Mapping`, used to detect whether shoot data relevant to the calendar event has changed.
- **Shoot**: A scheduled photography appointment with an associated client, address, services, notes, and status.
- **Client_Name**: The display name of the shoot's client.
- **Photographer_Calendar**: A photographer's Google Calendar to which shoot events are synced under the per-photographer, per-event model.
- **Full_Address**: The formatted property address produced by `ShootMutationSupportService::formatFullAddress`.
- **Estimated_Duration**: The shoot duration in minutes produced by `ShootMutationSupportService::calculateShootDurationFromShoot`, which clamps values to the range 60–240 minutes and defaults to 120 minutes when no value can be derived.
- **Internal_Shoot_Link**: The URL `https://reprodashboard.com/shoots/{shoot_id}` linking back to the shoot record.

## Requirements

### Requirement 1: Event Title

**User Story:** As a photographer, I want the calendar event title to show only the client name, so that my calendar is clean and free of internal labels.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder builds a Calendar_Event for a non-cancelled Shoot, THE Event_Payload_Builder SHALL set the event title to the Client_Name only.
2. THE Event_Payload_Builder SHALL exclude service names, service identifiers, shoot status, photographer names, and internal labels from the event title.
3. WHEN the Event_Payload_Builder builds a Calendar_Event for a cancelled Shoot, THE Event_Payload_Builder SHALL set the event title to "CANCELLED - {Client_Name}".

### Requirement 2: Event Location

**User Story:** As a photographer, I want the event location to be the full property address, so that I can navigate to the shoot.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder builds a Calendar_Event, THE Event_Payload_Builder SHALL set the event location to the Full_Address produced by `ShootMutationSupportService::formatFullAddress`.

### Requirement 3: Event Description (Photographer/Public Mode)

**User Story:** As a photographer, I want a clean plain-text description with the details I need on site, so that I can run the shoot without opening the dashboard.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder builds the description, THE Event_Payload_Builder SHALL produce plain text containing the Client_Name.
2. WHERE the client phone number is present, THE Event_Payload_Builder SHALL include the client phone number in the description.
3. IF the client phone number is missing, THEN THE Event_Payload_Builder SHALL omit the client phone number line from the description.
4. WHERE the client email is present, THE Event_Payload_Builder SHALL include the client email in the description.
5. IF the client email is missing, THEN THE Event_Payload_Builder SHALL omit the client email line from the description.
6. THE Event_Payload_Builder SHALL include a blank line followed by a "Shoot Services:" section listing the shoot's services.
7. THE Event_Payload_Builder SHALL include a "Shoot Notes:" section containing the shoot notes, and SHALL render "Not provided" when shoot notes are absent.
8. THE Event_Payload_Builder SHALL include a "Property Access:" section derived from existing shoot notes and client data, and SHALL render "Not provided" when no value can be derived.
9. THE Event_Payload_Builder SHALL include an "Arrival Instructions:" section derived from existing shoot notes and client data, and SHALL render "Not provided" when no value can be derived.
10. THE Event_Payload_Builder SHALL include an "On-Site Contact:" section, and WHERE no on-site contact can be derived, THE Event_Payload_Builder SHALL fall back to the Client_Name and client contact details.
11. THE Event_Payload_Builder SHALL include the Internal_Shoot_Link as the last line of the description.
12. THE Event_Payload_Builder SHALL exclude pricing, payment status, and admin or internal notes from the photographer description.
13. THE Event_Payload_Builder SHALL derive the Property Access, Arrival Instructions, and On-Site Contact values from existing shoot and client fields without introducing database schema changes.

### Requirement 4: Event Duration

**User Story:** As a photographer, I want the event end time to reflect the estimated shoot duration, so that my calendar blocks the correct time.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder sets the event end time, THE Event_Payload_Builder SHALL set the end time to the start time plus the Estimated_Duration from `ShootMutationSupportService::calculateShootDurationFromShoot`.
2. IF no duration can be derived from the Shoot, THEN THE Event_Payload_Builder SHALL use the Estimated_Duration fallback of 120 minutes clamped to the 60–240 minute range.

### Requirement 5: Reminders

**User Story:** As a photographer, I want reminders before each shoot, so that I do not miss appointments.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder builds a Calendar_Event for a Photographer_Calendar, THE Event_Payload_Builder SHALL set event reminders with `useDefault` set to false and explicit popup overrides at 24 hours and 30 minutes before the start time.

### Requirement 6: Color Coding

**User Story:** As a photographer, I want events color-coded by shoot status, so that I can scan my calendar at a glance.

#### Acceptance Criteria

1. WHEN the Event_Payload_Builder builds a Calendar_Event, THE Event_Payload_Builder SHALL set the event `colorId` based on the shoot status using a Google `colorId` value supported by the integration.

### Requirement 7: Per-Service Timing Block

**User Story:** As a photographer, I want to see per-service timing only when services are scheduled separately, so that the description stays concise for standard shoots.

#### Acceptance Criteria

1. WHILE a Shoot has services with differing per-service `scheduled_at` values, THE Event_Payload_Builder SHALL include a service-level timing block listing each service and its scheduled time.
2. IF a Shoot's services do not have differing per-service schedules, THEN THE Event_Payload_Builder SHALL omit the service-level timing block.

### Requirement 8: Cancelled Shoot Handling

**User Story:** As a photographer, I want cancelled shoots to remain visible but clearly marked, so that I retain the record without confusion.

#### Acceptance Criteria

1. WHEN a Shoot is cancelled, THE Calendar_Sync SHALL retain the existing Calendar_Event rather than deleting it.
2. WHEN a Shoot is cancelled, THE Event_Payload_Builder SHALL set the event title to "CANCELLED - {Client_Name}" and SHALL include a "Shoot Status: Cancelled" line in the description.

### Requirement 9: Sync Update Detection

**User Story:** As a photographer, I want shoot changes to update the existing event, so that my calendar stays accurate without duplicates.

#### Acceptance Criteria

1. WHEN the Client_Name, client phone, client email, Full_Address, shoot date or time, photographer, services, notes, status, or cancellation state of a Shoot changes, THE Calendar_Sync SHALL update the existing Calendar_Event rather than creating a new one.
2. THE Calendar_Sync SHALL detect relevant changes by comparing a recomputed Sync_Fingerprint against the stored Sync_Fingerprint on the Event_Mapping.
3. THE Calendar_Sync SHALL include the Client_Name, client phone, client email, Full_Address, shoot date and time, photographer, services, notes, status, and cancellation state in the Sync_Fingerprint computation.

### Requirement 10: Duplicate Prevention

**User Story:** As a photographer, I want each shoot to map to a single event, so that I never see duplicate calendar entries.

#### Acceptance Criteria

1. WHEN the Calendar_Sync processes a Shoot, THE Calendar_Sync SHALL check for an existing Event_Mapping before creating a Calendar_Event.
2. IF an Event_Mapping exists for the Shoot and Photographer_Calendar, THEN THE Calendar_Sync SHALL update the mapped Calendar_Event.
3. IF no Event_Mapping exists for the Shoot and Photographer_Calendar, THEN THE Calendar_Sync SHALL create a Calendar_Event and store a new Event_Mapping.
4. WHERE summary sync status visibility is enabled, THE Calendar_Sync SHALL mirror a summary sync status onto the Shoot record.

## Non-Goals

The following are explicitly deferred and out of scope for this feature:

- A dedicated admin/internal calendar mode with a full-detail description (including pricing, payment status, and internal notes) and 1 day / 2 hour / 30 minute reminders.
- Multi-photographer single-shared-event-with-attendees behavior (Option A) where it conflicts with the current per-photographer, per-event model. The current per-photographer, per-event model is retained.
