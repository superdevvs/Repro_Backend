# Listing Studio introductory photo edits

Clients can request one batch of three photo edits at `/listing-studio/free-edits`.
Existing clients are eligible for their first claim. Each photo can request virtual
staging, digital decluttering, green grass, or digital dusk/twilight. No checkout,
subscription, monthly credit grant, or access to the client media Studio is involved.

The client must sign in to their own active account, verify their current email,
and retain the existing `support-view` permission. Exactly three distinct JPEG,
PNG, or WebP images, each at most 10 MB, are accepted. A claim is permanently unique
per client ID and normalized email hash. Changing the email, closing the request,
or submitting a new request key does not renew eligibility.

## Team fulfillment

Requests appear in the existing **Support requests** queue at
`/messaging/email/inbox?tab=support`, titled **Listing Studio — 3 free photo edits**.
The first message contains the three private originals, the selected edit for each
photo, and the client's notes. Existing support managers can assign and update the
request. Process the photos using the team's normal tools, reply with the three
edited images, then resolve the request. Clients can follow the conversation and
download the private results from the same thread. Existing in-app support
notifications apply; the claim does not send a new external email or SMS.

The introductory-offer screen displays the support request's current status.
Resolution is a team action, not automated verification of output count or quality.
Closing or reopening the support request never grants another free batch.

## API

- `GET /api/listing-studio/free-edits` returns `data.eligible`, a stable `reason`,
  `email_verified`, an `offer` describing the upload/edit options, and an owned
  `claim` when one exists.
- `POST /api/listing-studio/free-edits` accepts multipart `request_key` (UUID),
  `attachments[0..2]`, corresponding `edit_types[0..2]`, and optional `notes`.
  It returns `{data: {id, ticket_id, status, support_url}}`; new claims return 201,
  identical retries 200, and repeat claims with another key/payload 409.
- Reasons are `already_claimed`, `email_not_verified`, and `email_already_claimed`.
  Another account's claim or support link is never returned for an email collision.
- The mutation returns `email_not_verified` (403) or `trial_already_claimed` (409)
  for those business failures. The existing global email-verification policy can
  return `email_verification_required` (403) first. Invalid uploads use standard
  422 field errors. Impersonated or non-client access is forbidden.

Signup and verification resend accept the optional literal
`return_to=/listing-studio/free-edits`. This is saved with the verification token,
and only a successful token verification can use it. URL query redirect parameters
are ignored; other destinations are rejected. Other registration/verification flows
keep their existing destination.

## Validation

Run `ListingStudioTrialTest`, `ListingStudioTrialVerificationReturnTest`,
`SupportTicketTest`, and `Users/AccountCreatedNotificationServiceTest` with PHPUnit.
These checks use local fakes/mocks and make no payments or external sends.
