<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceCall;
use App\Models\VoiceLinePolicy;
use App\Models\VoicePhoneOffer;
use App\Models\VoiceSipDevice;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/** Native SIP endpoints remain behind the Dashboard Call Control application. */
class VoiceSipDeviceService
{
    public function __construct(private VoiceBrowserGateway $gateway, private VoiceBrowserSessionService $sessions) {}

    public function configured(): bool
    {
        return (bool) config('services.telnyx.voice.browser_enabled') && filled(config('services.telnyx.api_key'))
            && filled(config('services.telnyx.voice.connection_id')) && filled(config('services.telnyx.voice.webhook_url'));
    }

    public function lines(User $user): array
    {
        if (! $this->sessions->canOperate($user)) {
            return [];
        }
        $policies = app(VoiceLinePolicyService::class);

        return VoiceLinePolicy::where('mode', '!=', 'sms_only')->get()->filter(fn ($p) => $policies->canAccess($user, new VoiceCall(['direction' => 'OUTBOUND', 'from_phone' => $p->phone_number])))
            ->map(fn ($p) => ['number' => $p->phone_number,
                'label' => app(VoiceNumberSettingsResolver::class)->matching($p->phone_number)->first()?->label ?? $p->phone_number,
                'receives_calls' => $p->mode === 'staff_voicemail' && $policies->recipients($p)->contains('id', $user->id),
                'timezone' => $p->timezone, 'weekly_hours' => $p->weekly_hours])->values()->all();
    }

    public function allowed(VoiceSipDevice $device): bool
    {
        return $device->enabled && $this->authorized($device);
    }

    public function authorized(VoiceSipDevice $device): bool
    {
        return ! $device->revoked_at && $device->state === 'ready' && $device->connection_id
            && $device->user && in_array($device->caller_id, array_column($this->lines($device->user), 'number'), true);
    }

    public function state(User $user): array
    {
        $devices = VoiceSipDevice::where('user_id', $user->id)->whereNull('revoked_at')->get();
        foreach ($devices as $device) {
            if ($this->allowed($device)) {
                $this->registered($device);
            }
        }

        return ['configured' => $this->configured(), 'lines' => $this->lines($user),
            'devices' => $devices->map(fn ($d) => $this->present($d))->all()];
    }

    private function present(VoiceSipDevice $d): array
    {
        return ['id' => $d->id, 'creation_key' => $d->creation_key, 'label' => $d->label, 'caller_id' => $d->caller_id, 'state' => $d->state,
            'enabled' => $d->enabled, 'registered' => $d->registered, 'registration_checked_at' => $d->registration_checked_at?->toIso8601String()];
    }

    public function create(User $user, array $data): array
    {
        abort_unless($this->sessions->canOperate($user) && $this->configured(), 403);
        abort_unless(in_array($data['caller_id'], array_column($this->lines($user), 'number'), true), 422, 'Choose an assigned voice business line.');

        return VoiceLocks::lock('voice-sip-enrol:'.$user->id, 60)->block(5, function () use ($user, $data): array {
            $device = VoiceSipDevice::where('user_id', $user->id)->where('creation_key', $data['creation_key'])->first();
            if (! $device) {
                abort_if(VoiceSipDevice::where('user_id', $user->id)->whereNull('revoked_at')->exists(), 409, 'Revoke your existing Groundwire device before adding a replacement.');
                $device = LockedWrite::run(fn () => VoiceSipDevice::create(['user_id' => $user->id, 'creation_key' => $data['creation_key'],
                    'label' => $data['label'], 'caller_id' => $data['caller_id'], 'sip_username' => 'gw'.Str::lower(Str::random(24)), 'sip_password' => Str::random(48)]));
            }
            abort_if($device->revoked_at || $device->label !== $data['label'] || $device->caller_id !== $data['caller_id'], 409, 'This setup request has different details.');
            if (! $device->connection_id) {
                $name = 'repro-groundwire-'.$device->id;
                // A stable connection name reconciles an ambiguous create without a second credential.
                $found = $this->gateway->request('GET', '/credential_connections', ['filter[connection_name][eq]' => $name]);
                $matches = collect($found)->filter(fn ($c) => is_array($c) && ($c['connection_name'] ?? '') === $name && ($c['user_name'] ?? '') === $device->sip_username)->values();
                abort_if($matches->count() > 1, 503, 'Phone setup needs provider reconciliation.');
                $connection = $matches->first();
                if (! $connection) {
                    abort_if($device->provision_attempted_at, 503, 'The provider has not confirmed setup. Retry this same setup later.');
                    LockedWrite::run(fn () => $device->update(['provision_attempted_at' => now()]));
                    try {
                        $connection = $this->gateway->request('POST', '/credential_connections', [
                            'connection_name' => $name, 'user_name' => $device->sip_username, 'password' => $device->sip_password,
                            'active' => true, 'encrypted_media' => 'SRTP', 'sip_uri_calling_preference' => 'internal', 'webhook_api_version' => '2',
                            'webhook_event_url' => config('services.telnyx.voice.webhook_url'),
                            'inbound' => ['ani_number_format' => '+E.164', 'dnis_number_format' => 'sip_username', 'codecs' => ['G722', 'G711U', 'G711A'], 'simultaneous_ringing' => 'disabled', 'timeout_2xx_secs' => 45],
                            // No direct PSTN profile: every normal outbound call is parked for Dashboard authorization.
                            'outbound' => ['call_parking_enabled' => true, 'channel_limit' => 1, 'localization' => 'US'],
                        ]);
                    } catch (Throwable $e) {
                        LockedWrite::run(fn () => $device->update(['state' => 'uncertain',
                            'provision_attempted_at' => ($e->getCode() >= 400 && $e->getCode() < 500 && ! in_array($e->getCode(), [408, 429], true)) ? null : $device->provision_attempted_at]));
                        throw $e;
                    }
                }
                abort_unless(! empty($connection['id']), 503, 'The provider did not return a connection ID.');
                LockedWrite::run(fn () => $device->update(['connection_id' => (string) $connection['id'], 'state' => 'verifying']));
            }
            $verified = $this->gateway->request('GET', '/credential_connections/'.$device->connection_id);
            abort_unless(($verified['user_name'] ?? '') === $device->sip_username && ($verified['active'] ?? false)
                && ($verified['encrypted_media'] ?? '') === 'SRTP' && ($verified['sip_uri_calling_preference'] ?? '') === 'internal'
                && ($verified['webhook_api_version'] ?? '') === '2' && ($verified['webhook_event_url'] ?? '') === config('services.telnyx.voice.webhook_url')
                && ($verified['outbound']['call_parking_enabled'] ?? false) && empty($verified['outbound']['outbound_voice_profile_id']),
                503, 'The SIP connection settings need verification before use.');
            LockedWrite::run(fn () => $device->update(['state' => 'ready']));

            return $this->present($device->fresh());
        });
    }

