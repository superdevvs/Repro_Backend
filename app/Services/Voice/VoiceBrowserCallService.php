<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceCallTranscript;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class VoiceBrowserCallService
{
    private const TERMINAL = ['failed', 'ended'];

    public function __construct(
        private readonly VoiceBrowserGateway $gateway,
        private readonly VoiceBrowserSessionService $sessions,
        private readonly VoiceNumberSettingsResolver $numbers,
        private readonly VoiceSettingsService $settings,
        private readonly TelnyxVoiceCallService $calls,
    ) {}

    public function state(VoiceCall $call, User $user): array
    {
        $browser = $this->active($call) ?? VoiceBrowserCall::query()->where('voice_call_id', $call->id)->latest()->first();
        $own = $browser?->legs()->where('user_id', $user->id)->whereNull('ended_at')->latest()->first();
        $agent = $browser?->legs()->where('role', 'agent')->whereNotNull('joined_at')->whereNull('ended_at')->first();
        $active = $browser && $browser->state === 'active' && $agent;
        $config = $this->sessions->configuration($user);
        $operate = $config['ready'] && $this->sessions->canOperate($user);
        $supervise = $config['ready'] && $this->sessions->canSupervise($user);
        $owner = $browser && $browser->owner_id === $user->id;

        return [
            'voice_call_id' => $call->id, 'state' => $browser?->state ?? ($call->ended_at ? 'ended' : 'ai_active'),
            'session_id' => $own?->session_id, 'agent_call_control_id' => $own?->call_control_id,
            'browser_call_control_id' => $own?->browser_call_control_id,
            'conference_id' => $browser?->conference_id, 'role' => $own?->role, 'mode' => $own?->mode,
            'muted' => $own?->muted ?? false,
            'held' => $browser?->legs()->where('role', 'customer')->value('held') ?? false,
            'error' => $browser?->metadata['error'] ?? null,
            'recording' => ['consent_given' => $call->recording_consent_given,
                'active' => ! empty($call->metadata['recording_started_at']),
                'stop_pending' => (bool) ($call->metadata['recording_stop_pending'] ?? false),
                'transcription_active' => (bool) ($call->metadata['browser_transcription_enabled'] ?? false),
                'transcription_pending' => (bool) ($call->metadata['browser_transcription_pending'] ?? false)],
            'capabilities' => [
                'can_takeover' => $operate && $this->callIsLive($call) && $call->status !== 'human_handoff' && filled($call->call_control_id) && ! $this->active($call),
                'can_monitor' => (bool) ($supervise && $active),
                'can_whisper' => (bool) ($supervise && $active),
                'can_barge' => (bool) ($supervise && $active),
                'can_control' => (bool) ($operate && $owner && $active && $own?->role === 'agent'),
                'can_end' => (bool) ($this->sessions->canOperate($user) && $owner && ! in_array($browser?->state, self::TERMINAL, true)),
                'can_transfer' => (bool) ($operate && $owner && $active),
                'can_record' => (bool) ($operate && $owner && $active && $this->calls->recordingEnabled()),
            ],
            'supervision_unavailable_reason' => $active ? null : 'Monitor and coach are available after a staff member joins a human call. Listening to Robbie-only calls is not available in this workspace yet.',
        ];
    }

    public function startOutbound(User $user, VoiceBrowserSession $session, array $data): VoiceCall
    {
        $this->assertSession($session, $user);
        $to = $this->numbers->normalize($data['to']);
        $from = $this->sourceNumber($data['from'] ?? null);
        abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $to) && $to !== $from, 422, 'Enter a valid customer phone number.');
        $this->assertOutboundAllowed($to);
        $hash = $this->hash($data);

        return Cache::lock('voice-browser-device:'.$session->id, 60)->block(5, function () use ($user, $session, $data, $to, $from, $hash): VoiceCall {
            $existing = $this->replay($user, $data['idempotency_key'], $hash);
            if ($existing) {
                $this->kickoff($existing);

                return $existing->voiceCall->fresh();
            }
            $this->assertFree($session);
            $browser = LockedWrite::run(fn () => DB::transaction(function () use ($user, $session, $data, $to, $from, $hash): VoiceBrowserCall {
                $resolved = $this->calls->resolveCaller($to);
                $call = VoiceCall::query()->create([
                    'provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'dialing', 'handled_by' => 'human',
                    'from_phone' => $from, 'to_phone' => $to, 'created_by_user_id' => $user->id,
                    'caller_user_id' => $resolved['user']?->id, 'caller_contact_id' => $data['contact_id'] ?? $resolved['contact']?->id,
                    'related_shoot_id' => $data['related_shoot_id'] ?? null,
                    'metadata' => ['source' => 'browser_staff', 'reason' => $data['reason'] ?? null],
                ]);
                $browser = $this->createBrowser($call, $session, 'human_outbound', $data['idempotency_key'], $hash);
                $this->newLeg($browser, 'agent', $this->sip($session), $session);
                $this->newLeg($browser, 'customer', $to);

                return $browser;
            }));
            $this->kickoff($browser);

            return $browser->voiceCall->fresh();
        });
    }

    public function takeover(VoiceCall $call, User $user, VoiceBrowserSession $session, array $data): VoiceBrowserCall
    {
        $this->assertSession($session, $user);
        abort_if(! $this->callIsLive($call) || $call->status === 'human_handoff' || ! $call->call_control_id, 409, 'This call is no longer available for takeover.');
        $hash = $this->hash(array_merge($data, ['voice_call_id' => $call->id]));

        return Cache::lock('voice-browser-device:'.$session->id, 60)->block(5, fn () => Cache::lock('voice-browser-import:'.$call->id, 60)->block(5, function () use ($call, $user, $session, $data, $hash): VoiceBrowserCall {
            $existing = $this->replay($user, $data['idempotency_key'], $hash);
            if ($existing) {
                $this->kickoff($existing);

                return $existing->fresh();
            }
            abort_if($this->active($call), 409, 'A staff connection is already in progress.');
            $this->assertFree($session);
            $browser = LockedWrite::run(fn () => DB::transaction(function () use ($call, $session, $data, $hash): VoiceBrowserCall {
                $browser = $this->createBrowser($call, $session, 'takeover', $data['idempotency_key'], $hash);
                $this->newLeg($browser, 'agent', $this->sip($session), $session);
                $customer = $this->newLeg($browser, 'customer', $this->remote($call));
                $customer->update(['call_control_id' => $call->call_control_id, 'state' => 'answered', 'answered_at' => $call->answered_at]);

                return $browser;
            }));
            $this->kickoff($browser);

            return $browser->fresh();
        }));
    }

    /** First eligible available device; provider answer/timeout decides the offer. */
    public function offerInbound(VoiceCall $call): bool
    {
        return Cache::lock('voice-browser-import:'.$call->id, 60)->block(5, fn () => $this->offerInboundLocked($call));
    }

    private function offerInboundLocked(VoiceCall $call): bool
    {
        if (! config('services.telnyx.voice.browser_enabled') || ! $this->callIsLive($call) || ! $call->call_control_id) {
            return false;
        }
        if ($this->active($call)) {
            return true;
        }
        $candidates = VoiceBrowserSession::query()->where('status', 'ready')->where('registered', true)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->where('heartbeat_at', '>=', now()->subSeconds(60))->with('user')->orderBy('heartbeat_at', 'desc')->get();
        foreach ($candidates as $session) {
            if (! $this->sessions->canOperate($session->user)
                || ! app(\App\Services\RolePermissionService::class)->userCan($session->user, 'voice-calls', 'view')) {
                continue;
            }
            $offered = Cache::lock('voice-browser-device:'.$session->id, 60)->block(5, function () use ($call, $session): bool {
                if ($this->active($call)) {
                    return true;
                }
                if ($this->busy($session)) {
                    return false;
                }
                $browser = LockedWrite::run(fn () => DB::transaction(function () use ($call, $session): VoiceBrowserCall {
                    $browser = $this->createBrowser($call, $session, 'human_inbound', 'inbound:'.$call->id, $this->hash(['call' => $call->id]));
                    $this->newLeg($browser, 'agent', $this->sip($session), $session);
                    $customer = $this->newLeg($browser, 'customer', $this->remote($call));
                    $customer->update(['call_control_id' => $call->call_control_id]);

                    return $browser;
                }));
                $this->kickoff($browser);

                return true;
            });
            if ($offered) {
                return true;
            }
        }

        return false;
    }

    public function supervise(VoiceCall $call, User $user, VoiceBrowserSession $session, string $mode, string $key): void
    {
        $this->assertSession($session, $user, true);
        $browser = $this->active($call);
        abort_unless($browser && $browser->state === 'active' && $browser->conference_id, 409, 'A human conference must be connected before monitoring.');
        Cache::lock('voice-browser-device:'.$session->id, 60)->block(5, function () use ($browser, $session, $user, $mode, $key): void {
            $replay = $browser->legs()->where('user_id', $user->id)->where('operation_key', $key)->first();
            if ($replay) {
                abort_if($replay->mode !== $mode || $replay->session_id !== $session->id, 409, 'This request key was used for different supervision details.');
                if (! $replay->ended_at && ! $replay->call_control_id) {
                    $this->dialLeg($replay);
                }

                return;
            }
            $own = $browser->legs()->where('role', 'supervisor')->where('user_id', $user->id)->whereNull('ended_at')->first();
            if ($own) {
                return;
            }
            $this->assertFree($session);
            $leg = LockedWrite::run(fn () => $this->newLeg($browser, 'supervisor', $this->sip($session), $session, $mode));
            $leg->update(['operation_key' => $key]);
            $this->dialLeg($leg);
        });
    }

    public function changeSupervision(VoiceCall $call, User $user, string $mode): void
    {
        abort_unless($this->sessions->canSupervise($user), 403);
        $browser = $this->active($call);
        $leg = $browser?->legs()->where('role', 'supervisor')->where('user_id', $user->id)->whereNotNull('joined_at')->whereNull('ended_at')->first();
        abort_unless($leg && $browser->conference_id, 409, 'The supervisor is not connected.');
        $agent = $this->joinedAgent($browser);
        abort_unless($agent, 409, 'The staff participant is no longer connected.');
        $payload = ['call_control_id' => $leg->call_control_id, 'supervisor_role' => $mode,
            'whisper_call_control_ids' => $mode === 'whisper' ? [$agent->call_control_id] : []];
        $revision = (string) Str::uuid();
        $this->gateway->command('supervisor-mode:'.$leg->id.':'.$revision, '/conferences/'.rawurlencode($browser->conference_id).'/actions/update', $payload);
        $this->gateway->command('supervisor-mute:'.$leg->id.':'.$revision, '/conferences/'.rawurlencode($browser->conference_id).'/actions/'.($mode === 'monitor' ? 'mute' : 'unmute'), ['call_control_ids' => [$leg->call_control_id]]);
        $leg->update(['mode' => $mode, 'muted' => $mode === 'monitor']);
    }

    public function leaveSupervision(VoiceCall $call, User $user): void
    {
        abort_unless($this->sessions->canSupervise($user), 403);
        $browser = $this->active($call);
        $leg = $browser?->legs()->where('role', 'supervisor')->where('user_id', $user->id)->whereNull('ended_at')->first();
        if ($leg) {
            $this->hangupLeg($leg);
        }
    }

    public function action(VoiceCall $call, User $user, array $data): void
    {
        $browser = $this->active($call);
        abort_unless($browser, 409, 'The browser call is no longer connected.');
        Cache::lock('voice-browser-call:'.$browser->id, 90)->block(5, fn () => $this->actionLocked($call->fresh(), $user, $data));
    }

    private function actionLocked(VoiceCall $call, User $user, array $data): void
    {
        $browser = $this->active($call);
        abort_unless($browser && $browser->owner_id === $user->id && $this->sessions->canOperate($user), 403);
        $action = $data['action'];
        if ($action === 'end') {
            $this->end($browser);

            return;
        }
        if ($action === 'recording_stop') {
            $this->calls->stopRecording($call, $data['idempotency_key'] ?? null);

            return;
        }
        $agent = $this->joinedAgent($browser);
        $customer = $browser->legs()->where('role', 'customer')->whereNotNull('joined_at')->whereNull('ended_at')->first();
        abort_unless($browser->state === 'active' && $agent && $customer, 409, 'The call is not connected.');
        $key = 'control:'.$browser->id.':'.($data['idempotency_key'] ?? (string) Str::uuid());
        $base = '/conferences/'.rawurlencode($browser->conference_id).'/actions/';
        if (in_array($action, ['mute', 'unmute'], true)) {
            $this->gateway->command($key, $base.$action, ['call_control_ids' => [$agent->call_control_id]]);
            $agent->update(['muted' => $action === 'mute']);
        } elseif (in_array($action, ['hold', 'resume'], true)) {
            $this->gateway->command($key, $base.($action === 'hold' ? 'hold' : 'unhold'), ['call_control_ids' => [$customer->call_control_id]]);
            $customer->update(['held' => $action === 'hold']);
        } elseif ($action === 'dtmf') {
            $this->gateway->command($key, '/calls/'.rawurlencode($customer->call_control_id).'/actions/send_dtmf', ['digits' => $data['digits']]);
        } elseif ($action === 'transfer') {
            $to = $this->numbers->normalize($data['to'] ?? '');
            abort_unless(preg_match('/^\+[1-9]\d{7,14}$/', $to) && $to !== $this->local($call), 422, 'Enter a valid transfer destination.');
            $this->gateway->command($key, '/calls/'.rawurlencode($customer->call_control_id).'/actions/transfer', ['to' => $to]);
            $browser->update(['state' => 'transferring']);
        } elseif (in_array($action, ['recording_start', 'recording_stop'], true)) {
            if ($action === 'recording_start') {
                abort_unless($call->recording_consent_given, 422, 'Record the caller’s consent before recording or transcription.');
                $recording = $this->calls->setRecordingConsent($call, true, $key);
                abort_unless($recording['recording'], 422, 'Recording could not be started. Check recording settings.');
            } else {
                $this->calls->stopRecording($call, $key);

                return;
            }
            $call->refresh();
            if (! empty($call->metadata['browser_transcription_enabled'])) {
                return;
            }
            $call->update(['metadata' => array_merge($call->metadata ?? [], ['browser_transcription_pending' => true])]);
            $this->gateway->command('transcription-start:'.$call->id.':'.($call->metadata['recording_generation'] ?? 0), '/calls/'.rawurlencode($customer->call_control_id).'/actions/transcription_start', ['transcription_engine' => 'Telnyx', 'transcription_tracks' => 'both']);
            $call->refresh()->update(['metadata' => array_merge($call->metadata ?? [], ['browser_transcription_enabled' => true, 'browser_transcription_pending' => false])]);
        }
    }

    public function disconnect(VoiceBrowserSession $session): void
    {
        LockedWrite::run(fn () => $session->update(['revoked_at' => $session->revoked_at ?? now(), 'registered' => false,
            'status' => $session->status === 'revoked' ? 'revoked' : 'revocation_pending']));
        try {
            foreach ($session->legs()->whereNull('ended_at')->with('browserCall')->get() as $leg) {
                if ($leg->role === 'supervisor') {
                    $this->hangupLeg($leg);
                } else {
                    $this->agentUnavailable($leg->browserCall, 'The staff phone disconnected.');
                }
            }
        } finally {
            // Carrier hangup failure must not leave the browser credential usable.
            $this->sessions->revoke($session);
        }
    }

    /** Safety net for a missing terminal webhook or a closed browser tab. */
    public function reconcile(): int
    {
        $count = 0;
        $browsers = VoiceBrowserCall::query()->whereNotIn('state', self::TERMINAL)->oldest()->limit(100)->get();
        foreach ($browsers as $browser) {
            Cache::lock('voice-browser-call:'.$browser->id, 90)->block(2, function () use ($browser, &$count): void {
                $browser->refresh();
                $agent = $browser->legs()->where('role', 'agent')->first();
                if (! $agent) {
                    return;
                }
                $session = $agent->session;
                $stale = ! $session || $session->revoked_at || $session->expires_at->isPast()
                    || ! $this->sessions->canOperate($session->user)
                    || ($session->heartbeat_at ?? $session->created_at)->lt(now()->subMinutes(2));
                foreach ($browser->legs()->where('role', 'supervisor')->whereNull('ended_at')->with('session.user')->get() as $supervisor) {
                    $device = $supervisor->session;
                    if (! $device || $device->revoked_at || $device->expires_at->isPast() || ! $this->sessions->canSupervise($device->user)
                        || ($device->heartbeat_at ?? $device->created_at)->lt(now()->subMinutes(2))) {
                        $this->hangupLeg($supervisor);
                    }
                }
                $timedOut = ! $agent->joined_at && $agent->created_at->lt(now()->subSeconds(45));
                if ($browser->state === 'recovering' || $stale || $timedOut) {
                    $this->agentUnavailable($browser, 'The staff phone is unavailable.');
                    $count++;
                } elseif (! empty($browser->metadata['error']) && $browser->updated_at->lt(now()->subSeconds(30))) {
                    $this->kickoff($browser);
                    $count++;
                }
            });
        }
        foreach (VoiceBrowserSession::query()->where(fn ($q) => $q->where('status', 'revocation_pending')
            ->orWhere(fn ($expired) => $expired->whereNull('revoked_at')->where('expires_at', '<=', now())))->limit(100)->get() as $session) {
            $this->disconnect($session);
        }

        return $count;
    }

    public function consent(VoiceCall $call, User $user, bool $consented, ?string $key = null): void
    {
        $browser = $this->active($call);
        abort_unless($browser, 409, 'The browser call is no longer connected.');
        Cache::lock('voice-browser-call:'.$browser->id, 90)->block(5, fn () => $this->consentLocked($call->fresh(), $user, $consented, $key));
    }

    private function consentLocked(VoiceCall $call, User $user, bool $consented, ?string $key = null): void
    {
        $browser = $this->active($call);
        abort_unless($browser && $browser->owner_id === $user->id && $this->sessions->canOperate($user), 403);
        if (! $consented) {
            $this->calls->setRecordingConsent($call, false, $key);
        } else {
            $call->update(['recording_consent_given' => true, 'metadata' => array_merge($call->metadata ?? [], [
                'recording_consent' => ['consented' => true, 'recorded_at' => now()->toIso8601String(), 'recorded_by' => $user->id, 'source' => 'staff_verbal_confirmation'],
            ])]);
        }
    }

    /** Called only after the outer webhook handler verifies Telnyx's signature. */
    public function handleWebhook(array $envelope): ?array
    {
        $data = $envelope['data'] ?? $envelope;
        $type = (string) ($data['event_type'] ?? '');
        $payload = $data['payload'] ?? $data;
        $control = (string) ($payload['call_control_id'] ?? $payload['participant_call_control_id'] ?? '');
        $leg = $control !== '' ? VoiceBrowserLeg::query()->where(fn ($q) => $q->where('call_control_id', $control)->orWhere('browser_call_control_id', $control))
            ->where(function ($q): void {
                $q->where('role', '!=', 'customer')->orWhereHas('browserCall', fn ($b) => $b->whereNotIn('state', self::TERMINAL));
            })->latest()->first() : null;
        $credentialConnection = (string) config('services.telnyx.voice.credential_connection_id', '');
        $isBrowserPeer = $credentialConnection !== '' && (string) ($payload['connection_id'] ?? '') === $credentialConnection;
        if (! $leg && $isBrowserPeer) {
            $leg = $this->correlateBrowserPeer($payload, $control);
        }
        if (! $leg && ! $isBrowserPeer && ! empty($payload['client_state'])) {
            $leg = VoiceBrowserLeg::query()->where('client_state', $payload['client_state'])->first();
            if ($leg && $control !== '' && $leg->call_control_id && $leg->call_control_id !== $control) {
                $leg = null;
            }
            if ($leg && $control !== '' && ! $leg->call_control_id) {
                $connection = (string) ($payload['connection_id'] ?? '');
                if ($connection !== '' && $connection !== (string) config('services.telnyx.voice.connection_id')) {
                    $leg = null;
                } else {
                    $leg->update(['call_control_id' => $control]);
                }
            }
        }
        if (! $leg) {
            if ($type === 'call.conversation.ended' && $control !== '') {
                $takeover = VoiceBrowserCall::query()->where('mode', 'takeover')->whereHas('legs', fn ($q) => $q->where('role', 'customer')->where('call_control_id', $control))->latest()->first();
                if ($takeover) {
                    // Conversation events terminate an AI segment; only a real
                    // customer-leg hangup terminates a call after takeover.
                    return ['status' => 'processed', 'voice_call_id' => $takeover->voice_call_id];
                }
            }
            if ($credentialConnection !== '' && (string) ($payload['connection_id'] ?? '') === $credentialConnection) {
                if ($control !== '' && ! in_array($type, ['call.hangup', 'call.ended', 'call.failed'], true)) {
                    $this->gateway->command('reject-browser-origin:'.$control, '/calls/'.rawurlencode($control).'/actions/hangup');
                }

                return ['status' => 'rejected_browser_origin', 'voice_call_id' => null];
            }

            return null;
        }

        if (! $isBrowserPeer && ! empty($payload['call_session_id']) && ! $leg->call_session_id) {
            $leg->update(['call_session_id' => $payload['call_session_id']]);
        }

        $browser = $leg->browserCall;
        // AI segment completion during takeover is not a customer hang-up.
        if ($browser->mode === 'takeover' && $type === 'call.conversation.ended') {
            return ['status' => 'processed', 'voice_call_id' => $browser->voice_call_id];
        }
        if ($leg->role === 'customer' && in_array($type, [
            'call.recording.saved', 'call.assistant.transcript', 'call.ai_assistant.message_history.updated',
            'call.ai_assistant.message_history_updated', 'call.assistant.message_history.updated',
            'call.summary.created', 'call.conversation_insights.generated',
        ], true)) {
            return null;
        }

        return Cache::lock('voice-browser-call:'.$browser->id, 90)->block(5, function () use ($leg, $type, $payload, $browser): array {
            $leg->refresh();
            $browser->refresh();
            $call = $browser->voiceCall->fresh();
            if ($leg->role === 'customer' && $leg->call_control_id && ! $call->call_control_id) {
                $call->update(['call_control_id' => $leg->call_control_id]);
            }
            try {
                if ($type === 'conference.participant.left' && ! $leg->ended_at) {
                    $leg->update(['state' => 'left']);
                    // Leaving a conference does not end the carrier call leg.
                    // A transferring customer must remain alive until the
                    // transfer result (or actual hangup) arrives.
                    if ($leg->role === 'customer' && $browser->state !== 'transferring') {
                        $this->end($browser);
                    } elseif ($leg->role === 'agent') {
                        $this->agentUnavailable($browser, 'The staff participant left the conference.');
                    } elseif ($leg->role === 'supervisor') {
                        $this->hangupLeg($leg);
                    }
                } elseif (in_array($type, ['call.hangup', 'call.ended', 'call.failed', 'call.no_answer'], true)) {
                    $leg->update(['state' => 'ended', 'ended_at' => $leg->ended_at ?? now()]);
                    if ($leg->role === 'customer') {
                        $call->update([
                            'status' => in_array($call->status, ['failed', 'cancelled', 'transferred'], true) ? $call->status
                                : ($type === 'call.failed' ? 'failed' : ($call->answered_at ? 'completed' : 'missed')),
                            'disposition' => $call->disposition ?: ($call->answered_at ? 'caller_hangup' : 'missed'),
                            'ended_at' => $call->ended_at ?? now(), 'provider_event_last_seen_at' => now(),
                            'duration_seconds' => $call->answered_at ? max(0, (int) ($payload['duration_seconds'] ?? $call->answered_at->diffInSeconds(now()))) : 0,
                        ]);
                        $this->end($browser, false);
                        if ($call->answered_at && filled($call->transcript) && empty($call->metadata['browser_finalized_at'])) {
                            try {
                                app(\App\Services\TelnyxAi\VoiceIntelligenceService::class)->finalize($call->fresh());
                                $call->refresh()->update(['metadata' => array_merge($call->metadata ?? [], ['browser_finalized_at' => now()->toIso8601String()])]);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Browser call final enrichment failed.', ['voice_call_id' => $call->id]);
                            }
                        }
                    } elseif ($leg->role === 'agent' && ! in_array($browser->state, self::TERMINAL, true)) {
                        $this->agentUnavailable($browser, 'The staff phone was not answered or disconnected.');
                    }
                } elseif ($type === 'conference.ended' && $browser->state === 'transferring') {
                    $browser->update(['metadata' => array_merge($browser->metadata ?? [], ['conference_ended' => true])]);
                } elseif ($type === 'conference.ended' && ! in_array($browser->state, self::TERMINAL, true)) {
                    $this->agentUnavailable($browser, 'The call conference ended.');
                } elseif ($leg->ended_at || $call->ended_at || in_array($browser->state, self::TERMINAL, true)) {
                    // Late answers and joins must never revive ended legs.
                } elseif ($type === 'call.answered') {
                    $leg->update(['answered_at' => $leg->answered_at ?? now(), 'state' => $leg->joined_at ? 'joined' : 'answered']);
                    if ($leg->role === 'agent') {
                        if ($leg->call_control_id) {
                            $this->createConference($browser, $leg);
                        }
                    } elseif ($leg->role === 'supervisor') {
                        if ($leg->call_control_id) {
                            $this->joinSupervisor($browser, $leg);
                        }
                    } else {
                        $call->update(['answered_at' => $call->answered_at ?? now(), 'started_at' => $call->started_at ?? now()]);
                        $this->connectCustomer($browser);
                    }
                } elseif ($type === 'conference.created') {
                    $conference = $payload['conference_id'] ?? $payload['id'] ?? null;
                    if ($conference && ! $browser->conference_id) {
                        $browser->update(['conference_id' => $conference]);
                    }
                } elseif ($type === 'conference.participant.joined') {
                    $conference = $payload['conference_id'] ?? $browser->conference_id;
                    if ($browser->conference_id && $conference !== $browser->conference_id) {
                        throw new RuntimeException('The provider reported a different conference.');
                    }
                    if ($conference && ! $browser->conference_id) {
                        $browser->update(['conference_id' => $conference]);
                    }
                    $leg->update(['joined_at' => $leg->joined_at ?? now(), 'state' => 'joined']);
                    if ($leg->role === 'agent') {
                        $this->connectCustomer($browser);
                    } elseif ($leg->role === 'customer' && $this->joinedAgent($browser)) {
                        $browser->update(['state' => 'active', 'metadata' => array_merge($browser->metadata ?? [], ['error' => null])]);
                        $call->update(['status' => 'active', 'handled_by' => $browser->mode === 'takeover' ? 'mixed' : 'human', 'answered_at' => $call->answered_at ?? now()]);
                    }
                } elseif ($type === 'call.transferred' && $leg->role === 'customer' && $browser->state === 'transferring') {
                    $browser->update(['state' => 'ended']);
                    $call->update(['status' => 'transferred', 'disposition' => 'transferred']);
                    foreach ($browser->legs()->where('role', '!=', 'customer')->whereNull('ended_at')->get() as $participant) {
                        $this->hangupLeg($participant);
                    }
                } elseif ($type === 'call.transfer.failed' && $browser->state === 'transferring') {
                    $customer = $browser->legs()->where('role', 'customer')->firstOrFail();
                    if (! $this->joinedAgent($browser) || ! empty($browser->metadata['conference_ended'])) {
                        app(VoiceRoutingService::class)->routeWithoutAi($call, 'browser_transfer_failed', true);
                        $browser->update(['state' => 'failed', 'metadata' => array_merge($browser->metadata ?? [], ['error' => 'The transfer failed; the customer was sent to the configured fallback.'])]);
                    } elseif ($customer->state === 'left') {
                        $customer->update(['joined_at' => null, 'state' => 'answered']);
                        $browser->update(['state' => 'connecting_customer', 'metadata' => array_merge($browser->metadata ?? [], [
                            'join_generation' => (int) ($browser->metadata['join_generation'] ?? 0) + 1, 'error' => 'Reconnecting the customer after the transfer failed.',
                        ])]);
                        $this->connectCustomer($browser);
                    } else {
                        $browser->update(['state' => 'active', 'metadata' => array_merge($browser->metadata ?? [], ['error' => 'The transfer was not completed.'])]);
                    }
                } elseif ($type === 'call.transcription' && $leg->role === 'customer') {
                    $this->transcription($call, $payload);
                }
            } catch (\Throwable $e) {
                $browser->refresh()->update(['metadata' => array_merge($browser->metadata ?? [], [
                    'error' => 'The phone provider has not completed this connection. Retry while keeping the customer call open.',
                ])]);
                throw $e;
            }

            return ['status' => 'processed', 'voice_call_id' => $call->id];
        });
    }

    /** Bind a signed receiving SIP leg to exactly one server-created offer. */
    private function correlateBrowserPeer(array $payload, string $control): ?VoiceBrowserLeg
    {
        if ($control === '') {
            return null;
        }
        $sessionId = (string) ($payload['call_session_id'] ?? '');
        $token = null;
        foreach (($payload['custom_headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), 'X-Repro-Offer') === 0) {
                $token = (string) ($header['value'] ?? '');
            }
        }
        if ($sessionId === '' && ! $token) {
            return null;
        }
        $candidate = VoiceBrowserLeg::query()->whereIn('role', ['agent', 'supervisor'])->whereNull('ended_at')
            ->whereNull('browser_call_control_id')->whereHas('browserCall', fn ($q) => $q->whereNotIn('state', self::TERMINAL))
            ->where(function ($q) use ($sessionId, $token): void {
                if ($sessionId !== '') {
                    $q->where('call_session_id', $sessionId);
                }
                if ($token) {
                    $q->orWhere('client_state', $token);
                }
            })->with('session')->get();
        if ($candidate->count() !== 1) {
            return null;
        }
        $leg = $candidate->first();
        $session = $leg->session;
        if (! $session || $session->revoked_at || $session->expires_at->isPast()) {
            return null;
        }
        // The opaque header can correlate an early event before /calls returns. A
        // device destination check prevents accepting a browser-originated leg.
        $destination = strtolower((string) ($payload['to'] ?? ''));
        $expected = strtolower($session->sip_username);
        $destinationUser = preg_replace('/^sips?:/i', '', explode('@', $destination)[0]);
        if ($destinationUser !== $expected) {
            return null;
        }
        if ($leg->call_session_id && $sessionId && ! hash_equals($leg->call_session_id, $sessionId)) {
            return null;
        }

        return Cache::lock('voice-browser-peer:'.$leg->id, 10)->block(2, function () use ($leg, $control, $sessionId): ?VoiceBrowserLeg {
            $leg->refresh();
            if ($leg->browser_call_control_id && $leg->browser_call_control_id !== $control) {
                return null;
            }
            $leg->update(['browser_call_control_id' => $control, 'call_session_id' => $leg->call_session_id ?: ($sessionId ?: null)]);

            return $leg;
        });
    }

    private function createConference(VoiceBrowserCall $browser, VoiceBrowserLeg $agent): void
    {
        if ($browser->conference_id) {
            return;
        }
        if (! $agent->session || $agent->session->revoked_at || ! $this->sessions->canOperate($agent->session->user)) {
            $this->agentUnavailable($browser, 'The staff phone is no longer authorized.');

            return;
        }
        $browser->update(['state' => 'connecting_agent']);
        $result = $this->gateway->command('conference:'.$browser->id, '/conferences', [
            'call_control_id' => $agent->call_control_id, 'name' => 'repro-'.$browser->id,
            'client_state' => $agent->client_state, 'beep_enabled' => 'never',
            'start_conference_on_create' => true, 'max_participants' => 10,
        ]);
        if (! empty($result['id'])) {
            $browser->update(['conference_id' => $result['id']]);
        }
        // Await conference.participant.joined before ever dialing the customer.
    }

    private function connectCustomer(VoiceBrowserCall $browser): void
    {
        $browser->refresh();
        if (! $browser->conference_id || ! $this->joinedAgent($browser) || in_array($browser->state, self::TERMINAL, true)) {
            return;
        }
        $customer = $browser->legs()->where('role', 'customer')->firstOrFail();
        $call = $browser->voiceCall->fresh();
        if ($call->ended_at || $customer->ended_at || $customer->joined_at) {
            return;
        }
        if ($browser->mode === 'human_outbound' && ! $customer->call_control_id) {
            $browser->update(['state' => 'dialing_customer']);
            $this->dialLeg($customer);

            return;
        }
        if ($browser->mode === 'human_inbound' && ! $customer->answered_at) {
            $this->gateway->command('answer:'.$customer->id, '/calls/'.rawurlencode($customer->call_control_id).'/actions/answer');
            $browser->update(['state' => 'connecting_customer']);

            return;
        }
        if (! $customer->answered_at && ! $call->answered_at) {
            return;
        }
        if ($browser->mode === 'takeover' && filled($browser->metadata['original_assistant_id'] ?? null)) {
            $this->gateway->command('ai-stop:'.$browser->id, '/calls/'.rawurlencode($customer->call_control_id).'/actions/ai_assistant_stop');
            $browser->update(['metadata' => array_merge($browser->metadata ?? [], ['ai_stopped' => true])]);
            $call->update(['ai_current_state' => 'ended']);
        }
        $browser->update(['state' => 'connecting_customer']);
        $customer->update(['state' => 'joining']);
        $this->gateway->command('join:'.$customer->id.':'.($browser->metadata['join_generation'] ?? 0), '/conferences/'.rawurlencode($browser->conference_id).'/actions/join', [
            'call_control_id' => $customer->call_control_id, 'client_state' => $customer->client_state,
            'mute' => false, 'supervisor_role' => 'none', 'start_conference_on_enter' => true, 'end_conference_on_exit' => false,
        ]);
    }

    private function joinSupervisor(VoiceBrowserCall $browser, VoiceBrowserLeg $leg): void
    {
        $agent = $this->joinedAgent($browser);
        if (! $agent || $browser->state !== 'active' || ! $leg->session || $leg->session->revoked_at
            || ! $this->sessions->canSupervise($leg->session->user)) {
            $this->hangupLeg($leg);

            return;
        }
        $this->gateway->command('join:'.$leg->id, '/conferences/'.rawurlencode($browser->conference_id).'/actions/join', [
            'call_control_id' => $leg->call_control_id, 'client_state' => $leg->client_state,
            'supervisor_role' => $leg->mode, 'mute' => $leg->mode === 'monitor',
            'whisper_call_control_ids' => $leg->mode === 'whisper' ? [$agent->call_control_id] : [],
            'end_conference_on_exit' => false, 'start_conference_on_enter' => true,
        ]);
        $leg->update(['state' => 'joining']);
    }

    private function agentUnavailable(VoiceBrowserCall $browser, string $error): void
    {
        $browser->refresh();
        if (in_array($browser->state, self::TERMINAL, true)) {
            return;
        }
        $call = $browser->voiceCall->fresh();
        $customer = $browser->legs()->where('role', 'customer')->first();
        if ($browser->state === 'transferring') {
            $browser->update(['metadata' => array_merge($browser->metadata ?? [], ['error' => 'Waiting for the provider to confirm the customer transfer.'])]);

            return;
        }
        if ($browser->state === 'active' || $customer?->joined_at) {
            $this->end($browser);

            return;
        }
        $browser->update(['state' => 'recovering', 'metadata' => array_merge($browser->metadata ?? [], ['error' => $error])]);
        foreach ($browser->legs()->where('role', '!=', 'customer')->whereNull('ended_at')->get() as $leg) {
            $this->hangupLeg($leg);
        }
        if ($browser->mode === 'human_outbound') {
            if ($customer?->call_control_id) {
                $this->hangupLeg($customer);
            }
            $call->update(['status' => 'cancelled', 'ended_at' => now(), 'disposition' => 'staff_unavailable', 'duration_seconds' => 0]);
        } elseif ($browser->mode === 'human_inbound' && ! $call->ended_at) {
            app(VoiceRoutingService::class)->routeWithoutAi($call, 'browser_staff_unavailable', true);
        } elseif ($browser->mode === 'takeover' && ! $call->ended_at && ! empty($browser->metadata['ai_stopped'])) {
            $destination = $this->numbers->normalize((string) ($this->settings->all()['support_handoff_number'] ?? ''));
            if ($destination && $destination !== $this->local($call)) {
                $this->gateway->command('takeover-fallback:'.$browser->id, '/calls/'.rawurlencode($call->call_control_id).'/actions/transfer', ['to' => $destination]);
                $call->update(['status' => 'human_handoff', 'disposition' => 'transfer_requested']);
            } elseif (! $this->numbers->aiEnabledForCall($call)) {
                app(VoiceRoutingService::class)->routeWithoutAi($call, 'voice_ai_disabled', true);
            } else {
                $result = $this->gateway->command('ai-resume:'.$browser->id, '/calls/'.rawurlencode($call->call_control_id).'/actions/ai_assistant_start', [
                    'assistant' => ['id' => $browser->metadata['original_assistant_id'], 'dynamic_variables' => $call->metadata['assistant_dynamic_variables'] ?? []],
                    'send_message_history_updates' => true,
                ]);
                $call->update(['handled_by' => 'ai', 'ai_current_state' => 'active', 'telnyx_conversation_id' => $result['conversation_id'] ?? $call->telnyx_conversation_id]);
            }
        }
        $browser->update(['state' => 'failed']);
        // A declined takeover never stops AI or hangs up the existing customer.
    }

    private function end(VoiceBrowserCall $browser, bool $customerAlso = true): void
    {
        if ($browser->state === 'ended') {
            return;
        }
        $browser->update(['state' => 'ending']);
        foreach ($browser->legs()->whereNull('ended_at')->get() as $leg) {
            if ($leg->role !== 'customer' || $customerAlso) {
                $this->hangupLeg($leg);
            }
        }
        if (! $browser->legs()->where('role', 'customer')->whereNotNull('call_control_id')->whereNull('ended_at')->exists()) {
            $browser->update(['state' => 'ended']);
            $call = $browser->voiceCall->fresh();
            if (! $call->ended_at) {
                $call->update(['status' => 'cancelled', 'ended_at' => now(), 'duration_seconds' => 0]);
            }
        }
    }

    private function hangupLeg(VoiceBrowserLeg $leg): void
    {
        if ($leg->ended_at) {
            return;
        }
        if ($leg->call_control_id) {
            $this->gateway->command('hangup:'.$leg->id, '/calls/'.rawurlencode($leg->call_control_id).'/actions/hangup');
            $leg->update(['state' => 'ending']);
        } else {
            $leg->update(['state' => 'ended', 'ended_at' => now()]);
        }
    }

    private function transcription(VoiceCall $call, array $payload): void
    {
        if (! $call->recording_consent_given || ! ($call->metadata['browser_transcription_enabled'] ?? false)) {
            return;
        }
        $transcription = $payload['transcription_data'] ?? $payload;
        if (($transcription['is_final'] ?? true) !== true) {
            return;
        }
        $text = trim((string) ($transcription['transcript'] ?? $transcription['text'] ?? ''));
        if ($text === '') {
            return;
        }
        $speaker = ($payload['transcription_track'] ?? $transcription['track'] ?? 'inbound') === 'outbound' ? 'agent' : 'customer';
        $metadata = $call->metadata ?? [];
        $live = $metadata['live'] ?? [];
        $seq = (int) ($live['transcript_seq'] ?? 0) + 1;
        $chunks = $live['transcript_chunks'] ?? [];
        $chunks[] = ['seq' => $seq, 'text' => $text, 'speaker' => $speaker, 'ts' => now()->toIso8601String(), 'telnyx_confidence' => $transcription['confidence'] ?? null];
        $metadata['live'] = array_merge($live, ['transcript_seq' => $seq, 'transcript_chunks' => array_slice($chunks, -400)]);
        $call->update(['transcript' => trim(($call->transcript ?? '')."\n".$speaker.': '.$text), 'live_transcript_preview' => $text, 'metadata' => $metadata]);
        VoiceCallTranscript::query()->create([
            'voice_call_id' => $call->id, 'speaker' => $speaker, 'text' => $text,
            'transcript_type' => 'final', 'occurred_at' => now(), 'confidence' => $transcription['confidence'] ?? null,
        ]);
        try {
            app(\App\Services\TelnyxAi\VoiceIntelligenceService::class)->onRealtimeUpdate($call->fresh(), ['text' => $text, 'confidence' => $transcription['confidence'] ?? null]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Browser call live enrichment failed.', ['voice_call_id' => $call->id]);
        }
    }

    private function active(VoiceCall $call): ?VoiceBrowserCall
    {
        return VoiceBrowserCall::query()->where('voice_call_id', $call->id)->whereNotIn('state', self::TERMINAL)->latest()->first();
    }

    private function callIsLive(VoiceCall $call): bool
    {
        return ! $call->ended_at && ! in_array($call->status, ['completed', 'failed', 'missed', 'cancelled', 'exhausted', 'transferred'], true);
    }

    private function createBrowser(VoiceCall $call, VoiceBrowserSession $session, string $mode, string $key, string $hash): VoiceBrowserCall
    {
        return VoiceBrowserCall::query()->create([
            'voice_call_id' => $call->id, 'owner_id' => $session->user_id, 'session_id' => $session->id,
            'mode' => $mode, 'idempotency_key' => $key, 'request_hash' => $hash,
            'metadata' => ['original_assistant_id' => $call->handled_by === 'ai' && $call->ai_current_state !== 'ended' ? $call->assistant_id : null,
                'original_handled_by' => $call->handled_by],
        ]);
    }

    private function newLeg(VoiceBrowserCall $browser, string $role, string $destination, ?VoiceBrowserSession $session = null, ?string $mode = null): VoiceBrowserLeg
    {
        return $browser->legs()->create([
            'role' => $role, 'destination' => $destination, 'session_id' => $session?->id, 'user_id' => $session?->user_id,
            'mode' => $mode, 'muted' => $mode === 'monitor', 'client_state' => base64_encode((string) Str::uuid()),
        ]);
    }

    private function kickoff(VoiceBrowserCall $browser): void
    {
        if (in_array($browser->state, self::TERMINAL, true)) {
            return;
        }
        $agent = $browser->legs()->where('role', 'agent')->whereNull('ended_at')->latest()->first();
        if ($agent?->joined_at) {
            $this->connectCustomer($browser);

            return;
        }
        if ($agent?->answered_at && $agent->call_control_id) {
            $this->createConference($browser, $agent);

            return;
        }
        if ($agent && ! $agent->call_control_id) {
            $this->dialLeg($agent);
        }
    }

    private function dialLeg(VoiceBrowserLeg $leg): void
    {
        $call = $leg->browserCall->voiceCall;
        if (! $leg->answered_at && ! $leg->ended_at) {
            $leg->update(['state' => 'dialing']);
        }
        $result = $this->gateway->command('dial:'.$leg->id, '/calls', [
            'connection_id' => (string) config('services.telnyx.voice.connection_id'),
            'to' => $leg->destination, 'from' => $this->local($call),
            'webhook_url' => (string) config('services.telnyx.voice.webhook_url'),
            'timeout_secs' => $leg->role === 'customer' ? 45 : 25, 'client_state' => $leg->client_state,
            'custom_headers' => $leg->role === 'customer' ? [] : [['name' => 'X-Repro-Offer', 'value' => $leg->client_state]],
        ]);
        if (! empty($result['call_control_id'])) {
            $leg->update(['call_control_id' => $result['call_control_id'], 'call_session_id' => $result['call_session_id'] ?? $leg->call_session_id]);
        }
        $leg->refresh();
        if (! $leg->call_control_id) {
            throw new RuntimeException('The phone is still connecting. Wait for the provider or retry this operation.');
        }
        if ($leg->role === 'customer') {
            $call->update(['call_control_id' => $leg->call_control_id, 'started_at' => $call->started_at ?? now()]);
        } elseif ($leg->answered_at && ! $leg->ended_at) {
            if ($leg->role === 'agent') {
                $this->createConference($leg->browserCall->fresh(), $leg);
            } else {
                $this->joinSupervisor($leg->browserCall->fresh(), $leg);
            }
        }
    }

    private function joinedAgent(VoiceBrowserCall $browser): ?VoiceBrowserLeg
    {
        return $browser->legs()->where('role', 'agent')->whereNotNull('joined_at')->whereNull('ended_at')->latest()->first();
    }

    private function assertSession(VoiceBrowserSession $session, User $user, bool $supervisor = false): void
    {
        abort_unless($session->user_id === $user->id && ($supervisor ? $this->sessions->canSupervise($user) : $this->sessions->canOperate($user)), 403);
        abort_unless($this->sessions->configuration($user)['ready'] && $this->sessions->usable($session), 409, 'Connect this browser phone before calling.');
    }

    private function busy(VoiceBrowserSession $session): bool
    {
        return $session->legs()->whereNull('ended_at')->whereHas('browserCall', fn ($q) => $q->whereNotIn('state', self::TERMINAL))->exists();
    }

    private function assertFree(VoiceBrowserSession $session): void
    {
        abort_if($this->busy($session), 409, 'This browser phone is already handling a call.');
    }

    private function sip(VoiceBrowserSession $session): string
    {
        return 'sip:'.$session->sip_username.'@sip.telnyx.com';
    }

    private function remote(VoiceCall $call): string
    {
        return (string) (strtoupper($call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone);
    }

    private function local(VoiceCall $call): string
    {
        return (string) (strtoupper($call->direction) === 'OUTBOUND' ? $call->from_phone : $call->to_phone);
    }

    private function hash(array $data): string
    {
        unset($data['idempotency_key']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(User $user, string $key, string $hash): ?VoiceBrowserCall
    {
        $existing = VoiceBrowserCall::query()->where('owner_id', $user->id)->where('idempotency_key', $key)->first();
        abort_if($existing && ! hash_equals($existing->request_hash, $hash), 409, 'This request key was already used for different call details.');

        return $existing;
    }

    private function sourceNumber(?string $from): string
    {
        $number = $this->numbers->normalize($from ?: (string) config('services.telnyx.from_number'));
        $configured = $this->numbers->normalize((string) config('services.telnyx.from_number'));
        abort_unless($number !== '' && ($number === $configured || $this->numbers->matching($number)->isNotEmpty()), 422, 'Choose a configured company phone number.');

        return $number;
    }

    private function assertOutboundAllowed(string $to): void
    {
        $mode = $this->settings->outboundMode();
        abort_if($mode === 'none', 422, 'Outbound calling is turned off.');
        abort_if($mode === 'canary' && ! in_array($to, $this->calls->canaryNumbers(), true), 422, 'The destination is not allowlisted for canary outbound.');
    }
}
