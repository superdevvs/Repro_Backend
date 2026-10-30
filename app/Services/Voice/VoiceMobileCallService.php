<?php

namespace App\Services\Voice;

use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceMobileOffer;
use App\Models\VoiceMobileSession;
use App\Models\VoiceMobileTicket;
use App\Support\VoiceLocks;
use Illuminate\Support\Str;

class VoiceMobileCallService
{
    public function __construct(private VoiceMobileSessionService $sessions, private VoiceBrowserGateway $gateway, private VoiceMobileProof $proof) {}

    public function outbound(VoiceMobileSession $s, string $to, string $key): array
    {
        return VoiceLocks::lock('voice-browser-user:'.$s->user_id, 60)->block(5, function () use ($s, $to, $key) {
            $s->refresh();
            abort_unless($this->sessions->authorized($s), 403);
            $from = app(VoiceBrowserCallService::class)->mobileCallerNumber($s->user, $to);
            $ticket = VoiceMobileTicket::where('session_id', $s->id)->where('idempotency_key', strtolower($key))->first();
            if ($ticket) {
                abort_unless($ticket->destination === $to, 409, 'This request key has different call details.');
                abort_unless($ticket->expires_at->isFuture() && $ticket->state === 'pending', 409, 'This call attempt has expired or already started.');
            } else {
                abort_unless(app(VoiceIncomingOfferService::class)->eligible($s->user), 409, 'You are already handling a call or are unavailable.');
                abort_if(VoiceMobileTicket::whereHas('session', fn ($q) => $q->where('user_id', $s->user_id))->where('state', 'pending')->where('expires_at', '>', now())->exists(), 409, 'Another call is connecting.');
                $ticket = VoiceMobileTicket::create(['session_id' => $s->id, 'idempotency_key' => strtolower($key), 'destination' => $to, 'caller_number' => $from, 'expires_at' => now()->addSeconds(45)]);
            }

            return ['call_id' => $ticket->id, 'session_id' => $s->id, 'destination' => $ticket->destination, 'caller_name' => 'RePro',
                'caller_number' => $ticket->caller_number, 'expires_at' => $ticket->expires_at->toIso8601String(),
                'sdk_destination' => 'sip:mobile-'.$ticket->id.'@sip.telnyx.com', 'offer_token' => $this->proof->token('outbound', $s->id, $ticket->id), 'idempotency_key' => $ticket->idempotency_key];
        });
    }

    public function offer(VoiceIncomingOffer $incoming): void
    {
        $eligible = VoiceMobileSession::whereIn('user_id', $incoming->eligible_user_ids)->whereNull('revoked_at')->where('status', 'ready')
            ->whereNotNull('push_token_hash')->where('registration_expires_at', '>', now())->get()->filter(fn ($s) => $this->sessions->registered($s));
        foreach ($eligible as $s) {
            $offer = VoiceMobileOffer::firstOrCreate(['incoming_offer_id' => $incoming->id, 'session_id' => $s->id],
                ['expires_at' => $incoming->expires_at, 'client_state' => base64_encode((string) Str::uuid())]);
            \App\Jobs\DialVoiceMobileOffer::dispatch($offer->id)->afterCommit();
        }
    }

    public function dial(string $id): void
    {
        $o = VoiceMobileOffer::findOrFail($id);
        VoiceLocks::lock('voice-mobile-offer:'.$o->id, 60)->block(5, function () use ($o) {
            $o->refresh();
            $s = $o->session;
            $incoming = $o->incomingOffer;
            if (! $this->sessions->registered($s) || $incoming->status !== 'waiting' || $o->expires_at->isPast() || $o->state === 'cancelled') {
                $this->cancelOffer($o);

                return;
            }
            $this->sessions->prepareIncoming($s);
            $call = $incoming->voiceCall;
            VoiceLocks::lock('voice-incoming:'.$incoming->id, 60)->block(5, function () use ($o, $incoming) {
                $o->refresh();
                $incoming->refresh();
                abort_unless($incoming->status === 'waiting' && in_array($o->state, ['pending', 'dialing'], true) && $o->expires_at->isFuture(), 409);
                $o->update(['state' => 'dialing']);
            });
            $r = $this->gateway->command('mobile-offer:'.$o->id, '/calls', ['connection_id' => config('services.telnyx.voice.connection_id'),
                'to' => 'sip:'.$s->sip_username.'@sip.telnyx.com', 'from' => $call->to_phone,
                'webhook_url' => config('services.telnyx.voice.webhook_url'), 'timeout_secs' => 40,
                'client_state' => $o->client_state, 'custom_headers' => [['name' => 'X-Repro-Mobile-Offer', 'value' => $this->proof->token('incoming', $s->id, $o->id)]]], true);
            if (filled($r['call_control_id'] ?? null)) {
                $o->update(['call_control_id' => $r['call_control_id']]);
            }
            $o->refresh();
            if (($incoming->fresh()->status !== 'waiting' || $o->state === 'cancel_requested' || ! $this->sessions->authorized($s->fresh())) && $o->state !== 'accepted') {
                $this->cancelOffer($o);
            }
        });
    }

