# Direct Telnyx voice rollout

Robbie voice calls use direct Telnyx Call Control when `VOICE_PROVIDER=telnyx`.
Vapi remains available only as an explicitly selected compatibility provider.

## Required configuration

```dotenv
VOICE_PROVIDER=telnyx
VOICE_CANARY_MODE=true
VOICE_CANARY_NUMBERS=+12025550123,+12025550124

TELNYX_API_KEY=
TELNYX_PUBLIC_KEY=
TELNYX_FROM_NUMBER=
TELNYX_VOICE_ENABLED=true
TELNYX_VOICE_CONNECTION_ID=
TELNYX_VOICE_ASSISTANT_ID=
TELNYX_VOICE_WEBHOOK_URL=https://api.reprodashboard.com/api/webhooks/telnyx/voice
TELNYX_VOICE_RECORDING_ENABLED=true
TELNYX_VOICE_SUPPORT_HANDOFF_NUMBER=
TELNYX_VOICE_DISCLOSURE_TEXT=
TELNYX_TOOL_BRIDGE_BASE_URL=https://api.reprodashboard.com/api/telnyx-ai/tools
TELNYX_TOOL_BRIDGE_SECRET=
```

Keep canary mode enabled until the controlled inbound and outbound checks pass.
Outbound calls fail closed when the destination is not in
`VOICE_CANARY_NUMBERS`.

## Assistant synchronization

Inspect the current assistant without modifying Telnyx:

```shell
php artisan voice:sync-telnyx-assistant
```

Create a non-main assistant version and route only the configured canary phone
targets to it:

```shell
php artisan voice:sync-telnyx-assistant --apply --route-canary --version-name=repro-voice-canary
```

Remove the configured canary targets from version routing:

```shell
php artisan voice:sync-telnyx-assistant --remove-canary
```

The apply command never promotes the new version to main. Review the Telnyx
assistant version and controlled-call evidence before any deliberate promotion.

## Canary checklist

- Confirm AI disclosure and both recording-consent outcomes.
- Verify OTP success and failure, authorized reads, and all confirmation-gated
  mutations against isolated QA records.
- Confirm transfer and callback fallback behavior.
- Inspect voice call, transcript, session, tool-audit, and recording fields.
- Create but do not pay a controlled Stripe payment link.
- Confirm duplicate provider events and tool retries do not duplicate side
  effects.

Provider contracts:

- [Dial](https://developers.telnyx.com/api-reference/call-commands/dial)
- [Start AI assistant](https://developers.telnyx.com/api-reference/call-commands/start-ai-assistant)
- [Webhook tools](https://developers.telnyx.com/docs/inference/ai-assistants/async-tools)
- [Assistant version routing](https://developers.telnyx.com/docs/inference/ai-assistants/version-testing-traffic-distribution)

## Dashboard staff voicemail lines

Physical-number policies live in `voice_line_policies`; duplicate CRM number rows
share the same policy. `staff_voicemail` checks the line's IANA timezone and weekly
windows before creating the existing 45-second atomic incoming offer. Only
assigned active staff with calling permission and a fresh registered Dashboard
session are eligible. Personal phones, global forwarding and Robbie are excluded.
`SMS-only` lines have no voice group or assistant route.

Voicemail answers the customer leg, waits for answer confirmation, plays the
selected private MP3, then starts inbound-only recording after playback completion
with a beep, a 180-second limit and a five-second silence timeout. Command IDs and
stage-specific client state prevent duplicate and delayed events from restarting
capture. Recording errors retain a missed item and a manual follow-up task.
Conversation recording consent is unchanged. No callback is scheduled automatically.

`StoreVoiceVoicemail` retrieves audio by provider recording ID using a bounded,
nonredirecting download from the approved Telnyx recording origin. Audio is stored
on the private local disk and streamed only through an authenticated, line-scoped
endpoint with private/no-store caching. `voice:maintain-voicemail` recovers uncertain
commands, flags incomplete capture, and deletes expired local/provider recordings
after 90 days while preserving the provider ID and call history.

Prepare the approved business groups explicitly after release:

```sh
php artisan voice:configure-business-lines /path/to/office.mp3 /path/to/closed.mp3 \
  --support-email=VERIFIED_ACTIVE_SUPPORT_EMAIL --canary=SEPARATE_OWNED_TEST_NUMBER
```

This command imports validated greetings, resolves the active support identity,
grants editing managers only the needed view/operate calling permissions, and
preserves existing SMS settings. It does not attach provider numbers, submit ports,
activate ports, or cancel the losing carrier. Readiness distinguishes local
configuration/device registration from provider assignment and live audio proof.

Before assigning the ported DIDs, use the separate canary to verify open/closed
hours, staff availability, atomic answering, greeting completion and failed
playback fallback, silent/max-length capture, caller hangup, one saved notification,
authenticated playback, and correct outbound caller ID. Impersonated sessions are
not eligible to connect a browser phone; use genuine staff sessions. A mobile-sized
viewport is not evidence of audio on a real mobile device. Obtain a confirmed
reschedule if live readiness cannot meet the port deadline; an on-demand port can
still activate automatically at the end of its carrier window.
