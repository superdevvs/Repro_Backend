<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceMobileSession;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Explicit speech capture, independent of audio recording consent and commands. */
class VoiceHumanTranscriptionService
{
    public function __construct(private VoiceBrowserGateway $gateway) {}

    public function state(?VoiceCall $call, User $user): array
    {
        $control = (array) data_get($call?->metadata, 'human_transcription', []);
        $ended = $call && ($call->ended_at || in_array($call->status, ['completed', 'failed', 'cancelled', 'missed', 'transferred'], true));
        $browser = $call ? VoiceBrowserCall::where('voice_call_id', $call->id)->latest()->first() : null;
        $available = $call && $browser && $this->eligible($call, $browser, $user);
        $enabled = ! $ended && (bool) data_get($call?->metadata, 'browser_transcription_enabled', false);
        $state = $ended ? 'ended' : ($control['state'] ?? ($enabled ? 'active' : 'off'));

        return ['state' => $state, 'available' => (bool) $available, 'enabled' => $enabled,
            'consent_given' => (bool) ($control['consented'] ?? $call?->recording_consent_given ?? false),
            'error' => in_array($control['error'] ?? null, ['start_unconfirmed', 'stop_unconfirmed'], true) ? $control['error'] : null];
    }

    public function allowsSegments(VoiceCall $call): bool
    {
        $control = (array) data_get($call->metadata, 'human_transcription', []);

        return ! empty($call->metadata['browser_transcription_enabled'])
            && (($control['managed'] ?? false) ? ($control['consented'] ?? false) : $call->recording_consent_given);
    }

