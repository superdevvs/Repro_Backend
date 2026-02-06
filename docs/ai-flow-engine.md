# AI Flow Engine (Strict Rule-Based Scope)

## Overview
This doc describes the strict-scope rule-based flow engine, intent policy, and handoff to OpenAI.
Rule-based flows are limited to:
- `book_shoot`
- `manage_booking`
- `availability`

All other intents should be handled by OpenAI.

## Intent Policy
Rule-based eligibility is enforced in `IntentPolicy` with a minimum confidence of `1.0`.
The `IntentRegistry` provides keyword scoring and negative signals.

## Flow Engine Contract
Rule-based flows implement a small state-machine contract:

- `FlowHandlerInterface::defaultStep()`
- `FlowHandlerInterface::handleStep(step, state)`

The `FlowEngine` persists `step` and `state_data` and returns the response payload.

### Data Model
State is stored on `ai_chat_sessions`:
- `step`: current flow step
- `state_data`: serialized flow data

## Handoff to OpenAI
If a rule-based flow falls back to generic responses or errors, the orchestrator can return:

```json
{
  "handoff": true,
  "handoff_context": {
    "reason": "fallback_smalltalk",
    "intent": "book_shoot",
    "step": "ask_date",
    "state_data": {"property_label": "..."}
  }
}
```

`AiChatController` will forward that context to OpenAI (if available) and continue the conversation.

## Suggestions Behavior
Fallback suggestion pills are shown only when **no assistant step metadata** exists. During active flows,
only flow-provided suggestions are shown.

## Logging
Flow transitions are logged in `FlowEngine`:
- `flow` (handler class)
- `current_step`
- `next_step`
- `clear_step`
- `session_id`

## Regression Checklist
Manual checks for strict-scope flows:

1. **Book shoot**
   - Start: "Book a new shoot"
   - Provide property address
   - Provide date + time (or date with time in same message)
   - Select services
   - Confirm booking and verify success response

2. **Manage booking**
   - Start: "Manage a booking"
   - Select booking by ID/address
   - Reschedule with date + time
   - Cancel flow confirmation
   - Change services flow

3. **Availability**
   - Start: "Check availability"
   - Choose photographer or "All photographers"
   - Provide date (today/tomorrow/next week)
   - Confirm slots display

4. **Fallback/Handoff**
   - Send out-of-scope query (pricing/support)
   - Verify rule-based returns handoff and OpenAI continues
