<?php

namespace App\Services\Voice;

use App\Jobs\CloseVoiceOfferDevices;
use App\Jobs\DialVoiceStaffPhone;
use App\Jobs\ReconcileVoiceIncomingOffer;
use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoicePhoneOffer;
use App\Models\VoicePushSubscription;
use App\Models\VoiceStaffPhone;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Support\LockedWrite;
use App\Support\VoiceLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Durable team offers. Provider media is connected only after one atomic winner. */
class VoiceIncomingOfferService
{
    public function __construct(private VoiceBrowserSessionService $sessions, private VoiceBrowserGateway $gateway, private VoiceStaffPhoneService $phones) {}

    public function create(VoiceCall $call): bool
    {
        if (! config('services.telnyx.voice.browser_enabled') || ! $this->live($call) || ! $call->call_control_id) {
            return false;
        }
        $existing = VoiceIncomingOffer::where('voice_call_id', $call->id)->first();
        if ($existing) {
            return in_array($existing->status, ['waiting', 'claimed'], true);
        }
        // Start from reachable channels, not the entire client/account directory.
        // Permission checks still use each actual user, including custom grants.
        $candidates = VoiceBrowserSession::whereNull('revoked_at')->where('status', 'ready')->where('registered', true)
            ->where('heartbeat_at', '>=', now()->subSeconds(60))->where('expires_at', '>', now())->pluck('user_id')
            ->merge(VoiceStaffPhone::where('available', true)->where('phone_enabled', true)->whereNotNull('verified_at')->whereNotNull('phone')->pluck('user_id'));
        $push = app(VoicePushService::class);
        if ($push->configuration()['configured']) {
            $candidates = $candidates->merge(VoicePushSubscription::whereNull('revoked_at')->pluck('user_id'));
        }
        $eligible = User::whereIn('id', $candidates->unique()->all())->get()
            ->filter(fn (User $user) => $this->eligible($user) && $this->reachable($user, $call))->pluck('id')->all();
        if ($eligible === []) {
            return false;
        }
        $offer = LockedWrite::run(fn () => VoiceIncomingOffer::firstOrCreate(['voice_call_id' => $call->id], [
            'eligible_user_ids' => $eligible, 'expires_at' => now()->addSeconds(45),
        ]));
        $call->update(['status' => 'ringing', 'metadata' => array_merge($call->metadata ?? [], ['incoming_offer_id' => $offer->id])]);
        $this->notify($offer);
        if (config('voice_calls.realtime_connection') !== 'sync') {
            ReconcileVoiceIncomingOffer::dispatch($offer->id)->delay(now()->addSeconds(8))->afterCommit();
            ReconcileVoiceIncomingOffer::dispatch($offer->id)->delay($offer->expires_at)->afterCommit();
        }

        return true;
    }

    public function eligible(User $user, ?int $exceptCall = null): bool
    {
        if (! $this->sessions->canOperate($user) || ! ($this->phones->state($user)['available'])) {
            return false;
        }

        return ! VoiceBrowserCall::where('owner_id', $user->id)->whereNotIn('state', ['failed', 'ended'])
            ->when($exceptCall, fn ($q) => $q->where('voice_call_id', '!=', $exceptCall))->exists()
            && ! VoiceBrowserLeg::where('user_id', $user->id)->whereNull('ended_at')
                ->whereHas('browserCall', fn ($q) => $q->whereNotIn('state', ['failed', 'ended'])->when($exceptCall, fn ($q) => $q->where('voice_call_id', '!=', $exceptCall)))->exists();
    }

    public function reachable(User $user, VoiceCall $call): bool
    {
        if ($this->phones->destination($user, $call)) {
            return true;
        }
        if (VoiceBrowserSession::where('user_id', $user->id)->whereNull('revoked_at')->where('registered', true)
            ->where('heartbeat_at', '>=', now()->subSeconds(60))->where('expires_at', '>', now())->get()
            ->contains(fn ($session) => $this->sessions->usable($session))) {
            return true;
        }
        $push = app(VoicePushService::class);

        return $push->configuration()['configured'] && $push->enabledFor($user->id)
            && VoicePushSubscription::where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }

