<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\PersonalAccessToken;
use App\Models\VoiceMobileSession;
use App\Support\VoiceLocks;
use Illuminate\Support\Str;

class VoiceMobileSessionService
{
    public function __construct(private VoiceBrowserGateway $gateway, private VoiceBrowserSessionService $permissions) {}

    public function configured(string $environment): bool
    {
        return (bool) config('mobile_voice.enabled') && (bool) config('mobile_voice.push_verified') && (bool) config('services.telnyx.voice.browser_enabled')
            && in_array($environment, ['debug', 'production'], true)
            && filled(config('mobile_voice.bundle_id')) && filled(config('mobile_voice.push_credentials.'.$environment))
            && filled(config('services.telnyx.api_key')) && filled(config('services.telnyx.voice.connection_id'))
            && str_starts_with((string) config('services.telnyx.voice.webhook_url'), 'https://');
    }

    public function authorized(VoiceMobileSession $s): bool
    {
        $s = $s->fresh();
        return $s && $this->configured($s->apns_environment) && $s->status === 'ready' && ! $s->revoked_at
            && $s->expires_at->isFuture() && ($owner = $this->activeOwner($s)) && $this->permissions->canOperate($owner);
    }

    public function registered(VoiceMobileSession $s): bool
    {
        $s = $s->fresh();
        return $s && $this->configured($s->apns_environment) && $s->status === 'ready' && ! $s->revoked_at
            && $s->registration_expires_at->isFuture() && filled($s->push_token_hash)
            && ($owner = $this->activeOwner($s)) && $this->permissions->canOperate($owner);
    }

    private function tokenFor(User $user, mixed $id): ?PersonalAccessToken
    {
        if (! $id || ! $user->isAccountEligibleForAuthentication()) { return null; }
        // Re-read persistence on every gate: a cached Sanctum token/user relation
        // must not survive logout, expiry, account removal or owner changes.
        $token = PersonalAccessToken::find($id);
        $minutes = config('sanctum.expiration');
        if (! $token || (string) $token->tokenable_id !== (string) $user->id || $token->tokenable_type !== $user->getMorphClass()
            || ($token->expires_at && ! $token->expires_at->isFuture())
            || ($minutes && $token->created_at->lte(now()->subMinutes($minutes)))) { return null; }
        return $token;
    }

    private function activeOwner(VoiceMobileSession $s): ?User
    {
        $owner = User::find($s->user_id);
        return $owner && $this->tokenFor($owner, $s->personal_access_token_id) ? $owner : null;
    }

    public function currentToken(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();
        $owner = User::find($user->id);
        return $owner && $token instanceof \Laravel\Sanctum\PersonalAccessToken
            ? $this->tokenFor($owner, $token->getKey()) : null;
    }

    public function matchesCurrentToken(VoiceMobileSession $s, User $user): bool
    {
        $token = $this->currentToken($user);
        return $token && (string) $s->user_id === (string) $user->id
            && (string) $s->personal_access_token_id === (string) $token->id;
    }

    private function boundedExpiry(PersonalAccessToken $token, \Illuminate\Support\Carbon $desired): \Illuminate\Support\Carbon
    {
        if ($token->expires_at) { $desired = $desired->min($token->expires_at); }
        if ($minutes = config('sanctum.expiration')) { $desired = $desired->min($token->created_at->copy()->addMinutes($minutes)); }
        return $desired;
    }

    /** Renew this same server credential before a background ring; never create a replacement identity. */
    public function prepareIncoming(VoiceMobileSession $s): void
    {
        VoiceLocks::lock('voice-mobile-device:'.$s->user_id.':'.$s->device_id, 60)->block(5, function () use ($s) {
            $s->refresh();
            abort_unless($this->registered($s), 403);
            if ($s->expires_at->lt(now()->addMinute())) {
                $owner = $this->activeOwner($s);
                $token = $owner ? $this->tokenFor($owner, $s->personal_access_token_id) : null;
                abort_unless($token, 403);
                $expiry = $this->boundedExpiry($token, now()->addHour());
                $this->gateway->request('PATCH', '/telephony_credentials/'.$s->credential_id, ['expires_at' => $expiry->toIso8601String()]);
                $s->update(['expires_at' => $expiry]);
            }
            abort_unless($this->registered($s), 403);
        });
    }

    public function reachable(User $u): bool
    {
        return VoiceMobileSession::where('user_id', $u->id)->whereNull('revoked_at')->whereNotNull('push_token_hash')
            ->where('status', 'ready')->where('registration_expires_at', '>', now())->get()->contains(fn ($s) => $this->registered($s));
    }

