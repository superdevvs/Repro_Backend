<?php

namespace App\Services\Voice;

use App\Models\Message;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Models\VoiceWrapUpOperation;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Saves operator work separately from AI callback scheduling. */
class VoiceWrapUpService
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function save(VoiceCall $call, User $actor, array $data, ?string $headerKey = null): array
    {
        $sendSms = (bool) ($data['send_sms'] ?? false);
        $phone = trim((string) (strtoupper($call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone));
        if ($sendSms && $phone === '') {
            throw ValidationException::withMessages(['sms_body' => 'This call has no customer phone number to text.']);
        }
        if ($sendSms && trim((string) ($data['sms_body'] ?? '')) === '') {
            throw ValidationException::withMessages(['sms_body' => 'Write the follow-up message before sending.']);
        }
        if (isset($data['task_title']) && trim($data['task_title']) === '') {
            throw ValidationException::withMessages(['task_title' => 'The follow-up task needs a title.']);
        }
        $requestedKey = $data['idempotency_key'] ?? $headerKey;
        unset($data['idempotency_key']);
        ksort($data);
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'data' => $data], JSON_THROW_ON_ERROR));
        $key = $requestedKey ?: 'legacy-'.$hash;

        $operation = LockedWrite::run(fn () => DB::transaction(function () use ($call, $actor, $data, $key, $hash, $sendSms, $phone): VoiceWrapUpOperation {
            $operation = VoiceWrapUpOperation::query()->firstOrCreate(
                ['voice_call_id' => $call->id, 'idempotency_key' => $key],
                ['request_hash' => $hash, 'request_payload' => array_merge($data, ['sms_to' => $phone]), 'sms_status' => $sendSms ? 'pending' : 'draft'],
            );
            if (! hash_equals($operation->request_hash, $hash)) {
                throw new ConflictHttpException('This submission key was already used for different wrap-up details.');
            }
            if ($operation->wasRecentlyCreated) {
                $this->saveRecapAndTask($call->fresh(), $actor, $data, $operation);
            }

            return $operation;
        }), 'voice.wrap-up.prepare');

        if ($operation->response_json && ! in_array($operation->sms_status, ['uncertain', 'pending', 'sending'], true)) {
            return $operation->response_json;
        }

        if (! $sendSms) {
            return $this->finish($operation, 'draft');
        }

        // Claim before crossing the provider boundary. A repeated HTTP request
        // can recover a pending claim, but cannot re-send a sending operation.
        $claimed = LockedWrite::run(fn () => VoiceWrapUpOperation::query()
            ->whereKey($operation->id)->where('sms_status', 'pending')
            ->update(['sms_status' => 'sending', 'updated_at' => now()]), 'voice.wrap-up.claim-sms');

        if (! $claimed) {
            $message = $this->operationMessage($operation);
            if ($message) {
                return $this->finishFromMessage($operation, $message);
            }

            return $this->finish($operation, 'uncertain', null,
                'The send result is not confirmed. Check the SMS conversation before sending another message.');
        }

        try {
            $recipient = (string) $operation->request_payload['sms_to'];
            $message = $this->messaging->sendSms([
                'to' => $recipient,
                'body_text' => trim((string) $data['sms_body']),
                'user_id' => $actor->id,
                'contact_phone' => $recipient,
                'send_source' => 'MANUAL',
                'related_shoot_id' => $call->related_shoot_id,
                'metadata' => ['voice_wrap_up_operation_id' => $operation->id, 'voice_call_id' => $call->id],
            ]);

            return $this->finishFromMessage($operation, $message);
        } catch (Throwable $exception) {
            // sendSms persists its message before contacting Telnyx. Once that
            // row exists, a timeout may have delivered; never blindly retry it.
            $message = $this->operationMessage($operation);
            if ($message) {
                return $this->finishFromMessage($operation, $message);
            }

            return $this->finish($operation, 'failed', null,
                'The recap and task were saved, but the SMS could not be sent. Check the recipient and SMS settings.');
        }
    }

    private function saveRecapAndTask(VoiceCall $call, User $actor, array $data, VoiceWrapUpOperation $operation): void
    {
        $task = VoiceFollowUpTask::query()->where('voice_call_id', $call->id)->whereNull('automation_run_id')->first();
        if (($data['create_task'] ?? false) || ($task && isset($data['task_status']))) {
            $task ??= new VoiceFollowUpTask(['voice_call_id' => $call->id]);
            $task->fill([
                'title' => $data['task_title'] ?? $task->title ?? 'Follow up from call',
                'due_at' => $data['task_due_at'] ?? $task->due_at ?? now()->addHours(4),
                'assigned_to_user_id' => $task->assigned_to_user_id ?? $actor->id,
                'related_shoot_id' => array_key_exists('attach_shoot', $data)
                    ? ($data['attach_shoot'] ? $call->related_shoot_id : null)
                    : ($task->exists ? $task->related_shoot_id : $call->related_shoot_id),
                'status' => $data['task_status'] ?? $task->status ?? 'open',
            ]);
            $task->completed_at = $task->status === 'completed' ? ($task->completed_at ?? now()) : null;
            $task->save();
        }

        $metadata = $call->metadata ?? [];
        $previous = is_array($metadata['wrap_up'] ?? null) ? $metadata['wrap_up'] : [];
        $sms = is_array($previous['sms'] ?? null) ? $previous['sms'] : [];
        if (array_key_exists('sms_body', $data)) {
            $sms = ['body' => $data['sms_body'], 'status' => 'draft', 'drafted_at' => now()->toIso8601String()];
        }
        $followUp = $task
            ? VoiceFollowUpTask::query()->where('voice_call_id', $call->id)->where('status', 'open')->exists()
            : (bool) $call->needs_follow_up;
        $metadata['wrap_up'] = array_merge($previous, [
            'recap_title' => $data['recap_title'] ?? $previous['recap_title'] ?? null,
            'recap_body' => $data['recap_body'] ?? $previous['recap_body'] ?? null,
            'outcome' => $data['outcome'] ?? $previous['outcome'] ?? 'Follow-up required',
            'task' => $task?->toArray(),
            'sms' => $sms,
            'sms_sent' => false,
            'operation_id' => $operation->id,
            'idempotency_key' => $operation->idempotency_key,
            'saved_at' => now()->toIso8601String(),
            'saved_by' => $actor->id,
        ]);
        $metadata['needs_follow_up'] = $followUp;
        $call->forceFill([
            'summary' => $data['recap_body'] ?? $data['recap_title'] ?? $call->summary,
            'needs_follow_up' => $followUp,
            'metadata' => $metadata,
        ])->save();
    }

    private function operationMessage(VoiceWrapUpOperation $operation): ?Message
    {
        return $operation->message_id
            ? Message::query()->find($operation->message_id)
            : Message::query()->where('metadata->voice_wrap_up_operation_id', $operation->id)->first();
    }

    private function finishFromMessage(VoiceWrapUpOperation $operation, Message $message): array
    {
        $status = strtoupper((string) $message->status);
        if (in_array($status, ['SENT', 'DELIVERED'], true)) {
            return $this->finish($operation, 'sent', $message);
        }
        if ($status === 'BLOCKED') {
            return $this->finish($operation, 'blocked', $message, 'The recap and task were saved. SMS delivery is blocked by the delivery settings.');
        }

        return $this->finish($operation, 'uncertain', $message,
            'The recap and task were saved. The SMS send result is not confirmed; check the SMS conversation before sending again.');
    }

    private function finish(VoiceWrapUpOperation $operation, string $status, ?Message $message = null, ?string $error = null): array
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($operation, $status, $message, $error): array {
            $currentOperation = $operation->fresh();
            // A concurrent retry can have observed QUEUED just before the send
            // completed. Never replace a confirmed result with that stale view.
            if ($currentOperation->sms_status === 'sent' && $currentOperation->response_json) {
                return $currentOperation->response_json;
            }
            $call = VoiceCall::query()->findOrFail($operation->voice_call_id);
            $metadata = $call->metadata ?? [];
            $wrapUp = $metadata['wrap_up'] ?? [];
            $sms = $wrapUp['sms'] ?? [];
            if (($operation->request_payload['send_sms'] ?? false)) {
                $sms = array_filter([
                    'body' => $operation->request_payload['sms_body'] ?? '',
                    'status' => $status,
                    'message_id' => $message?->id,
                    'sent_at' => $status === 'sent' ? $message?->sent_at?->toIso8601String() : null,
                    'error' => $error,
                ], static fn ($value) => $value !== null);
            }
            // A slower send must not replace a newer recap or task edit.
            if (($wrapUp['operation_id'] ?? null) === $operation->id) {
                $wrapUp['sms'] = $sms;
                $wrapUp['sms_sent'] = $status === 'sent';
                $metadata['wrap_up'] = $wrapUp;
                $call->forceFill(['metadata' => $metadata])->save();
            }
            $response = $call->fresh()->load([
                'callerUser:id,name,email,phone,phonenumber',
                'callerContact:id,name,email,phone',
                'relatedShoot.photographer:id,name',
                'scheduledCallback',
            ])->toArray();
            $response['wrap_up_result'] = ['operation_id' => $operation->id, 'sms_status' => $status, 'sms_sent' => $status === 'sent', 'error' => $error];
            $operation->forceFill(['sms_status' => $status, 'message_id' => $message?->id, 'response_json' => $response])->save();

            return $response;
        }), 'voice.wrap-up.finish');
    }
}
