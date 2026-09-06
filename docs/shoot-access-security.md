# Shoot access remediation — audit #2

This change enforces actor and assignment checks on authenticated shoot APIs. It is a local implementation; production has not been deployed or verified.

## Access rules

| Actor | Shoot access |
| --- | --- |
| Admin, superadmin, editing manager | Studio-wide operational access, subject to each action's role checks. |
| Sales representative (including supported aliases) | Only shoots whose `rep_id` matches the actor. Client-account metadata or shoot creation does not grant access. |
| Photographer | Top-level or service assignment is required; files assigned to another photographer's service remain restricted. |
| Editor | Top-level or service editing assignment is required; existing editing-lane and raw-download restrictions also apply. |
| Client | Own shoots, explicitly linked accounts that share shoots, or delivered shoots explicitly shared with the client. Shared read access does not grant owner workflow writes. |
| Guest or unknown role | No operational shoot access. Finance/accounting retain their explicitly authorized history access. |

Assignment authorization is centralized in `ShootAuthorizationSupport`. Ordinary shoot lists, photographer lists, details, history, file lists and file actions use the same assignment rules. Restricted actors bypass list caches so assignment and sharing revocation take effect on the next request.

Messages additionally require participation in the conversation, and a new message's recipient must be an eligible shoot participant. Issue media and recipients must belong to the authorized shoot. Workflow, issue, reschedule, reorder, cover and processing mutations check access before changes or job dispatch. Processing batches are authorized before the first job is dispatched. Activity logs, tour analytics, scheduling and approval also require assignment where the actor does not have studio-wide authority.

Client raw files, hidden files and unreleased originals remain restricted. ZIP requests check their members before returning a shared archive. A mixed-service archive can be denied even when the actor may access some files; the actor can request their assigned service or select accessible files. The dedicated iGUIDE viewer remains the access path for restricted actors; source packages are not deliverable media.

## Private-listing discovery

Client discovery is a separate projection, requested with `private_listing=true&listing_scope=all`. It returns visible, delivered property summaries only. It excludes operational relationships, contacts, billing and media URLs, and ignores operational filters that could reveal those details. Direct shoot APIs still require the access rules above.

The current frontend can consume the sparse property summary. Discovery cards continue opening the existing public branded tour. Thumbnail and agent fields use placeholders because this projection does not expose those fields. Public-tour and public-share contracts are separate from this authenticated API remediation.

## Validation and release

See `security-remediation-progress.md` for the final test result. Regression coverage includes allowed and denied roles, assignment revocation, cross-service files, message recipients, issue references, workflow writes, image processing batches, client release restrictions, and existing media and rescheduling behavior. Tests use isolated SQLite and fake storage/queues.

Before release, compare the listed implementation against the real repository base and deploy through the project's normal process. The copied workspace's Git metadata is empty, so it cannot establish a release diff. Do not include the paused, untested Dropbox or provider-webhook drafts in a release for this step. No database migration or credential rotation is required for this shoot-access change.

After deployment, verify with one assigned and one unassigned account that details, lists, messages, workflow status and media behave as expected, then remove an assignment and verify that access is immediately denied. Confirm an assigned user's normal media and rescheduling flows still work. Previously issued public/storage links are outside the scope of assignment checks on API requests.