    public function bootstrap(User $user, array $data): array
    {
        abort_unless($this->permissions->canOperate($user), 403);
        $token = $this->currentToken($user);
        abort_unless($token, 401, 'Sign in again before connecting mobile calling.');
        $env = $data['apns_environment'];
        abort_unless($this->configured($env), 503, 'Mobile calling is awaiting provider and Apple push verification.');

        return VoiceLocks::lock('voice-mobile-device:'.$user->id.':'.strtolower($data['device_id']), 60)->block(5, function () use ($user, $data, $env, $token) {
            abort_unless($this->currentToken($user)?->id === $token->id, 401);
            $s = VoiceMobileSession::where('user_id', $user->id)->where('device_id', strtolower($data['device_id']))->whereNull('revoked_at')->latest()->first();
            if ($s) {
                abort_unless($s->apns_environment === $env && $s->bundle_id === config('mobile_voice.bundle_id'), 409, 'Revoke the old device registration before changing environments.');
                abort_unless((string) $s->personal_access_token_id === (string) $token->id, 409, 'Revoke the previous login registration before reconnecting this phone.');
            }
            $s ??= VoiceMobileSession::create(['user_id' => $user->id, 'personal_access_token_id' => $token->id, 'device_id' => strtolower($data['device_id']),
                'apns_environment' => $env, 'bundle_id' => config('mobile_voice.bundle_id'), 'expires_at' => $this->boundedExpiry($token, now()->addHour()), 'registration_expires_at' => $this->boundedExpiry($token, now()->addDays(30)),
                'connection_username' => 'rpm'.Str::lower(Str::random(24))]);
            $push = strtolower($data['push_token'] ?? '');
            // A token cannot silently move between accounts. Sign-out must revoke the previous binding.
            $hash = $push === '' ? null : hash_hmac('sha256', $push, (string) config('app.key'));
            if ($hash) {
                abort_if(VoiceMobileSession::where('push_token_hash', $hash)->whereNull('revoked_at')->where('id', '!=', $s->id)->exists(), 409, 'Revoke the previous phone registration before reconnecting.');
            }
            $s->update(['push_token' => $push ?: null, 'push_token_hash' => $hash]);
            $this->provision($s);
            $expiry = $this->boundedExpiry($token, now()->addHour());
            $this->gateway->request('PATCH', '/telephony_credentials/'.$s->credential_id, ['expires_at' => $expiry->toIso8601String()]);
            $jwt = $this->gateway->request('POST', '/telephony_credentials/'.$s->credential_id.'/token', [], true);
            $parts = explode('.', $jwt);
            $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
            abort_unless(is_array($claims) && is_numeric($claims['exp'] ?? null) && $claims['exp'] > now()->addSeconds(30)->timestamp, 503, 'The provider returned an invalid mobile credential.');
            $expires = $expiry->min(\Illuminate\Support\Carbon::createFromTimestampUTC((int) $claims['exp']));
            // Provider provisioning may outlast a login. Never return its JWT if
            // logout/expiry happened while those external requests were pending.
            $current = $s->fresh();
            $owner = $this->activeOwner($current);
            if ($current->revoked_at || ! $this->matchesCurrentToken($current, $user) || ! $owner || ! $this->permissions->canOperate($owner)) {
                $s->update(['revoked_at' => now(), 'status' => 'revocation_pending', 'push_token' => null, 'push_token_hash' => null]);
                abort(401, 'Sign in again before connecting mobile calling.');
            }
            $s->update(['status' => 'ready', 'expires_at' => $expires, 'registration_expires_at' => $this->boundedExpiry($token, now()->addDays(30))]);

            return ['session_id' => $s->id, 'device_id' => $s->device_id, 'jwt' => $jwt, 'expires_at' => $expires->toIso8601String(),
                'apns_environment' => $env, 'receive_calls' => $hash !== null, 'make_calls' => true, 'push_when_active' => false];
        });
    }