    public function control(VoiceCall $call, User $user, string $action, bool $consented, string $key, ?VoiceMobileSession $mobile = null, ?string $sdkID = null): array
    {
        $browser = VoiceBrowserCall::where('voice_call_id', $call->id)->latest()->first();
        abort_unless($browser && (int) $browser->owner_id === (int) $user->id, 403);

        return VoiceLocks::lock('voice-browser-call:'.$browser->id, 90)->block(5, fn () => VoiceLocks::lock('voice-recording-control:'.$call->id, 75)->block(5, function () use ($call, $browser, $user, $action, $consented, $key, $mobile, $sdkID) {
            $call->refresh();
            $browser->refresh();
            $user = $user->fresh();
            abort_unless($user && app(VoiceBrowserSessionService::class)->canOperate($user)
                && app(VoiceLinePolicyService::class)->canAccess($user, $call), 403);
            abort_if($call->ended_at || $browser->state !== 'active', 409, 'The call is not connected.');
            abort_unless($this->customer($browser) && $browser->legs()->where('role', 'agent')->whereNotNull('joined_at')->whereNull('ended_at')->exists(), 409, 'The call is not connected.');
            abort_if($mobile && ! $this->nativeBound($browser, $mobile, (string) $sdkID), 403, 'This device no longer owns the live call.');
            abort_unless($this->eligible($call, $browser, $user), 403, 'This call is no longer authorized for capture.');
            abort_unless(in_array($action, ['start', 'stop'], true), 422);
            abort_if($action === 'start' && ! $consented, 422, 'Confirm caller consent before starting live transcription.');
            $customer = $this->customer($browser);
            $control = (array) data_get($call->metadata, 'human_transcription', []);
            $requestKey = 'human-transcription:'.$user->id.':'.strtolower($key);
            $receipt = VoiceCallEvent::where('idempotency_key', $requestKey)->first();
            if ($receipt) {
                abort_unless((int) $receipt->voice_call_id === (int) $call->id
                    && data_get($receipt->raw_payload, 'action') === $action
                    && data_get($receipt->raw_payload, 'customer_leg_id') === $customer->id
                    && data_get($receipt->raw_payload, 'customer_control_id') === $customer->call_control_id, 409, 'This request key has different capture details.');
                if ($receipt->processed_at) {
                    return $this->state($call, $user);
                }
                abort_unless((int) data_get($receipt->raw_payload, 'generation') === (int) ($control['generation'] ?? 0), 409, 'This capture request has been superseded.');
                abort_unless(($control['request_key'] ?? null) === $requestKey, 409, 'This capture request has been superseded.');
            }
            abort_if($action === 'start' && ($control['stop_pending'] ?? false), 409, 'Confirm transcription has stopped before starting again.');
            $enabled = (bool) ($call->metadata['browser_transcription_enabled'] ?? false);
            $pending = (bool) ($call->metadata['browser_transcription_pending'] ?? false);
            abort_if($action === 'start' && $pending && ! $enabled && ($control['request_key'] ?? null) !== $requestKey,
                409, 'Resolve the pending capture request or confirm it stopped before starting again.');
            $generation = (int) ($control['generation'] ?? 0);
            if (! $receipt && $action === 'start' && ! $enabled && ! $pending) {
                $generation++;
            }
            $needed = $action === 'start' ? ! $enabled : ($enabled || $pending || ($control['stop_pending'] ?? false));
            $control = array_merge($control, ['managed' => true, 'consented' => $action === 'start',
                'request_key' => $requestKey,
                'consented_by' => $user->id, 'consent_recorded_at' => now()->toIso8601String(),
                'generation' => $generation, 'customer_leg_id' => $customer->id,
                'customer_control_id' => $customer->call_control_id, 'error' => null,
                'state' => $needed ? ($action === 'start' ? 'starting' : 'stopping') : ($action === 'start' ? 'active' : 'off'),
                'stop_pending' => $action === 'stop' && $needed]);
            // Consent withdrawal and the receipt commit before network I/O.
            // A failed/uncertain stop must continue rejecting late chunks.
            $receipt = LockedWrite::run(fn () => DB::transaction(function () use ($call, $user, $action, $requestKey, $receipt, $control, $customer, $generation, $needed) {
                $receipt ??= VoiceCallEvent::create(['voice_call_id' => $call->id, 'provider' => 'internal',
                    'event_type' => 'human.transcription.'.$action, 'normalized_type' => 'transcription_control',
                    'idempotency_key' => $requestKey, 'received_at' => now(), 'raw_payload' => [
                        'action' => $action, 'user_id' => $user->id, 'generation' => $generation,
                        'customer_leg_id' => $customer->id, 'customer_control_id' => $customer->call_control_id]]);
                $this->save($call, $control, $action === 'start' && ! $needed, $needed);
                if (! $needed) {
                    $receipt->update(['processed_at' => now(), 'processing_error' => null]);
                }

                return $receipt;
            }), 'voice.transcription.intent');
            if (! $needed) {
                return $this->state($call->fresh(), $user);
            }
            try {
                $payload = $action === 'start' ? ['transcription_engine' => 'Telnyx', 'transcription_tracks' => 'both',
                    'transcription_engine_config' => ['transcription_engine' => 'Telnyx',
                        'transcription_model' => 'openai/whisper-large-v3-turbo', 'language' => 'auto_detect']] : [];
                $this->gateway->command('human-transcription-'.$action.':'.$call->id.':'.$generation,
                    '/calls/'.rawurlencode($customer->call_control_id).'/actions/transcription_'.$action, $payload);
                $control['state'] = $action === 'start' ? 'active' : 'off';
                $control['stop_pending'] = false;
                LockedWrite::run(fn () => DB::transaction(function () use ($call, $control, $action, $receipt) {
                    $this->save($call, $control, $action === 'start', false);
                    $receipt->update(['processed_at' => now(), 'processing_error' => null]);
                }), 'voice.transcription.confirm');
            } catch (Throwable $e) {
                $control['state'] = 'error';
                $control['error'] = $action === 'start' ? 'start_unconfirmed' : 'stop_unconfirmed';
                LockedWrite::run(function () use ($call, $control, $receipt) {
                    $this->save($call, $control, false, true);
                    $receipt->update(['processing_error' => $control['error']]);
                }, 'voice.transcription.uncertain');
                abort(503, 'Transcription was not confirmed. Refresh and retry the same request.');
            }

            return $this->state($call->fresh(), $user);
        }));
    }