    public function claim(VoiceMobileSession $s, string $sdkID, string $token): array
    {
        abort_unless($this->sessions->authorized($s), 403);
        $id = explode('.', $token, 2)[0];
        $o = VoiceMobileOffer::where('id', $id)->where('session_id', $s->id)->firstOrFail();
        abort_unless($this->proof->verify($token, 'incoming', $s->id, $o->id), 403);

        return app(VoiceIncomingOfferService::class)->claimMobile($o, strtolower($sdkID));
    }

    public function state(VoiceMobileSession $s, string $sdkID): array
    {
        abort_unless($this->sessions->authorized($s), 403);
        $ticket = VoiceMobileTicket::where('session_id', $s->id)->whereKey(strtolower($sdkID))->first();
        $offer = VoiceMobileOffer::where('session_id', $s->id)->where('sdk_call_id', strtolower($sdkID))->first();
        $callID = $ticket?->voice_call_id ?? $offer?->incomingOffer?->voice_call_id;
        $browser = $callID ? VoiceBrowserCall::where('voice_call_id', $callID)->where('owner_id', $s->user_id)
            ->whereHas('legs', fn ($q) => $q->where('mobile_session_id', $s->id))->first() : null;

        return ['voice_call_id' => $browser?->voice_call_id, 'state' => $browser?->state ?? ($ticket?->state ?? 'connecting')];
    }

    public function end(VoiceMobileSession $s, string $sdkID, ?string $token = null): void
    {
        $proofOffer = null;
        if ($token) {
            $proofOffer = VoiceMobileOffer::where('session_id', $s->id)->whereKey(explode('.', $token, 2)[0])->firstOrFail();
            abort_unless($this->proof->verify($token, 'incoming', $s->id, $proofOffer->id), 403);
            abort_if($proofOffer->sdk_call_id && $proofOffer->sdk_call_id !== strtolower($sdkID), 409);
        }
        VoiceLocks::lock('voice-browser-user:'.$s->user_id, 60)->block(5, function () use ($s, $sdkID, $proofOffer) {
            $t = VoiceMobileTicket::where('session_id', $s->id)->whereKey(strtolower($sdkID))->first();
            if ($t) {
                $t->update(['state' => 'cancelled']);
                if ($t->voice_call_id) {
                    app(VoiceBrowserCallService::class)->endMobile($s, $t->voice_call_id);
                }
            }
            $o = $proofOffer ?? VoiceMobileOffer::where('session_id', $s->id)->where('sdk_call_id', strtolower($sdkID))->first();
            if ($o) {
                $o->refresh();
                abort_if($o->sdk_call_id && $o->sdk_call_id !== strtolower($sdkID), 409);
                if (! $o->sdk_call_id) {
                    $o->update(['sdk_call_id' => strtolower($sdkID)]);
                }
                if ($o->state === 'accepted') {
                    app(VoiceBrowserCallService::class)->endMobile($s, $o->incomingOffer->voice_call_id);
                }
                $this->cancelOffer($o, true);
            }
            // Without an invite proof, an unknown SDK UUID has no authority. Signed hangup/expiry closes that offer.
        });
    }

    public function endSession(VoiceMobileSession $s): void
    {
        VoiceMobileTicket::where('session_id', $s->id)->where('state', 'pending')->update(['state' => 'cancelled']);
        foreach (VoiceBrowserLeg::where('mobile_session_id', $s->id)->whereNull('ended_at')->get() as $leg) {
            app(VoiceBrowserCallService::class)->endMobile($s, $leg->browserCall->voice_call_id);
        }
        foreach (VoiceMobileOffer::where('session_id', $s->id)->whereNotIn('state', ['cancelled', 'ended'])->get() as $o) {
            $this->cancelOffer($o, true);
        }
    }

    public function closeLosing(VoiceIncomingOffer $incoming): void
    {
        foreach (VoiceMobileOffer::where('incoming_offer_id', $incoming->id)->whereNotIn('state', ['accepted', 'cancelled', 'ended'])->get() as $o) {
            $this->cancelOffer($o);
        }
    }