    public function listing(User $user): array
    {
        abort_unless($this->sessions->canOperate($user), 403);

        return ['data' => VoiceIncomingOffer::whereJsonContains('eligible_user_ids', $user->id)
            ->where('created_at', '>=', now()->subMinutes(5))->with(['voiceCall.callerUser', 'voiceCall.callerContact', 'claimedBy'])
            ->latest()->limit(50)->get()->map(fn ($offer) => $this->present($offer, $user))->all()];
    }

    public function present(VoiceIncomingOffer $offer, User $user): array
    {
        $call = $offer->voiceCall;
        $status = $offer->status === 'waiting' && ($offer->expires_at->isPast() || ! $this->live($call)) ? 'expired' : $offer->status;

        return ['id' => $offer->id, 'voice_call_id' => $call->id, 'status' => $status,
            'expires_at' => $offer->expires_at->toIso8601String(), 'caller_name' => $call->callerUser?->name ?? $call->callerContact?->name,
            'remote_phone' => $call->from_phone, 'claimed_by' => $offer->claimedBy ? ['id' => $offer->claimedBy->id, 'name' => $offer->claimedBy->name] : null,
            'can_claim' => $status === 'waiting' && in_array($user->id, $offer->eligible_user_ids, true) && $this->eligible($user),
            'phone_available' => $this->phones->destination($user, $call) !== null];
    }

    public function claim(VoiceIncomingOffer $offer, User $user, array $data): array
    {
        abort_unless($this->sessions->canOperate($user) && in_array($user->id, $offer->eligible_user_ids, true), 403);
        $this->assertNotCancelled($offer, $user, $data['idempotency_key']);
        if (($data['device'] ?? 'browser') === 'phone') {
            $phone = VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(5, function () use ($offer, $user, $data) {
                $this->assertNotCancelled($offer, $user, $data['idempotency_key']);
                $offer->refresh();
                $this->assertClaimable($offer, $user);

                return $this->preparePhone($offer, $user);
            });
            // This one explicit dial runs outside the shared offer lock; another admin
            // can claim immediately even when this carrier request is slow.
            $this->dialPhone($phone->id);
            $offer->refresh();
            abort_if($offer->status !== 'waiting' && $offer->claimed_by_id !== $user->id, 409, 'This call was answered elsewhere or is no longer available.');

            return ['voice_call_id' => $offer->voice_call_id, 'incoming_offer' => $this->present($offer, $user), 'phone_pending' => $offer->status === 'waiting'];
        }
        $session = VoiceBrowserSession::findOrFail($data['session_id']);
        $browser = VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(5, fn () => VoiceLocks::lock('voice-browser-user:'.$user->id, 60)->block(5,
            fn () => VoiceLocks::lock('voice-browser-device:'.$session->id, 60)->block(5, function () use ($offer, $user, $data, $session) {
                $offer->refresh();
                $this->assertNotCancelled($offer, $user, $data['idempotency_key']);
                $session->refresh();
                abort_unless($session->user_id === $user->id, 403);
                abort_unless($this->sessions->configuration($user)['ready'] && $this->sessions->usable($session), 409, 'Connect this browser before answering.');
                if ($offer->status === 'claimed' && $offer->claimed_by_id === $user->id && $offer->claimed_session_id === $session->id && $offer->claim_key === $data['idempotency_key']) {
                    return VoiceBrowserCall::findOrFail($offer->browser_call_id);
                }
                $this->assertClaimable($offer, $user);
                $browser = $this->commitClaim($offer, $user, $session, $data['idempotency_key']);
                $this->closeDevices($offer, 'claimed', true);

                return $browser;
            })));
        app(VoiceBrowserCallService::class)->resumeConnection($browser);

        return array_merge(app(VoiceBrowserCallService::class)->state($offer->voiceCall->fresh(), $user), ['incoming_offer' => $this->present($offer->fresh(), $user)]);
    }