    public function credentials(User $user, VoiceSipDevice $device): array
    {
        abort_unless($device->user_id === $user->id && $this->allowed($device), 403);

        return ['title' => 'REPro · '.$user->name, 'username' => $device->sip_username, 'password' => $device->sip_password,
            'domain' => 'sip.telnyx.com', 'port' => 5061, 'transport' => 'TLS (sip)', 'media' => 'SRTP', 'caller_id' => $device->caller_id];
    }

    public function update(User $user, VoiceSipDevice $device, array $data): array
    {
        abort_unless($device->user_id === $user->id && ! $device->revoked_at && $this->sessions->canOperate($user), 403);
        if (isset($data['caller_id'])) {
            abort_unless(in_array($data['caller_id'], array_column($this->lines($user), 'number'), true), 422, 'Choose an assigned voice business line.');
        }
        LockedWrite::run(fn () => $device->update($data));

        return $this->present($device->fresh());
    }

    public function revoke(User $user, VoiceSipDevice $device): void
    {
        abort_unless($device->user_id === $user->id, 403);
        LockedWrite::run(fn () => $device->update(['revoked_at' => $device->revoked_at ?? now(), 'enabled' => false, 'registered' => false, 'state' => 'revocation_pending']));
        $this->finishRevoke($device);
    }

    private function finishRevoke(VoiceSipDevice $device): void
    {
        if (! $device->connection_id && $device->provision_attempted_at) {
            $found = $this->gateway->request('GET', '/credential_connections', ['filter[connection_name][eq]' => 'repro-groundwire-'.$device->id]);
            foreach ($found as $c) {
                if (is_array($c) && ($c['user_name'] ?? '') === $device->sip_username) {
                    $this->gateway->request('PATCH', '/credential_connections/'.$c['id'], ['active' => false]);
                }
            }
        }
        if ($device->connection_id) {
            $this->gateway->request('PATCH', '/credential_connections/'.$device->connection_id, ['active' => false]);
        }
        Cache::forget('voice-sip-registration-v2:'.$device->id);
        LockedWrite::run(fn () => $device->update(['state' => 'revoked']));
    }

    public function reconcile(): void
    {
        foreach (VoiceSipDevice::where('state', 'revocation_pending')->limit(20)->get() as $device) {
            try {
                $this->finishRevoke($device);
            } catch (Throwable) { /* Local revocation remains effective; retry next cycle. */
            }
        }
    }

    public function registered(VoiceSipDevice $device): bool
    {
        if (! $this->allowed($device)) {
            return false;
        }
        try {
            // The legacy check action performs a slow SIP probe. This endpoint reads the live registrar directly.
            $result = Cache::remember('voice-sip-registration-v2:'.$device->id, 10, fn () => $this->gateway->request('GET', '/sip_registration_status', [
                'credential_type' => 'sip_credential_connection', 'username' => $device->sip_username,
            ], timeout: 3));
            $ready = ($result['registered'] ?? null) === true && ($result['sip_registration_status'] ?? '') === 'registered'
                && ($result['credential_type'] ?? '') === 'sip_credential_connection'
                && (string) ($result['connection_id'] ?? '') === $device->connection_id
                && ($result['credential_username'] ?? '') === $device->sip_username;
        } catch (Throwable) {
            $ready = false;
        }
        // A concurrent revoke or pause cannot be revived by a delayed registration result.
        $updated = LockedWrite::run(fn () => VoiceSipDevice::whereKey($device->id)->whereNull('revoked_at')->where('enabled', true)->where('state', 'ready')
            ->update(['registered' => $ready, 'registration_checked_at' => now()]));
        $device->refresh();

        return $updated === 1 && $ready && $this->allowed($device);
    }

