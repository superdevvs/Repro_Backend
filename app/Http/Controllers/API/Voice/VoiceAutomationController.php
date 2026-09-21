<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\ScheduledVoiceCall;
use App\Models\User;
use App\Models\VoiceAutomationRule;
use App\Models\VoiceAutomationRun;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Services\Voice\VoiceAutomationDefinition;
use App\Services\Voice\VoiceAutomationService;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VoiceAutomationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'rules' => VoiceAutomationRule::query()->latest('id')->get(),
            'managed_triggers' => VoiceAutomationRule::withTrashed()->distinct()->pluck('trigger_type'),
            'assignees' => User::query()->whereIn('role', ['admin', 'superadmin', 'editing_manager', 'salesRep'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, VoiceAutomationDefinition $definitions): JsonResponse
    {
        $data = $definitions->validate($request->all());
        $key = $request->validate(['idempotency_key' => ['required', 'uuid']])['idempotency_key'];
        $hash = hash('sha256', json_encode($this->canonicalPayload($data), JSON_THROW_ON_ERROR));
        // The unique key also protects concurrent requests and archived rules.
        // No provider or queue work occurs inside this short write.
        $rule = LockedWrite::run(fn () => VoiceAutomationRule::withTrashed()->firstOrCreate([
            'created_by_user_id' => $request->user()->id, 'creation_key' => $key,
        ], array_merge($data, ['creation_request_hash' => $hash])), 'voice-automation-create');
        if (! hash_equals($rule->creation_request_hash, $hash)) {
            throw new ConflictHttpException('This save was already used for different rule details. Close the editor and review the saved workflows before making changes.');
        }
        if ($rule->trashed()) {
            throw new ConflictHttpException('This rule was already created and archived. Repeating the save will not create or enable another rule.');
        }

        return response()->json($rule, $rule->wasRecentlyCreated ? 201 : 200);
    }

    private function canonicalPayload(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalPayload($value);
            }
        }

        return $payload;
    }

    public function update(Request $request, VoiceAutomationRule $rule, VoiceAutomationDefinition $definitions): JsonResponse
    {
        $data = $definitions->validate(array_merge($rule->toArray(), $request->all()));
        if ($data['trigger_type'] !== $rule->trigger_type) {
            throw ValidationException::withMessages(['trigger_type' => 'Create a new rule to use a different trigger.']);
        }
        LockedWrite::run(fn () => $rule->update($data), 'voice-automation-update');

        return response()->json($rule->fresh());
    }

    public function destroy(VoiceAutomationRule $rule): JsonResponse
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($rule): void {
            $rule->forceFill(['enabled' => false])->save();
            $rule->delete();
            VoiceCall::query()->whereIn('scheduled_voice_call_id', ScheduledVoiceCall::query()
                ->select('id')->where('metadata->automation_rule_id', $rule->id)
                ->whereIn('status', ['scheduled', 'deferred', 'failed']))
                ->update(['callback_status' => 'cancelled']);
            ScheduledVoiceCall::query()->where('metadata->automation_rule_id', $rule->id)
                ->whereIn('status', ['scheduled', 'deferred', 'failed'])
                ->update(['status' => 'cancelled', 'next_attempt_at' => null]);
        }), 'voice-automation-archive');

        return response()->json(['archived' => true]);
    }

    public function preview(Request $request, VoiceAutomationDefinition $definitions, VoiceAutomationService $automations): JsonResponse
    {
        $input = $request->validate([
            'rule' => ['required', 'array'],
            'sample' => ['present', 'array:known_caller,direction,intent,shoot_status,amount_due,days_overdue,target_phone'],
            'sample.known_caller' => ['sometimes', 'boolean'],
            'sample.direction' => ['sometimes', Rule::in(['INBOUND', 'OUTBOUND'])],
            'sample.intent' => ['nullable', 'string', 'max:80'],
            'sample.shoot_status' => ['nullable', 'string', 'max:80'],
            'sample.amount_due' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'sample.days_overdue' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sample.target_phone' => ['nullable', 'string', 'max:30'],
        ]);
        $data = $definitions->validate($input['rule']);
        $sample = $input['sample'];
        if (array_key_exists('known_caller', $sample)) {
            $sample['known_caller'] = (bool) $sample['known_caller'];
        }

        return response()->json(array_merge($automations->plan($data, $sample), [
            'preview_only' => true, 'sample_is_fictional' => true,
            'notice' => 'No rule, task or call was saved, queued or sent. Live outbound availability is checked by the call worker.',
        ]));
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['rule_id' => ['sometimes', 'integer'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $runs = VoiceAutomationRun::query()->with(['task', 'scheduledCall', 'rule'])
            ->when($data['rule_id'] ?? null, fn ($query, $id) => $query->where('voice_automation_rule_id', $id))
            ->latest('id')->paginate(30);
        $runs->getCollection()->transform(function (VoiceAutomationRun $run): array {
            $result = $run->toArray();
            $result['effective_status'] = $run->scheduledCall?->status
                ?? ($run->task ? 'task_'.$run->task->status : $run->status);

            return $result;
        });

        return response()->json($runs);
    }

    public function updateTask(Request $request, VoiceFollowUpTask $task): JsonResponse
    {
        abort_unless($task->automation_run_id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'completed'])]]);
        LockedWrite::run(fn () => DB::transaction(function () use ($task, $data): void {
            $task = VoiceFollowUpTask::query()->lockForUpdate()->findOrFail($task->id);
            $task->forceFill([
                'status' => $data['status'],
                'completed_at' => $data['status'] === 'completed' ? ($task->completed_at ?: now()) : null,
            ])->save();
            if ($task->voice_call_id) {
                $call = VoiceCall::query()->lockForUpdate()->find($task->voice_call_id);
                $followUp = VoiceFollowUpTask::query()->where('voice_call_id', $task->voice_call_id)->where('status', 'open')->exists();
                $call?->forceFill([
                    'needs_follow_up' => $followUp,
                    'metadata' => array_merge($call->metadata ?? [], ['needs_follow_up' => $followUp]),
                ])->save();
            }
        }), 'voice-automation-task-status');

        return response()->json($task->fresh());
    }
}
