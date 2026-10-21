# Client notification timing

Client receipts, confirmations, and responses to an explicit request send immediately.
The fact that an automation generates a message does not make it a reminder.
Routine notifications due before 09:00 wait until 09:00 in the recipient's local
timezone. Later explicitly scheduled delivery times remain unchanged.

| Purpose | Timing |
| --- | --- |
| Payment receipt, acknowledgment, invoice paid, refund confirmation | Immediate |
| Booking request receipt, booking confirmation, approval or decline | Immediate |
| Cancellation, removal, hold confirmation, and request decisions | Immediate |
| Accepted client-requested reschedule | Immediate |
| Password reset, account access/verification, phone security notice | Immediate |
| Direct conversation reply; client-requested invoice/link fulfillment | Immediate |
| Active checkout failure response | Immediate; background payment follow-ups wait |
| Routine price/note/status updates | 09:00 or later |
| Schedule, location, access, services, or photographer change | Immediate only when it affects an active upcoming appointment today in the recipient's timezone |
| Payment/access/preparation reminders | 09:00 or later, including manually sent templates |
| Automatic media-ready/delivery notification, including manual delivery templates | 09:00 or later |
| Weekly invoices, summaries, reports, surveys, marketing | 09:00 or later |
| Shoot appointment reminder | 09:00 or later, with the morning exception below |

The same-day morning reminder exception is unchanged: 09:00 appointment -> 07:00
reminder, 10:00 -> 08:00, 11:00 -> 09:00. The two-hour window applies only to
appointments from 09:00 through 11:00. Expired or rescheduled appointment reminders
are cancelled instead of delivering outdated details.

`ClientNotificationTimingPolicy` classifies durable event, canonical-email, and
template identities. Server-owned `notification.origin=client_action` distinguishes
a response to a client request from a routine update. `notification.urgent` is
derived from operational change fields; a price or notes change does not qualify.
Ordinary compose endpoints do not accept these timing overrides.

The policy applies to existing email and SMS routes. It does not create new
notifications or enable disabled rules/channels. Recipient preferences, opt-outs,
stale-message checks, and idempotency still run before delivery. Active checkout
already returns its result directly; a background failed-payment webhook is not
classified as an active checkout response.

The scheduled dispatcher also rechecks quiet-held messages after policy changes.
It may release a future message only when its saved `scheduled_at` still equals
the quiet-hours `delivery.deferred_until` and the current policy permits delivery.
It retains the same message and lock, and never advances an explicit user schedule.
