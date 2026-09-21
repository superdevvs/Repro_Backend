<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceBrowserSession;
use App\Services\RolePermissionService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class VoiceBrowserSessionService
{
    public function __construct(private readonly VoiceBrowserGateway $gateway, private readonly RolePermissionService $permissions) {}

    public function canOperate(User $user): bool
    {
        return $user->isAccountEligibleForAuthentication() && $this->permissions->userCan($user, 'voice-calls', 'view')
            && $this->permissions->userCan($user, 'voice-calls', 'operate');
    }

    public function canSupervise(User $user): bool
    {
        return $user->isAccountEligibleForAuthentication() && $this->permissions->userCan($user, 'voice-calls', 'view')
            && $this->permissions->userCan($user, 'voice-calls', 'supervise');
    }

    public function configuration(User $user): array
    {
        $blockers = [];
        $enabled = (bool) config('services.telnyx.voice.browser_enabled', false);
        if (! $enabled) {
            $blockers[] = 'Browser calling is not enabled.';
        }
        if (! filled(config('services.telnyx.voice.credential_connection_id'))) {
            $blockers[] = 'The browser phone connection is not configured.';
        }
        if (! filled(config('services.telnyx.voice.connection_id'))) {
            $blockers[] = 'The call control application is not configured.';
        }
        if (! filled(config('services.telnyx.api_key'))) {
            $blockers[] = 'The phone provider is not configured.';
        }
        if (! filled(config('services.telnyx.voice.webhook_url'))) {
            $blockers[] = 'The phone webhook is not configured.';
        }
        $ready = $blockers === [];
        $operate = $ready && $this->canOperate($user);
        $supervise = $ready && $this->canSupervise($user);

        return ['enabled' => $enabled, 'ready' => $ready, 'presence_verification' => 'provider', 'blockers' => $blockers, 'capabilities' => [
            'human_outbound' => $operate, 'receive_calls' => $operate, 'takeover' => $operate,
            'monitor' => $supervise, 'whisper' => $supervise, 'barge' => $supervise,
        ]];
    }

    public function connect(User $user, string $deviceId): array
    {
        abort_unless($this->canOperate($user) || $this->canSupervise($user), 403);
        $config = $this->configuration($user);
        abort_unless($config['ready'], 422, implode(' ', $config['blockers']));

        return Cache::lock('voice-browser-session:'.$user->id.':'.$deviceId, 50)->block(5, function () use ($user, $deviceId): array {
            $session = VoiceBrowserSession::query()->where('user_id', $user->id)->where('device_id', $deviceId)
                ->whereNull('revoked_at')->where('status', 'ready')->where('expires_at', '>', now())->latest()->first();
            if ($session) {
                return $this->issueToken($session, $deviceId);
            }
            $session = LockedWrite::run(fn () => VoiceBrowserSession::query()->create([
                'user_id' => $user->id, 'device_id' => $deviceId, 'expires_at' => now()->addHour(),
            ]));
            try {
                $credential = $this->gateway->request('POST', '/telephony_credentials', [
                    'connection_id' => (string) config('services.telnyx.voice.credential_connection_id'),
                    'expires_at' => $session->expires_at->toIso8601String(), 'name' => 'repro-browser-'.$session->id,
                ]);
                if (empty($credential['id']) || ! preg_match('/^gencred[A-Za-z0-9_-]+$/', (string) ($credential['sip_username'] ?? ''))) {
                    throw new RuntimeException('The phone provider returned an invalid browser credential.');
                }
                LockedWrite::run(fn () => $session->update([
                    'credential_id' => $credential['id'], 'sip_username' => $credential['sip_username'], 'status' => 'ready',
                ]));
                $response = $this->issueToken($session, $deviceId, false);
                $response['registration_delay_ms'] = 5000;

                return $response;
            } catch (\Throwable $e) {
                LockedWrite::run(fn () => $session->update(['status' => 'failed', 'registered' => false]));
                throw $e;
            }
        });
    }

    public function issueToken(VoiceBrowserSession $session, string $deviceId, bool $extend = true): array
    {
        abort_unless(hash_equals($session->device_id, $deviceId), 403);
        abort_if($session->revoked_at || $session->expires_at->isPast() || $session->status !== 'ready', 409, 'Reconnect this browser phone.');
        if ($extend) {
            $expires = now()->addHour();
            $this->gateway->request('PATCH', '/telephony_credentials/'.rawurlencode($session->credential_id), ['expires_at' => $expires->toIso8601String()]);
            LockedWrite::run(fn () => $session->update(['expires_at' => $expires]));
        }
        $token = $this->gateway->request('POST', '/telephony_credentials/'.rawurlencode($session->credential_id).'/token', [], true);
        if (! is_string($token) || count(explode('.', $token)) !== 3) {
            throw new RuntimeException('The phone provider did not return a valid session token.');
        }

        return array_merge($this->safeState($session), ['token' => $token, 'registration_delay_ms' => 0]);
    }

    public function safeState(VoiceBrowserSession $session): array
    {
        $offers = $session->legs()->whereNull('ended_at')->whereHas('browserCall', fn ($q) => $q->whereNotIn('state', ['failed', 'ended']))
            ->with('browserCall.voiceCall.callerUser')->get()->map(function ($leg): array {
                $call = $leg->browserCall->voiceCall;

                return [
                    'voice_call_id' => $call->id, 'agent_call_control_id' => $leg->call_control_id,
                    'browser_call_control_id' => $leg->browser_call_control_id,
                    'role' => $leg->role, 'mode' => $leg->mode, 'state' => $leg->state,
                    'caller_name' => $call->callerUser?->name,
                    'remote_phone' => strtoupper($call->direction) === 'OUTBOUND' ? $call->to_phone : $call->from_phone,
                ];
            })->all();

        return [
            'id' => $session->id, 'device_id' => $session->device_id,
            'status' => $session->expires_at->isPast() || $session->revoked_at ? 'expired' : $session->status,
            'expires_at' => $session->expires_at->toIso8601String(), 'registered' => $session->registered,
            'offers' => $offers,
        ];
    }

    public function heartbeat(VoiceBrowserSession $session, bool $registered, ?bool $transportConnected = null): array
    {
        $session->refresh();
        abort_if($session->revoked_at || $session->expires_at->isPast() || $session->status !== 'ready', 409, 'Reconnect this browser phone.');
        $credentialId = $session->credential_id;
        $cacheKey = 'voice-browser-registration:'.hash('sha256', $credentialId.'|'.config('services.telnyx.voice.credential_connection_id'));
        $attemptKey = 'voice-browser-presence-attempt:'.$session->id;
        $attempt = (string) Str::uuid();
        $writeLock = 'voice-browser-presence-write:'.$session->id;
        Cache::lock($writeLock, 10)->block(1, fn () => Cache::put($attemptKey, $attempt, 30));
        // Legacy registered=true is also only a transport hint. Availability
        // requires the carrier to confirm this session's server-owned identity.
        if (! ($transportConnected ?? $registered)) {
            Cache::forget($cacheKey);
            $registered = false;
        } else {
            try {
                $registered = Cache::remember($cacheKey, 10, function () use ($session): bool {
                    if (! filled($session->credential_id) || ! filled($session->sip_username)) {
                        return false;
                    }
                    $state = $this->gateway->request('GET', '/sip_registration_status', [
                        'credential_type' => 'telephony_credential', 'username' => $session->sip_username,
                    ], timeout: 3);

                    return ($state['registered'] ?? null) === true
                        && ($state['sip_registration_status'] ?? null) === 'registered'
                        && (! array_key_exists('connection_id', $state) || (string) $state['connection_id'] === (string) config('services.telnyx.voice.credential_connection_id'))
                        && (! array_key_exists('credential_type', $state) || $state['credential_type'] === 'telephony_credential')
                        && (! array_key_exists('credential_username', $state) || $state['credential_username'] === $session->sip_username);
                });
            } catch (\Throwable $exception) {
                $registered = false;
            }
        }
        // Provider I/O must not hold a SQLite write transaction. Recheck in the
        // write predicate so a concurrent revocation/expiry cannot be revived.
        Cache::lock($writeLock, 10)->block(1, function () use ($session, $attemptKey, $attempt, $credentialId, $registered): void {
            if (Cache::get($attemptKey) !== $attempt) {
                return; // A newer disconnect or presence request wins.
            }
            $updated = LockedWrite::run(fn () => VoiceBrowserSession::query()->whereKey($session->id)
                ->whereNull('revoked_at')->where('status', 'ready')->where('expires_at', '>', now())
                ->where('credential_id', $credentialId)
                ->update(['heartbeat_at' => now(), 'registered' => $registered, 'updated_at' => now()]));
            abort_unless($updated === 1, 409, 'Reconnect this browser phone.');
        });

        return $this->safeState($session->fresh());
    }

    public function usable(VoiceBrowserSession $session): bool
    {
        return $session->status === 'ready' && ! $session->revoked_at && $session->expires_at->isFuture()
            && $session->registered && $session->heartbeat_at?->gte(now()->subSeconds(60));
    }

    public function revoke(VoiceBrowserSession $session): void
    {
        Cache::lock('voice-browser-revoke:'.$session->id, 40)->block(5, function () use ($session): void {
            $session->refresh();
            if ($session->revoked_at && $session->status === 'revoked') {
                return;
            }
            // Disable local use before network I/O, but retain a durable retry
            // state until the provider confirms deletion (or already absent).
            LockedWrite::run(fn () => $session->update(['revoked_at' => $session->revoked_at ?? now(), 'registered' => false, 'status' => 'revocation_pending']));
            if ($session->credential_id) {
                $this->gateway->request('DELETE', '/telephony_credentials/'.rawurlencode($session->credential_id));
            }
            LockedWrite::run(fn () => $session->update(['status' => 'revoked']));
        });
    }
}