    public function cancel(VoiceIncomingOffer $offer, User $user, string $key): array
    {
        abort_unless($this->sessions->canOperate($user) && in_array($user->id, $offer->eligible_user_ids, true), 403);
        LockedWrite::run(fn () => DB::table('voice_cancelled_attempts')->insertOrIgnore(['user_id' => $user->id,
            'idempotency_key' => $this->cancelKey($offer, $key), 'created_at' => now(), 'updated_at' => now()]));
        $phoneToCancel = null;
        VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(5, function () use ($offer, $user, $key, &$phoneToCancel): void {
            $offer->refresh();
            if ($offer->status === 'claimed' && $offer->claimed_by_id === $user->id && $offer->claim_key === $key) {
                app(VoiceBrowserCallService::class)->withdrawIncoming(VoiceBrowserCall::findOrFail($offer->browser_call_id), $user);
                $offer->refresh()->update(['status' => 'cancelled', 'metadata' => array_merge($offer->metadata ?? [], ['fallback_completed_at' => now()->toIso8601String()])]);
                $this->closeDevices($offer, 'cancelled', true);
            } elseif ($offer->status === 'waiting') {
                $phone = $offer->phoneOffers()->where('user_id', $user->id)->first();
                if ($phone) {
                    $phone->update(['state' => 'cancel_requested']);
                    $phoneToCancel = $phone;
                }
            }
        });
        if ($phoneToCancel) {
            CloseVoiceOfferDevices::dispatch($offer->id)->afterCommit();
            $this->cancelPhone($phoneToCancel);
        }

        return ['status' => 'cancelled', 'incoming_offer' => $this->present($offer->fresh(), $user)];
    }

    private function cancelKey(VoiceIncomingOffer $offer, string $key): string
    {
        return 'incoming:'.hash('sha256', $offer->id.'|'.$key);
    }

    private function assertNotCancelled(VoiceIncomingOffer $offer, User $user, string $key): void
    {
        abort_if(DB::table('voice_cancelled_attempts')->where('user_id', $user->id)->where('idempotency_key', $this->cancelKey($offer, $key))->exists(), 409, 'This answer attempt was cancelled.');
    }

    private function assertClaimable(VoiceIncomingOffer $offer, User $user): void
    {
        abort_unless($this->sessions->canOperate($user) && in_array($user->id, $offer->eligible_user_ids, true), 403);
        abort_unless($offer->status === 'waiting' && $offer->expires_at->isFuture() && $this->live($offer->voiceCall->fresh()), 409, 'This call was answered elsewhere or is no longer available.');
        abort_unless($this->eligible($user), 409, 'You are unavailable or already handling a call.');
    }

