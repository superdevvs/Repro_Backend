<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Jobs\ScheduledVoiceCallJob;
use App\Models\ScheduledVoiceCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledVoiceCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledVoiceCall::query()
            ->with(['callerUser:id,name,email', 'callerContact:id,name,email,phone', 'relatedShoot:id,address,property_address,status', 'originalVoiceCall:id,summary,from_phone,to_phone']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($automation = $request->query('automation_type')) {
            $query->where('automation_type', $automation);
        }
        if ($request->boolean('due')) {
            $query->whereIn('status', [ScheduledVoiceCall::STATUS_SCHEDULED, ScheduledVoiceCall::STATUS_DEFERRED, ScheduledVoiceCall::STATUS_FAILED])
                ->where('next_attempt_at', '<=', now());
        }

        return response()->json($query->latest('next_attempt_at')->paginate((int) $request->query('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_phone' => ['required', 'string', 'max:32'],
            'from_phone' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:255'],
            'automation_type' => ['nullable', 'string', 'max:255'],
            'caller_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'caller_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'related_shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'related_invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'original_voice_call_id' => ['nullable', 'integer', 'exists:voice_calls,id'],
            'scheduled_at' => ['nullable', 'date'],
            'next_attempt_at' => ['nullable', 'date'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'summary' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $scheduledAt = $data['scheduled_at'] ?? now();
        $scheduled = ScheduledVoiceCall::query()->create(array_merge($data, [
            'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $data['next_attempt_at'] ?? $scheduledAt,
            'created_by_user_id' => $request->user()?->id,
        ]));

        return response()->json($scheduled->fresh(), 201);
    }

    public function update(Request $request, ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,deferred,dialing,completed,failed,exhausted,cancelled'],
            'target_phone' => ['sometimes', 'string', 'max:32'],
            'from_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'automation_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'next_attempt_at' => ['sometimes', 'nullable', 'date'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $scheduledCall->fill($data)->save();

        return response()->json($scheduledCall->fresh());
    }

    public function cancel(ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        $scheduledCall->forceFill(['status' => ScheduledVoiceCall::STATUS_CANCELLED])->save();

        return response()->json($scheduledCall->fresh());
    }

    public function retry(ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        $scheduledCall->forceFill([
            'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
            'next_attempt_at' => now(),
            'last_error' => null,
        ])->save();

        ScheduledVoiceCallJob::dispatch($scheduledCall->id);

        return response()->json($scheduledCall->fresh());
    }
}
