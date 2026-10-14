<?php

namespace App\Services\Voice;

use App\Jobs\StoreVoiceVoicemail;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Models\VoiceVoicemail;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Customer-leg voicemail is independent of staff/conversation recording consent. */
class VoiceVoicemailService
{
    public function __construct(private VoiceLinePolicyService $policies, private VoiceBrowserGateway $gateway) {}

    public function begin(VoiceCall $call, bool $closed): VoiceCall
    {
        $policy = $this->policies->forCall($call);
        if (! $policy || $policy->mode !== 'staff_voicemail' || $call->ended_at) {
            return $call;
        }
        $vm = LockedWrite::run(fn () => VoiceVoicemail::firstOrCreate(['voice_call_id' => $call->id], [
            'phone_number' => $policy->phone_number, 'recipient_ids' => $this->policies->recipients($policy)->pluck('id')->all(),
            'greeting_kind' => $closed ? 'closed' : 'office', 'expires_at' => now()->addDays(90),
        ]));
        VoiceLocks::lock('voicemail:'.$vm->id, 60)->block(5, function () use ($call, $vm): void {
            $vm->refresh();
            if ($vm->state !== 'answering') {
                return;
            }
            $call->refresh()->update(['intent' => 'voicemail', 'disposition' => 'voicemail', 'handled_by' => null,
                'needs_follow_up' => true, 'ai_current_state' => 'disabled',
                'metadata' => array_merge($call->metadata ?? [], ['voicemail' => true, 'voice_routing' => ['enabled' => false, 'mode' => 'staff_voicemail']])]);
            // Do not play before the asynchronous answer confirmation.
            $this->command($vm, 'answer', [], 'answer');
            if ($call->answered_at) {
                $this->greet($vm);
            }
        });

        return $call->fresh();
    }