    private function commitClaim(VoiceIncomingOffer $offer, User $user, ?VoiceBrowserSession $session, string $key, ?VoicePhoneOffer $phone = null): VoiceBrowserCall
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($offer, $user, $session, $key, $phone) {
            $updated = VoiceIncomingOffer::whereKey($offer->id)->where('status', 'waiting')->where('expires_at', '>', now())
                ->update(['status' => 'claimed', 'claimed_by_id' => $user->id, 'claimed_session_id' => $session?->id, 'claim_key' => $key, 'claimed_at' => now()]);
            abort_unless($updated === 1, 409, 'This call was answered elsewhere.');
            $browser = app(VoiceBrowserCallService::class)->createClaimedIncoming($offer->voiceCall->fresh(), $user, $session, $offer->id, $phone);
            $offer->refresh()->update(['browser_call_id' => $browser->id]);
            if ($phone) {
                $phone->update(['state' => 'accepted', 'accepted_at' => now()]);
            }

            return $browser;
        }), 'voice.incoming.claim');
    }

    private function preparePhone(VoiceIncomingOffer $offer, User $user): VoicePhoneOffer
    {
        $destination = $this->phones->destination($user, $offer->voiceCall);
        abort_unless($destination, 422, 'Set up and verify your staff phone first.');

        return VoicePhoneOffer::firstOrCreate(['incoming_offer_id' => $offer->id, 'user_id' => $user->id], [
            'destination' => $destination, 'expires_at' => $offer->expires_at, 'client_state' => base64_encode((string) Str::uuid()),
        ])->fresh();
    }

    public function dialPhone(string $id): void
    {
        VoiceLocks::lock('voice-phone-dial:'.$id, 40)->block(3, function () use ($id): void {
            $phone = VoicePhoneOffer::find($id);
            if (! $phone || $phone->call_control_id || ! in_array($phone->state, ['pending', 'dialing'], true)) {
                return;
            }
            $offer = $phone->incomingOffer;
            if ($offer->status !== 'waiting' || $offer->expires_at->isPast() || ! $this->live($offer->voiceCall)
                || ! $this->eligible($phone->user) || $this->phones->destination($phone->user, $offer->voiceCall) !== $phone->destination) {
                $phone->update(['state' => 'cancelled']);

                return;
            }
            $phone->update(['state' => 'dialing']);
            $result = $this->gateway->command('phone-offer:'.$phone->id, '/calls', [
                'connection_id' => (string) config('services.telnyx.voice.connection_id'), 'to' => $phone->destination,
                'from' => $offer->voiceCall->to_phone, 'webhook_url' => (string) config('services.telnyx.voice.webhook_url'),
                'timeout_secs' => 25, 'client_state' => $phone->client_state,
            ]);
            if (! empty($result['call_control_id'])) {
                $phone->update(['call_control_id' => $result['call_control_id']]);
            }
            // Cancellation can arrive before the carrier returns its new call ID.
            $phone->refresh();
            if (! $phone->accepted_at && (in_array($phone->state, ['cancel_requested', 'cancelled'], true)
                || $offer->fresh()->status !== 'waiting' || $offer->expires_at->isPast() || ! $this->live($offer->voiceCall->fresh()))) {
                $this->cancelPhone($phone);
            }
        });
    }

    /** Called only by the signed call webhook path; phone answer alone is not consent to join. */
    public function handlePhoneWebhook(array $envelope): ?array
    {
        $data = $envelope['data'] ?? $envelope;
        $payload = $data['payload'] ?? $data;
        $control = (string) ($payload['call_control_id'] ?? '');
        $type = (string) ($data['event_type'] ?? '');
        $phone = VoicePhoneOffer::where(function ($q) use ($control, $payload): void {
            $q->where('call_control_id', $control !== '' ? $control : '__none__');
            if (! empty($payload['client_state'])) {
                $q->orWhere('client_state', $payload['client_state']);
            }
        })->first();
        if (! $phone || $phone->accepted_at) {
            return null;
        }
        $next = null;
        $browser = null;
        $result = VoiceLocks::lock('voice-incoming:'.$phone->incoming_offer_id, 60)->block(5, function () use ($phone, $payload, $control, $type, &$next, &$browser) {
            $phone->refresh();
            if ($phone->accepted_at) {
                return null;
            }
            $offer = $phone->incomingOffer;
            if ($phone->call_control_id && $phone->call_control_id !== $control) {
                return ['status' => 'rejected_phone_offer', 'voice_call_id' => null];
            }
            if (! $phone->call_control_id && $control !== '') {
                abort_unless(($payload['connection_id'] ?? '') === (string) config('services.telnyx.voice.connection_id'), 403);
                $phone->update(['call_control_id' => $control]);
            }
            if (in_array($type, ['call.hangup', 'call.ended', 'call.failed', 'call.no_answer'], true)) {
                $phone->update(['state' => 'ended']);
            } elseif (in_array($phone->state, ['ended', 'cancelled', 'cancel_requested'], true) || $offer->status !== 'waiting' || $offer->expires_at->isPast() || ! $this->live($offer->voiceCall) || ! $this->eligible($phone->user)
                || $this->phones->destination($phone->user, $offer->voiceCall) !== $phone->destination) {
                $next = 'hangup';
            } elseif ($type === 'call.answered') {
                $phone->update(['state' => 'awaiting_acceptance']);
                $next = 'prompt';
            } elseif ($type === 'call.gather.ended' && $phone->state === 'awaiting_acceptance') {
                if (($payload['digits'] ?? '') !== '1') {
                    $phone->update(['state' => 'cancel_requested']);
                    $next = 'hangup';
                } else {
                    $browser = VoiceLocks::lock('voice-browser-user:'.$phone->user_id, 60)->block(5, function () use ($offer, $phone) {
                        $this->assertClaimable($offer, $phone->user);

                        return $this->commitClaim($offer, $phone->user, null, 'phone:'.$phone->id, $phone);
                    });
                    $this->closeDevices($offer, 'claimed', true);
                }
            }

            return ['status' => 'processed', 'voice_call_id' => $offer->voice_call_id];
        });
        // Carrier I/O never holds the shared offer lock, including prompt playback.
        if ($browser) {
            app(VoiceBrowserCallService::class)->resumeConnection($browser);
        } elseif ($next === 'hangup') {
            CloseVoiceOfferDevices::dispatch($phone->incoming_offer_id)->afterCommit();
            $this->cancelPhone($phone->fresh());
        } elseif ($next === 'prompt') {
            $phone->refresh();
            if ($phone->incomingOffer->status !== 'waiting') {
                $this->cancelPhone($phone);
            } else {
                $this->gateway->command('phone-accept-prompt:'.$phone->id, '/calls/'.rawurlencode($control).'/actions/gather_using_speak', [
                    'payload' => 'REPro team call. Press 1 to answer this caller. Otherwise hang up.', 'voice' => 'female', 'language' => 'en-US',
                    'valid_digits' => '1', 'minimum_digits' => 1, 'maximum_digits' => 1, 'maximum_tries' => 1, 'timeout_millis' => 10000,
                    'client_state' => $phone->client_state,
                ]);
            }
        }

        return $result;
    }

    public function reconcile(?string $id = null): void
    {
        $offers = VoiceIncomingOffer::when($id, fn ($q) => $q->whereKey($id))
            ->where(function ($q): void {
                $q->where('status', 'waiting')->orWhereNull('metadata->push_closed_at')
                    ->orWhere(fn ($expired) => $expired->where('status', 'expired')->whereNull('metadata->fallback_completed_at'))
                    ->orWhereHas('phoneOffers', fn ($phones) => $phones->whereNotIn('state', ['accepted', 'cancelled', 'ended']));
            })->where('created_at', '>=', now()->subDay())->oldest()->limit(100)->get();
        foreach ($offers as $offer) {
            $phonesToDial = [];
            VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(3, function () use ($offer, &$phonesToDial): void {
                $offer->refresh();
                $call = $offer->voiceCall->fresh();
                if ($offer->status === 'claimed') {
                    $this->closeDevices($offer, 'claimed');

                    return;
                }
                if ($offer->status === 'cancelled') {
                    $this->closeDevices($offer, 'cancelled');

                    return;
                }
                if (! $this->live($call)) {
                    $offer->update(['status' => 'cancelled']);
                    $this->closeDevices($offer, 'ended');

                    return;
                }
                if ($offer->expires_at->isPast() || $offer->status === 'expired') {
                    $offer->update(['status' => 'expired']);
                    $this->closeDevices($offer, 'expired', true);
                    if (empty($offer->metadata['fallback_completed_at'])) {
                        app(VoiceRoutingService::class)->resumeAfterTeamOffer($call);
                        $offer->update(['metadata' => array_merge($offer->metadata ?? [], ['fallback_completed_at' => now()->toIso8601String()])]);
                    }
                } elseif ($offer->status === 'waiting' && $offer->created_at->lte(now()->subSeconds(8))) {
                    foreach (User::whereIn('id', $offer->eligible_user_ids)->get() as $user) {
                        if ($this->eligible($user) && $this->phones->destination($user, $call)) {
                            $phonesToDial[] = $this->preparePhone($offer, $user)->id;
                        }
                    }
                }
            });
            foreach ($phonesToDial as $phoneId) {
                DialVoiceStaffPhone::dispatch($phoneId)->afterCommit();
            }
            if ($offer->phoneOffers()->where('state', 'cancel_requested')->exists()) {
                CloseVoiceOfferDevices::dispatch($offer->id)->afterCommit();
            }
        }
    }

    public function cancelForCall(VoiceCall $call): void
    {
        $offer = VoiceIncomingOffer::where('voice_call_id', $call->id)->first();
        if (! $offer || $offer->status !== 'waiting') {
            return;
        }
        VoiceLocks::lock('voice-incoming:'.$offer->id, 60)->block(3, function () use ($offer): void {
            if ($offer->fresh()->status !== 'waiting') {
                return;
            }
            $offer->update(['status' => 'cancelled']);
            $this->closeDevices($offer, 'ended', true);
        });
    }

    private function cancelPhone(VoicePhoneOffer $phone): void
    {
        if (in_array($phone->state, ['ended', 'accepted'], true)) {
            return;
        }
        if ($phone->call_control_id) {
            $this->gateway->command('cancel-phone:'.$phone->id, '/calls/'.rawurlencode($phone->call_control_id).'/actions/hangup');
        }
        $phone->update(['state' => 'cancelled']);
    }

    public function closeLosingPhones(VoiceIncomingOffer $offer): void
    {
        foreach ($offer->phoneOffers()->whereNotIn('state', ['accepted', 'ended', 'cancelled'])
            ->when($offer->status === 'waiting', fn ($query) => $query->where('state', 'cancel_requested'))->get() as $phone) {
            $this->cancelPhone($phone);
        }
    }

    private function closeDevices(VoiceIncomingOffer $offer, string $reason, bool $deferPhoneHangups = false): void
    {
        if ($deferPhoneHangups) {
            // Never make a winning caller wait behind multiple carrier hangup requests.
            // The periodic reconciler is also a durable fallback if enqueueing fails.
            if (config('voice_calls.realtime_connection') !== 'sync') {
                try {
                    CloseVoiceOfferDevices::dispatch($offer->id)->afterCommit();
                } catch (\Throwable) {
                    Log::error('Losing phone cleanup enqueue failed.', ['offer_id' => $offer->id]);
                }
            }
        } else {
            try {
                $this->closeLosingPhones($offer);
            } catch (\Throwable) {
                Log::error('Losing phone cancellation pending.', ['offer_id' => $offer->id]);
            }
        }
        if (! empty($offer->metadata['push_closed_at'])) {
            return;
        }
        try {
            app(VoicePushService::class)->closeOffer($offer->id, $reason);
            $offer->refresh()->update(['metadata' => array_merge($offer->metadata ?? [], ['push_closed_at' => now()->toIso8601String()])]);
        } catch (\Throwable $e) {
            Log::error('Call alert cancellation pending.', ['offer_id' => $offer->id]);
        }
    }

    private function notify(VoiceIncomingOffer $offer): void
    {
        try {
            app(VoicePushService::class)->notifyIncoming($offer->voiceCall, $offer->id, $offer->eligible_user_ids, $offer->expires_at);
        } catch (\Throwable $e) {
            Log::error('Call alert dispatch failed; shared queue remains available.', ['offer_id' => $offer->id]);
        }
    }

    private function live(VoiceCall $call): bool
    {
        return ! $call->ended_at && ! in_array($call->status, ['completed', 'failed', 'missed', 'cancelled', 'exhausted', 'transferred'], true);
    }
}
