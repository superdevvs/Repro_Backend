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