    private function save(VoiceCall $call, array $control, bool $enabled, bool $pending): void
    {
        $call->refresh();
        $call->update(['metadata' => array_merge($call->metadata ?? [], ['human_transcription' => $control,
            'browser_transcription_enabled' => $enabled, 'browser_transcription_pending' => $pending])]);
    }

    public function nativeBound(VoiceBrowserCall $browser, VoiceMobileSession $session, string $sdkID): bool
    {
        if (! app(VoiceMobileSessionService::class)->authorized($session)) {
            return false;
        }
        $agent = $browser->legs()->where('role', 'agent')->whereNotNull('joined_at')->whereNull('ended_at')->latest()->first();
        if (! $agent || $agent->mode !== 'mobile' || $agent->mobile_session_id !== $session->id) {
            return false;
        }
        $ticket = \App\Models\VoiceMobileTicket::where('session_id', $session->id)->whereKey(strtolower($sdkID))->first();
        if ($ticket) {
            return $ticket->state === 'started' && (int) $ticket->voice_call_id === (int) $browser->voice_call_id
                && $ticket->call_control_id === $agent->call_control_id;
        }
        $offer = \App\Models\VoiceMobileOffer::where('session_id', $session->id)->where('sdk_call_id', strtolower($sdkID))->first();

        return $offer && $offer->state === 'accepted' && $offer->call_control_id === $agent->call_control_id
            && $offer->incomingOffer?->status === 'claimed'
            && $offer->incomingOffer->claim_key === 'mobile:'.$offer->id
            && $offer->incomingOffer->browser_call_id === $browser->id
            && (int) $offer->incomingOffer->voice_call_id === (int) $browser->voice_call_id;
    }

    private function customer(VoiceBrowserCall $browser): ?VoiceBrowserLeg
    {
        return $browser->legs()->where('role', 'customer')->whereNotNull('joined_at')->whereNull('ended_at')->whereNotNull('call_control_id')->first();
    }

    private function eligible(VoiceCall $call, VoiceBrowserCall $browser, User $user): bool
    {
        if ($call->ended_at || in_array($call->status, ['completed', 'failed', 'cancelled', 'missed', 'transferred'], true)
            || $browser->state !== 'active' || (int) $browser->owner_id !== (int) $user->id
            || ! app(VoiceBrowserSessionService::class)->canOperate($user)
            || ! app(VoiceLinePolicyService::class)->canAccess($user, $call)
            || ! config('services.telnyx.voice.browser_enabled') || ! filled(config('services.telnyx.api_key'))
            || ! filled(config('services.telnyx.voice.connection_id')) || ! filled(config('services.telnyx.voice.webhook_url'))
            || ! $this->customer($browser)) {
            return false;
        }
        $agent = $browser->legs()->where('role', 'agent')->whereNotNull('joined_at')->whereNull('ended_at')->latest()->first();
        if (! $agent || (int) $agent->user_id !== (int) $user->id) {
            return false;
        }
        if ($agent->mode === 'mobile') {
            $session = \App\Models\VoiceMobileSession::find($agent->mobile_session_id);

            return $session && app(VoiceMobileSessionService::class)->authorized($session);
        }
        if ($agent->mode === 'sip') {
            $device = \App\Models\VoiceSipDevice::find($agent->sip_device_id);

            return $device && app(VoiceSipDeviceService::class)->allowed($device);
        }
        if ($agent->mode === 'phone') {
            return true;
        }

        return $agent->session && app(VoiceBrowserSessionService::class)->usable($agent->session);
    }
}