    public function reachable(User $user): bool
    {
        return VoiceSipDevice::where('user_id', $user->id)->whereNull('revoked_at')->where('enabled', true)->where('state', 'ready')->get()->contains(fn ($d) => $this->registered($d));
    }

    public function offer(\App\Models\VoiceIncomingOffer $offer): void
    {
        $policy = app(VoiceLinePolicyService::class)->forCall($offer->voiceCall);
        if ($policy?->mode !== 'staff_voicemail') {
            return;
        }
        foreach (VoiceSipDevice::whereIn('user_id', $offer->eligible_user_ids)->whereNull('revoked_at')->where('enabled', true)->where('state', 'ready')->get() as $device) {
            if (! $this->registered($device)) {
                continue;
            }
            $phone = LockedWrite::run(fn () => VoicePhoneOffer::firstOrCreate(['incoming_offer_id' => $offer->id, 'user_id' => $device->user_id], [
                'sip_device_id' => $device->id, 'destination' => 'sip:'.$device->sip_username.'@sip.telnyx.com', 'expires_at' => $offer->expires_at, 'client_state' => base64_encode((string) Str::uuid())]));
            \App\Jobs\DialVoiceStaffPhone::dispatch($phone->id)->afterCommit();
        }
    }

    public function validOffer(VoicePhoneOffer $phone): bool
    {
        $device = $phone->sipDevice;
        $policy = app(VoiceLinePolicyService::class)->forCall($phone->incomingOffer->voiceCall);

        return $device && $this->allowed($device) && $device->user_id === $phone->user_id && $policy?->mode === 'staff_voicemail'
            && app(VoiceLinePolicyService::class)->open($policy) && app(VoiceLinePolicyService::class)->recipients($policy)->contains('id', $phone->user_id)
            && $phone->destination === 'sip:'.$device->sip_username.'@sip.telnyx.com';
    }

    /** Signature verification occurs before this method. Identity is the provider connection, never a caller-supplied SIP From header. */
    public function webhook(array $envelope): ?array
    {
        $data = $envelope['data'] ?? $envelope;
        $p = $data['payload'] ?? $data;
        $type = $data['event_type'] ?? '';
        $control = (string) ($p['call_control_id'] ?? '');
        $device = VoiceSipDevice::where('connection_id', (string) ($p['connection_id'] ?? ''))->first();
        if (! $device || $control === '') {
            return null;
        }
        if (VoiceBrowserLeg::where('call_control_id', $control)->where('sip_device_id', $device->id)->exists()) {
            return null;
        }
        // Receiving SIP peer notifications are never a new customer call. The API-side offer leg controls the atomic claim.
        if (($p['direction'] ?? '') === 'incoming') {
            $token = collect($p['custom_headers'] ?? [])->first(fn ($h) => strcasecmp($h['name'] ?? '', 'X-Repro-Offer') === 0)['value'] ?? null;
            $session = (string) ($p['call_session_id'] ?? '');
            $offer = VoicePhoneOffer::where('sip_device_id', $device->id)->where(function ($q) use ($token, $session, $control): void {
                $q->where('peer_call_control_id', $control);
                if ($token) {
                    $q->orWhere('client_state', $token);
                }
                if ($session !== '') {
                    $q->orWhere('call_session_id', $session);
                }
            })->first();
            if ($offer) {
                $offer->update(['peer_call_control_id' => $control]);
                if ($offer->accepted_at || ($offer->incomingOffer->status === 'waiting' && $offer->expires_at->isFuture() && $this->validOffer($offer))) {
                    return ['status' => 'processed_sip_peer', 'voice_call_id' => $offer->incomingOffer->voice_call_id];
                }
            }
        } elseif ($type === 'call.initiated' && ($p['direction'] ?? '') === 'outgoing' && $this->allowed($device)) {
            try {
                $call = app(VoiceBrowserCallService::class)->startSipOutbound($device, $p);

                return ['status' => 'processed_sip_outbound', 'voice_call_id' => $call->id];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) { /* Reject unauthorized/busy calls without dialing a customer. */
            }
        }
        if (! in_array($type, ['call.hangup', 'call.ended', 'call.failed'], true)) {
            $this->gateway->command('reject-sip:'.$control, '/calls/'.rawurlencode($control).'/actions/hangup');
        }

        return ['status' => 'rejected_sip_device', 'voice_call_id' => null];
    }
}