    public function handleWebhook(array $data): ?array
    {
        $type = $data['event_type'] ?? $data['type'] ?? '';
        $payload = $data['payload'] ?? [];
        $control = $payload['call_control_id'] ?? null;
        if (! $control || $type === 'call.initiated') {
            return null;
        }
        $vm = VoiceVoicemail::whereHas('voiceCall', fn ($q) => $q->where('call_control_id', $control))->first();
        if (! $vm) {
            return null;
        }
        $store = false;
        VoiceLocks::lock('voicemail:'.$vm->id, 60)->block(5, function () use ($vm, $payload, $type, &$store): void {
            $vm->refresh();
            $call = $vm->voiceCall->fresh();
            $token = $payload['client_state'] ?? '';
            if (in_array($type, ['call.hangup', 'call.ended', 'call.failed', 'call.no_answer'], true)) {
                $call->update(['ended_at' => $call->ended_at ?? now(), 'status' => 'completed']);
                if (in_array($vm->state, ['recording', 'starting_recording'], true)) {
                    $vm->update(['state' => 'finalizing']);
                } elseif (in_array($vm->state, ['answering', 'greeting', 'fallback'], true)) {
                    $this->fail($vm, 'Caller hung up before leaving a message.', 'abandoned');
                }

                return;
            }
            // A completed provider message is immutable, including delayed errors.
            if (in_array($vm->state, ['saved', 'storing', 'expired', 'abandoned'], true)) {
                return;
            }
            if ($type === 'call.answered' && $vm->state === 'answering') {
                $call->update(['answered_at' => $call->answered_at ?? now(), 'status' => 'answered']);
                $this->greet($vm);
            } elseif ($type === 'call.playback.ended' && $vm->state === 'greeting' && $token === $this->token($vm, 'greeting')) {
                if (($payload['status'] ?? 'completed') === 'completed') {
                    $this->record($vm);
                } else {
                    $this->fallback($vm);
                }
            } elseif ($type === 'call.speak.ended' && $vm->state === 'fallback' && $token === $this->token($vm, 'fallback')) {
                $this->record($vm);
            } elseif (in_array($type, ['call.playback.error', 'call.speak.error'], true)) {
                if ($vm->state === 'greeting' && $token === $this->token($vm, 'greeting')) {
                    $this->fallback($vm);
                } elseif ($vm->state === 'fallback' && $token === $this->token($vm, 'fallback')) {
                    $this->fail($vm, 'Voicemail greeting and spoken fallback failed.');
                    $this->command($vm, 'hangup', [], 'hangup');
                }
            } elseif ($type === 'call.recording.saved' && $vm->recording_started_at && $token === $this->token($vm, 'recording')) {
                $id = $payload['recording_id'] ?? null;
                if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $id)) {
                    $this->fail($vm, 'Provider did not return a valid recording ID.');

                    return;
                }
                // Never bind a second recording to this message.
                if ($vm->recording_id && $vm->recording_id !== $id) {
                    return;
                }
                $duration = $payload['duration_seconds'] ?? null;
                if ($duration === null && isset($payload['recording_started_at'], $payload['recording_ended_at'])) {
                    $duration = max(0, strtotime($payload['recording_ended_at']) - strtotime($payload['recording_started_at']));
                }
                $vm->update(['state' => 'storing', 'recording_id' => $id, 'duration_seconds' => min(180, max(0, (int) $duration)), 'failure' => null]);
                $store = true;
                if (! $call->ended_at) {
                    $this->command($vm, 'hangup', [], 'hangup');
                }
            } elseif ($type === 'call.recording.error' && $vm->recording_started_at && $token === $this->token($vm, 'recording')) {
                $this->fail($vm, 'Voicemail recording failed. Please call the caller back.');
                if (! $call->ended_at) {
                    $this->command($vm, 'hangup', [], 'hangup');
                }
            }
        });
        if ($store) {
            StoreVoiceVoicemail::dispatch($vm->id)->afterCommit();
        }

        return ['status' => 'processed', 'voice_call_id' => $vm->voice_call_id];
    }

    private function greet(VoiceVoicemail $vm): void
    {
        $policy = $this->policies->forNumber($vm->phone_number);
        $path = $vm->greeting_kind === 'closed' ? $policy?->closed_greeting : $policy?->office_greeting;
        $vm->update(['state' => 'greeting']);
        if (! $path || ! Storage::disk('voice_private')->exists($path)) {
            $this->fallback($vm);

            return;
        }
        try {
            $this->command($vm, 'playback_start', ['playback_content' => base64_encode(Storage::disk('voice_private')->get($path)), 'audio_type' => 'mp3'], 'greeting');
        } catch (Throwable $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500 && ! in_array($e->getCode(), [408, 429], true)) {
                $this->fallback($vm);

                return;
            }
            // An ambiguous playback response must be reconciled with the same command ID,
            // never issue a second greeting. The watchdog retries this exact command.
            $vm->update(['failure' => 'Greeting command awaiting provider confirmation.']);
        }
    }

    private function fallback(VoiceVoicemail $vm): void
    {
        $vm->update(['state' => 'fallback', 'failure' => null]);
        $this->command($vm, 'speak', ['payload' => 'Please leave your name, number, and a message after the beep.', 'voice' => 'female', 'language' => 'en-US'], 'fallback');
    }

    private function record(VoiceVoicemail $vm): void
    {
        if ($vm->voiceCall->fresh()->ended_at || $vm->recording_started_at) {
            return;
        }
        $vm->update(['state' => 'starting_recording', 'recording_started_at' => now()]);
        try {
            $this->command($vm, 'record_start', [
                'format' => 'mp3', 'channels' => 'single', 'recording_track' => 'inbound',
                'play_beep' => true, 'max_length' => 180, 'timeout_secs' => 5,
            ], 'recording');
            $vm->refresh();
            if ($vm->state === 'starting_recording') {
                $vm->update(['state' => 'recording']);
            }
        } catch (Throwable $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500 && ! in_array($e->getCode(), [408, 429], true)) {
                $this->fail($vm, 'Voicemail recording command rejected. A callback is required.');
                $this->command($vm, 'hangup', [], 'hangup');

                return;
            }
            // Unknown success stays pending; maintenance retries the identical command.
            $vm->update(['failure' => 'Recording command awaiting provider confirmation.']);
        }
    }

    public function command(VoiceVoicemail $vm, string $action, array $payload, string $stage): void
    {
        $this->gateway->command('voicemail:'.$vm->id.':'.$stage, '/calls/'.rawurlencode($vm->voiceCall->call_control_id).'/actions/'.$action,
            array_merge($payload, ['client_state' => $this->token($vm, $stage)]));
    }

    private function token(VoiceVoicemail $vm, string $stage): string
    {
        return base64_encode('voicemail:'.$vm->id.':'.$stage);
    }

    public function fail(VoiceVoicemail $vm, string $reason, string $state = 'failed'): void
    {
        $vm->refresh();
        if (in_array($vm->state, ['saved', 'expired'], true) || $vm->expires_at->isPast()) {
            return;
        }
        LockedWrite::run(function () use ($vm, $reason, $state): void {
            $vm->update(['state' => $state, 'failure' => $reason]);
            $vm->voiceCall->update(['disposition' => 'voicemail_failed', 'needs_follow_up' => true, 'summary' => $reason]);
            VoiceFollowUpTask::firstOrCreate(['voice_call_id' => $vm->voice_call_id], [
                'title' => 'Call back: '.$vm->voiceCall->from_phone.' (voicemail unavailable)',
                'assigned_to_user_id' => $vm->recipient_ids[0] ?? null, 'due_at' => now()->addHours(4), 'status' => 'open',
            ]);
        });
    }

    public function notifications(User $user, int $limit): \Illuminate\Support\Collection
    {
        $query = VoiceCall::whereHas('voicemail', fn ($q) => $q->where('state', 'saved')->where('expires_at', '>', now()))->with('voicemail');
        $this->policies->scope($query, $user);

        return $query->latest()->limit($limit)->get()->map(fn ($call) => [
            'id' => 'voicemail-'.$call->voicemail->id, 'type' => 'system', 'action' => 'voicemail_saved',
            'message' => 'New voicemail from '.$call->from_phone.' on '.$call->voicemail->phone_number,
            'timestamp' => $call->voicemail->saved_at->toIso8601String(), 'actionUrl' => '/calls/inbox/'.$call->id.'?filter=voicemail', 'actionLabel' => 'Listen',
        ]);
    }

    /** Recover lost confirmations with the same durable command, never a new recording. */
    public function maintain(): void
    {
        VoiceVoicemail::whereNotIn('state', ['saved', 'failed', 'abandoned', 'expired'])->where('updated_at', '<', now()->subSeconds(60))
            ->limit(100)->get()->each(function (VoiceVoicemail $vm): void {
                VoiceLocks::lock('voicemail:'.$vm->id, 60)->block(5, function () use ($vm): void {
                    $vm->refresh();
                    try {
                        if ($vm->state === 'storing') {
                            StoreVoiceVoicemail::dispatch($vm->id)->onConnection('database');

                            return;
                        }
                        $command = \App\Models\VoiceBrowserCommand::where('command_key', 'voicemail:'.$vm->id.':'.match ($vm->state) {
                            'answering' => 'answer', 'greeting' => 'greeting', 'fallback' => 'fallback', default => 'recording',
                        })->first();
                        if ($command && in_array($command->state, ['sending', 'uncertain'], true) && ! $vm->voiceCall->ended_at) {
                            $this->gateway->command($command->command_key, $command->path, $command->payload);
                            if ($vm->state === 'starting_recording') {
                                $vm->update(['state' => 'recording', 'failure' => null]);
                            }

                            return;
                        }
                        if ($vm->state === 'greeting' && $vm->updated_at->lt(now()->subSeconds(100)) && ! $vm->voiceCall->ended_at) {
                            $this->command($vm, 'playback_stop', [], 'stop_greeting');
                            $this->fallback($vm);

                            return;
                        }
                        if ($vm->created_at->lt(now()->subMinutes(6))) {
                            $this->fail($vm, 'Voicemail did not complete. A callback is required.');
                            if (! $vm->voiceCall->ended_at) {
                                $this->command($vm, 'hangup', [], 'hangup');
                            }
                        }
                    } catch (Throwable $e) {
                        if ($vm->created_at->lt(now()->subMinutes(6))) {
                            $this->fail($vm, 'Phone provider could not complete voicemail. A callback is required.');
                        }
                    }
                });
            });
        VoiceVoicemail::where('expires_at', '<=', now())->where(fn ($q) => $q->where('state', '!=', 'expired')->orWhereNotNull('failure'))
            ->limit(100)->get()->each(function (VoiceVoicemail $vm): void {
                VoiceLocks::lock('voicemail:'.$vm->id, 60)->block(5, function () use ($vm): void {
                    $vm->refresh()->update(['state' => 'expired', 'failure' => 'Retention deletion pending.']);
                    try {
                        if ($vm->recording_path) {
                            if (! Storage::disk('voice_private')->delete($vm->recording_path)) {
                                throw new \RuntimeException('Private recording deletion pending.');
                            }
                        }
                        if ($vm->recording_id) {
                            $this->gateway->request('DELETE', '/recordings/'.rawurlencode($vm->recording_id));
                        }
                        $vm->update(['recording_path' => null, 'failure' => null]);
                    } catch (Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Voicemail retention deletion pending.', ['voicemail_id' => $vm->id]);
                    }
                });
            });
    }
}
