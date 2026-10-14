<?php

namespace App\Services\Voice;

use App\Jobs\CloseVoiceOfferDevices;
use App\Jobs\SendIncomingCallToRobbie;
use App\Models\User;
use App\Models\VoiceBrowserCommand;
use App\Models\VoiceIncomingOffer;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use RuntimeException;

/** A staff decision on one ringing call; never changes automatic line routing. */
class ManualVoiceHandoffService
{
    public function available(VoiceIncomingOffer $offer, User $user): bool
    {
        $call = $offer->voiceCall;

        return app(VoiceBrowserSessionService::class)->canOperate($user)
            && in_array($user->id, $offer->eligible_user_ids, true)
            && app(VoiceLinePolicyService::class)->canAccess($user, $call)
            && app(VoiceLinePolicyService::class)->forCall($call)?->mode !== 'sms_only'
            && (bool) app(VoiceSettingsService::class)->all()['enabled']
            && filled(app(VoiceNumberSettingsResolver::class)->forCall($call)['assistant_id'])
            && ! $call->voicemail && ! $call->ended_at && filled($call->call_control_id);
    }

    public function send(VoiceIncomingOffer $offer, User $user, string $key): array
    {
        VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(5, function () use ($offer, $user, $key): void {
            $offer->refresh();
            abort_unless($this->available($offer, $user), 403, 'Robbie is unavailable for this call or your account.');
            if (in_array($offer->status, ['robbie_pending', 'robbie'], true)) {
                abort_unless(($offer->metadata['robbie_actor_id'] ?? null) === $user->id && ($offer->metadata['robbie_key'] ?? null) === $key, 409, 'Another teammate already handled this call.');

                return;
            }
            abort_unless($offer->status === 'waiting' && $offer->expires_at->isFuture(), 409, 'This call is no longer waiting.');
            $assistant = app(VoiceNumberSettingsResolver::class)->forCall($offer->voiceCall)['assistant_id'];
            LockedWrite::run(function () use ($offer, $user, $key, $assistant): void {
                $offer->update(['status' => 'robbie_pending', 'metadata' => array_merge($offer->metadata ?? [], ['robbie_actor_id' => $user->id, 'robbie_key' => $key, 'robbie_assistant_id' => $assistant])]);
                $call = $offer->voiceCall;
                $call->update(['metadata' => array_merge($call->metadata ?? [], ['manual_robbie_requested_by' => $user->id, 'manual_robbie_requested_at' => now()->toIso8601String()])]);
            });
            CloseVoiceOfferDevices::dispatch($offer->id);
            SendIncomingCallToRobbie::dispatch($offer->id);
        });

        return ['status' => $offer->fresh()->status, 'voice_call_id' => $offer->voice_call_id];
    }

    public function resume(VoiceIncomingOffer $offer): void
    {
        VoiceLocks::lock('manual-robbie:'.$offer->id, 60)->block(5, function () use ($offer): void {
            $offer->refresh();
            $call = $offer->voiceCall->fresh();
            if ($offer->status !== 'robbie_pending' || $call->ended_at) return;
            $gateway = app(VoiceBrowserGateway::class);
            $path = '/calls/'.rawurlencode($call->call_control_id).'/actions/';
            if (! $call->answered_at) {
                $gateway->command('manual-robbie-answer:'.$offer->id, $path.'answer');
                throw new RuntimeException('Waiting for the provider to confirm answer.');
            }
            $result = $gateway->command('manual-robbie-start:'.$offer->id, $path.'ai_assistant_start', [
                'assistant' => ['id' => $offer->metadata['robbie_assistant_id'], 'dynamic_variables' => ['business_line' => $call->to_phone, 'handoff_reason' => 'Staff explicitly sent this incoming call to Robbie.']],
                'send_message_history_updates' => true,
            ]);
            LockedWrite::run(function () use ($offer, $call, $result): void {
                $call->refresh();
                if ($call->ended_at) return;
                $call->update(['assistant_id' => $offer->metadata['robbie_assistant_id'], 'handled_by' => 'ai', 'status' => 'ai_active', 'ai_current_state' => 'active',
                    'telnyx_conversation_id' => $result['conversation_id'] ?? $call->telnyx_conversation_id,
                    'metadata' => array_merge($call->metadata ?? [], ['manual_robbie_accepted_at' => now()->toIso8601String()])]);
                $offer->update(['status' => 'robbie']);
            });
        });
    }

    public function failed(VoiceIncomingOffer $offer, ?\Throwable $exception = null): void
    {
        $call = $offer->voiceCall->fresh();
        if ($call->ended_at || $offer->fresh()->status !== 'robbie_pending') return;
        $start = VoiceBrowserCommand::where('command_key', 'manual-robbie-start:'.$offer->id)->first();
        // An uncertain start must be stopped and acknowledged before playing voicemail.
        $code = $exception?->getCode() ?? 0;
        $rejected = $code >= 400 && $code < 500 && ! in_array($code, [408, 429], true);
        if ($start && ! $rejected) {
            try {
                app(VoiceBrowserGateway::class)->command('manual-robbie-stop:'.$offer->id, '/calls/'.rawurlencode($call->call_control_id).'/actions/ai_assistant_stop');
            } catch (\Throwable $e) {
                $call->update(['needs_follow_up' => true, 'disposition' => 'robbie_handoff_failed', 'summary' => 'Provider did not confirm the Robbie handoff. Staff must check this call.', 'metadata' => array_merge($call->metadata ?? [], ['manual_robbie_error' => 'Provider did not confirm handoff. Staff must check this call.'])]);

                return;
            }
        }
        $offer->update(['status' => 'cancelled']);
        app(\App\Services\TelnyxAi\VoiceRoutingService::class)->routeWithoutAi($call, 'manual_robbie_failed', true);
    }
}
