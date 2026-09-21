# Calls automation rules

The Calls → Automations builder stores rules and run history in `voice_automation_rules` and `voice_automation_runs`. Apply `2026_09_21_020000_create_voice_automation_rules_and_runs.php` after the follow-up task migration before serving this release.

Each rule has one trigger, AND conditions, a detection-to-action delay, optional local quiet hours, and either an AI callback or an internal task. Callback rules also specify a maximum of 1–5 attempts and a retry delay. Global quiet hours remain mandatory. Tasks are saved immediately when an event matches, with a due time calculated from the delay and quiet hours; they do not place a call or send a message.

## Trigger delivery

- Missed calls: existing failed/no-answer routing and unanswered inbound terminal hangups. Scheduled callback attempts do not recursively create fresh callback budgets.
- Failed staff transfer: existing failed transfer routing.
- Shoot reminder: the existing scheduler scans upcoming shoots approximately 24 hours ahead or scheduled for tomorrow.
- Delivery follow-up: scans delivered shoots completed between one hour and two days earlier.
- Unpaid invoice: scans sent unpaid client invoices due tomorrow or earlier, including invoices without a due date.

`voice:dispatch-scheduled-calls` remains the scheduled entry point. Source scans stream in batches of 100, avoiding starvation behind the first page. Saving a rule does not immediately scan or dial. A newly enabled rule can match an existing entity in the next scan's eligibility window. Historical run outcomes are immutable; rule edits affect new source events.

Every rule/source-event key is unique. A short database transaction creates the run and exactly one task or scheduled callback. No carrier request happens in that transaction. Calls remain scheduled/dialing until actual call result evidence arrives. Run history derives its current status from the associated callback or task.

Before a delayed or retried call claims an attempt, the worker rechecks live invoice, shoot or original call eligibility and the saved rule conditions. A paid/deleted invoice, cancelled/deleted/past/rescheduled shoot or invalidated condition cancels the callback without an attempt. Carrier policy and per-line availability are also checked by the outbound call service.

## Disable, archive and task ownership

Any custom rule, including a disabled or archived rule, suppresses the standard toggle for its trigger. This prevents unintentional fallback calls. A new custom rule can resume that trigger. Multiple enabled rules may create separate actions from one event.

Disabling pauses pending callbacks; enabling allows them to become due again. Archiving cancels pending callbacks and retains run history, existing tasks and the trigger's custom ownership. Calls already in flight are not redialed or cancelled by archive.

Automation tasks have a unique `automation_run_id`; their call reference is nullable for shoot/invoice tasks. Manual wrap-up tasks remain unique per call and are queried separately. Both task workflows keep the call's `needs_follow_up` column and metadata in sync with all open tasks. Task completion/reopening is available in run history.

## API and permissions

All endpoints are under `/api/voice/automation-rules` and require Calls view permission. Rule creation, update, archive and preview additionally require `manage`; task completion requires `operate`.

- `GET /`: active rule list, managed trigger keys (including archived owners), and staff assignees.
- `POST /`: validated rule definition plus a required UUID `idempotency_key`, stable for the editor's creation attempt. The unique actor/key returns the existing rule on an identical retry; different details or an archived result return 409 without creating another rule. The creation payload hash survives later edits and archiving.
- `PATCH /{rule}`: validated rule definition; trigger type cannot change after creation.
- `DELETE /{rule}`: archive.
- `POST /preview`: `{rule, sample}`. The sample is explicitly fictional. No writes, queue dispatch or provider calls occur. Preview does not prove current outbound provider availability.
- `GET /runs?page=1&rule_id=...`: paginated history with rule snapshot, source, effective callback/task status.
- `PATCH /tasks/{task}`: `{status: "open" | "completed"}` for automation-owned tasks only.

Supported conditions are customer matched, call direction/intent, shoot status, invoice balance, and days overdue, restricted to compatible triggers and types. The system accepts no arbitrary code, URL or message action.