    private function provision(VoiceMobileSession $s): void
    {
        if (! $s->connection_id) {
            $name = 'repro-mobile-'.$s->id;
            $found = $this->gateway->request('GET', '/credential_connections', ['filter[connection_name][eq]' => $name]);
            $matches = collect($found)->filter(fn ($v) => is_array($v) && ($v['connection_name'] ?? '') === $name && ($v['user_name'] ?? '') === $s->connection_username)->values();
            abort_if($matches->count() > 1, 503, 'Mobile setup requires provider reconciliation.');
            $connection = $matches->first();
            if (! $connection) {
                abort_if($s->provision_attempted_at, 503, 'The provider has not confirmed this setup. Retry this device later.');
                $s->update(['provision_attempted_at' => now()]);
                $connection = $this->gateway->request('POST', '/credential_connections', [
                    'connection_name' => $name, 'user_name' => $s->connection_username, 'password' => Str::random(48), 'active' => true,
                    'encrypted_media' => 'SRTP', 'sip_uri_calling_preference' => 'internal', 'webhook_api_version' => '2',
                    'webhook_event_url' => config('services.telnyx.voice.webhook_url'),
                    'ios_push_credential_id' => config('mobile_voice.push_credentials.'.$s->apns_environment),
                    'outbound' => ['call_parking_enabled' => true, 'channel_limit' => 1, 'localization' => 'US'],
                ]);
            }
            abort_unless(filled($connection['id'] ?? null), 503, 'The provider did not confirm the mobile connection.');
            $s->update(['connection_id' => (string) $connection['id']]);
        }
        $v = $this->gateway->request('GET', '/credential_connections/'.$s->connection_id);
        abort_unless(($v['user_name'] ?? '') === $s->connection_username && ($v['active'] ?? false)
            && ($v['encrypted_media'] ?? '') === 'SRTP' && ($v['sip_uri_calling_preference'] ?? '') === 'internal'
            && ($v['webhook_api_version'] ?? '') === '2' && ($v['webhook_event_url'] ?? '') === config('services.telnyx.voice.webhook_url')
            && ($v['ios_push_credential_id'] ?? '') === config('mobile_voice.push_credentials.'.$s->apns_environment)
            && ($v['outbound']['call_parking_enabled'] ?? false) && empty($v['outbound']['outbound_voice_profile_id']), 503, 'Mobile provider settings require verification.');
        if (! $s->credential_id) {
            $name = 'repro-mobile-'.$s->id;
            $matches = collect($this->gateway->request('GET', '/telephony_credentials', ['filter[name]' => $name, 'filter[resource_id]' => $s->connection_id]))
                ->filter(fn ($v) => is_array($v) && ($v['name'] ?? '') === $name && ($v['resource_id'] ?? '') === $s->connection_id)->values();
            abort_if($matches->count() > 1, 503, 'Mobile credential creation needs provider reconciliation.');
            $v = $matches->first();
            if (! $v) {
                // Never create a second credential after an ambiguous provider response.
                abort_if($s->credential_attempted_at, 503, 'The provider has not confirmed this mobile credential. Retry later.');
                $s->update(['credential_attempted_at' => now()]);
                $v = $this->gateway->request('POST', '/telephony_credentials', ['connection_id' => $s->connection_id, 'name' => $name, 'expires_at' => now()->addHour()->toIso8601String()]);
            }
            abort_unless(filled($v['id'] ?? null) && preg_match('/^gencred[A-Za-z0-9_-]+$/', $v['sip_username'] ?? ''), 503, 'Invalid mobile credential.');
            $s->update(['credential_id' => $v['id'], 'sip_username' => $v['sip_username']]);
        }
    }

    public function revoke(VoiceMobileSession $s): void
    {
        // Local authorization stops first, even when provider cleanup is temporarily unavailable.
        $s->update(['revoked_at' => $s->revoked_at ?? now(), 'status' => 'revocation_pending', 'push_token' => null, 'push_token_hash' => null]);
        $failure = null;
        try {
            app(VoiceMobileCallService::class)->endSession($s);
        } catch (\Throwable $e) {
            $failure = $e;
        }
        try {
            if ($s->connection_id) {
                $this->gateway->request('PATCH', '/credential_connections/'.$s->connection_id, ['active' => false]);
            }
        } catch (\Throwable $e) {
            $failure ??= $e;
        }
        try {
            if ($s->credential_id) {
                $this->gateway->request('DELETE', '/telephony_credentials/'.$s->credential_id);
            }
        } catch (\Throwable $e) {
            $failure ??= $e;
        }
        if ($failure) {
            throw $failure;
        }
        $s->update(['status' => 'revoked']);
    }

    public function reconcile(): void
    {
        $sessions = VoiceMobileSession::where('status', 'revocation_pending')->orWhere(fn ($q) => $q->whereNull('revoked_at')->where(function ($q) {
            $q->where('registration_expires_at', '<=', now())->orWhereDoesntHave('personalAccessToken', function ($token) {
                $token->where('tokenable_type', (new User)->getMorphClass())->whereColumn('tokenable_id', 'voice_mobile_sessions.user_id')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                if ($minutes = config('sanctum.expiration')) { $token->where('created_at', '>', now()->subMinutes($minutes)); }
            });
        }))->limit(50)->get();
        foreach ($sessions as $s) {
            try {
                $this->revoke($s);
            } catch (\Throwable) {
                \Illuminate\Support\Facades\Log::warning('Mobile voice revocation pending.', ['session_id' => $s->id]);
            }
        }
    }
}
