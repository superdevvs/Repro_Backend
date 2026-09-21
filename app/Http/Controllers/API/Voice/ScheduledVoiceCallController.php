<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Jobs\ScheduledVoiceCallJob;
use App\Models\ScheduledVoiceCall;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduledVoiceCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledVoiceCall::query()
            ->with(['callerUser:id,name,email', 'callerContact:id,name,email,phone', 'relatedShoot:id,address,status', 'originalVoiceCall:id,summary,from_phone,to_phone']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($automation = $request->query('automation_type')) {
            $query->where('automation_type', $automation);
        }
        if ($request->boolean('due')) {
            $query->whereIn('status', [ScheduledVoiceCall::STATUS_SCHEDULED, ScheduledVoiceCall::STATUS_DEFERRED, ScheduledVoiceCall::STATUS_FAILED])
                ->where('next_attempt_at', '<=', now())
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
        }

        return response()->json($query->latest('next_attempt_at')->paginate(max(1, min(100, (int) $request->query('per_page', 25)))));
    }

    public function store(Request $request, VoiceSettingsService $settings): JsonResponse
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
        $defaults = $settings->all();
        $scheduled = LockedWrite::run(fn () => ScheduledVoiceCall::query()->create(array_merge($data, [
            'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $data['next_attempt_at'] ?? $scheduledAt,
            'created_by_user_id' => $request->user()?->id,
            'quiet_hours' => $defaults['quiet_hours'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? max(1, min(10, (int) ($defaults['callback_max_attempts'] ?? 3))),
        ])), 'voice-callback-manual-create');

        return response()->json($scheduled->fresh(), 201);
    }

    public function update(Request $request, ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,deferred,cancelled'],
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

        LockedWrite::run(fn () => DB::transaction(function () use ($scheduledCall, $data): void {
            $current = ScheduledVoiceCall::query()->lockForUpdate()->findOrFail($scheduledCall->id);
            $this->assertEditable($current);
            $updates = $data;
            if (array_key_exists('scheduled_at', $updates) && ! array_key_exists('next_attempt_at', $updates)) {
                $updates['next_attempt_at'] = $updates['scheduled_at'] ?: now();
            }
            if (($updates['status'] ?? null) === ScheduledVoiceCall::STATUS_CANCELLED) {
                $updates['next_attempt_at'] = null;
            }
            $current->fill($updates)->save();
        }), 'voice-callback-update');

        return response()->json($scheduledCall->fresh());
    }

    public function cancel(ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($scheduledCall): void {
            $current = ScheduledVoiceCall::query()->lockForUpdate()->findOrFail($scheduledCall->id);
            $this->assertEditable($current);
            $current->forceFill(['status' => ScheduledVoiceCall::STATUS_CANCELLED, 'next_attempt_at' => null])->save();
        }), 'voice-callback-cancel');

        return response()->json($scheduledCall->fresh());
    }

    public function retry(ScheduledVoiceCall $scheduledCall): JsonResponse
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($scheduledCall): void {
            $current = ScheduledVoiceCall::query()->lockForUpdate()->findOrFail($scheduledCall->id);
            $this->assertEditable($current);
            abort_if((int) $current->attempts >= (int) $current->max_attempts, 409, 'This callback has reached its attempt limit.');
            $current->forceFill([
                'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
                'scheduled_at' => now(),
                'next_attempt_at' => now(),
                'last_error' => null,
            ])->save();
        }), 'voice-callback-retry');

        ScheduledVoiceCallJob::dispatch($scheduledCall->id);

        return response()->json($scheduledCall->fresh());
    }

    private function assertEditable(ScheduledVoiceCall $scheduled): void
    {
        abort_if($scheduled->status === ScheduledVoiceCall::STATUS_DIALING, 409, 'This callback is in progress. Use the live call controls.');
        abort_if($scheduled->status === ScheduledVoiceCall::STATUS_COMPLETED, 409, 'This callback has already completed.');
    }
}