    private function cancelOffer(VoiceMobileOffer $o, bool $force = false): void
    {
        $query = VoiceMobileOffer::whereKey($o->id)->whereNotIn('state', $force ? ['cancelled', 'ended'] : ['accepted', 'cancelled', 'ended']);
        $cancel = $query->update(['state' => 'cancel_requested']);
        $o->refresh();
        if (! $cancel) {
            return;
        }
        if ($o->call_control_id) {
            $this->gateway->command('mobile-offer-end:'.$o->id, '/calls/'.rawurlencode($o->call_control_id).'/actions/hangup');
            $o->update(['state' => 'cancelled']);
        }
        // Retain cancellation intent while a carrier dial response may still be in flight.
    }

    public function reconcile(): void
    {
        foreach (VoiceMobileOffer::whereNotIn('state', ['cancelled', 'ended', 'accepted'])->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhereHas('incomingOffer', fn ($i) => $i->where('status', '!=', 'waiting')))->limit(100)->get() as $o) {
            $this->cancelOffer($o);
        }
        VoiceMobileTicket::where('state', 'pending')->where('expires_at', '<=', now())->update(['state' => 'expired']);
    }

    /** Invoked only after TelnyxVoiceWebhookController signature verification. */
    public function webhook(array $envelope): ?array
    {
        $d = $envelope['data'] ?? $envelope;
        $p = $d['payload'] ?? $d;
        $type = $d['event_type'] ?? '';
        $control = (string) ($p['call_control_id'] ?? '');
        $connection = (string) ($p['connection_id'] ?? '');
        $s = $connection === '' ? null : VoiceMobileSession::where('connection_id', $connection)->first();
        if ($s) {
            // Existing server-owned parked legs continue through the shared bridge handler.
            if (VoiceBrowserLeg::where('mobile_session_id', $s->id)->where('call_control_id', $control)->exists()) {
                return null;
            }
            $to = (string) ($p['to'] ?? '');
            if ($type === 'call.initiated' && ($p['direction'] ?? '') === 'outgoing') {
                preg_match('/^(?:sip:)?mobile-([a-f0-9-]{36})(?:@sip\.telnyx\.com)?$/i', $to, $m);
                $t = isset($m[1]) ? VoiceMobileTicket::where('session_id', $s->id)->whereKey(strtolower($m[1]))->first() : null;
                try {
                    abort_unless($t && $this->sessions->authorized($s) && $this->proof->verify($this->proof->header($p, 'X-Repro-Offer'), 'outbound', $s->id, $t->id), 403);
                    $call = app(VoiceBrowserCallService::class)->startMobileOutbound($s, $t, $p);

                    return ['status' => 'processed_mobile_outbound', 'voice_call_id' => $call->id];
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    if ($control !== '') {
                        $this->gateway->command('mobile-denied:'.$control, '/calls/'.rawurlencode($control).'/actions/hangup');
                    }

                    return ['status' => 'rejected_mobile_outbound'];
                }
            }
            // The SDK peer never establishes a claim. A valid incoming offer must still be awaiting/accepted.
            $token = $this->proof->header($p, 'X-Repro-Mobile-Offer');
            $id = explode('.', $token, 2)[0];
            $o = VoiceMobileOffer::where('session_id', $s->id)->whereKey($id)->first();
            $valid = $o && $this->sessions->authorized($s) && $this->proof->verify($token, 'incoming', $s->id, $o->id)
                && in_array($o->state, ['dialing', 'accepted'], true) && ($o->state === 'accepted' || $o->expires_at->isFuture());
            if (! $valid && $control !== '' && $type === 'call.initiated') {
                $this->gateway->command('mobile-denied:'.$control, '/calls/'.rawurlencode($control).'/actions/hangup');
            }

            return ['status' => $valid ? 'processed_mobile_peer' : 'ignored_mobile_peer'];
        }
        // API-originated incoming offer leg. Do not correlate arbitrary client_state from another connection.
        if ($connection !== (string) config('services.telnyx.voice.connection_id')) {
            return null;
        }
        $o = $control === '' ? null : VoiceMobileOffer::where('call_control_id', $control)->first();
        if (! $o && ! empty($p['client_state'])) {
            $o = VoiceMobileOffer::where('client_state', $p['client_state'])->first();
        }
        if (! $o) {
            return null;
        }
        if ($o->call_control_id && $o->call_control_id !== $control) {
            return ['status' => 'ignored_mobile_offer_identity'];
        }
        if (! $o->call_control_id && $control !== '') {
            $o->update(['call_control_id' => $control]);
        }
        if ($o->state === 'accepted') {
            return null;
        } // The shared human bridge now owns this exact control ID.
        if (in_array($type, ['call.hangup', 'call.ended', 'call.failed'], true)) {
            $o->update(['state' => 'ended']);
        }
        if ($type === 'call.answered') {
            $this->cancelOffer($o);
        } // No media before the atomic claim.

        return ['status' => 'processed_mobile_offer', 'voice_call_id' => $o->incomingOffer->voice_call_id];
    }
}
