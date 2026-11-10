<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiInstallation;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuth
{
    public function configured(): bool
    {
        return config('aryeo_api.enabled') && config('aryeo_api.client_id') && config('aryeo_api.key_id')
            && is_readable((string) config('aryeo_api.private_key_path'));
    }

    public static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function assertion(): string
    {
        abort_unless($this->configured(), 409, 'aryeo_credentials_not_configured');
        $header = self::b64(json_encode(['alg' => 'RS512', 'typ' => 'JWT', 'kid' => config('aryeo_api.key_id')], JSON_THROW_ON_ERROR));
        $body = self::b64(json_encode(['iss' => config('aryeo_api.client_id'), 'sub' => config('aryeo_api.client_id'),
            'aud' => config('aryeo_api.auth_url').'/oauth/token', 'exp' => now()->addMinutes(2)->timestamp,
            'iat' => now()->timestamp, 'jti' => (string) Str::uuid()], JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private(file_get_contents(config('aryeo_api.private_key_path')));
        abort_unless($key && openssl_sign($header.'.'.$body, $signature, $key, OPENSSL_ALGO_SHA512), 409, 'aryeo_signing_key_invalid');

        return $header.'.'.$body.'.'.self::b64($signature);
    }

    public function tokenRequest(array $fields): array
    {
        app(RateBudget::class)->reserve();
        // Never retry a code exchange or rotating refresh after a lost response.
        try {
            $r = Http::asForm()->acceptJson()->timeout(30)->connectTimeout(10)->withoutRedirecting()
                ->post(config('aryeo_api.auth_url').'/oauth/token', $fields + [
                    'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                    'client_assertion' => $this->assertion(),
                ]);
        } catch (\Throwable) {
            abort(409, 'authorization_outcome_unknown_reconnect');
        }
        abort_unless($r->successful() && is_string($r->json('access_token')), 409, 'authorization_failed_reconnect');

        return $r->json();
    }

    public function applicationToken(string $scope): string
    {
        return $this->tokenRequest(['grant_type' => 'client_credentials', 'scope' => $scope])['access_token'];
    }

    public function store(AryeoApiInstallation $installation, array $tokens, bool $reconnecting = false): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($installation, $tokens, $reconnecting) {
            $current = $installation->fresh();
            abort_unless((int) $current->authorization_generation === (int) $installation->authorization_generation, 409, 'authorization_connection_changed');
            $current->update(['credentials' => $tokens, 'token_expires_at' => now()->addSeconds(min(900, max(1, (int) ($tokens['expires_in'] ?? 900)))),
                'refresh_started_at' => null, 'status' => 'connected', 'verified_at' => now(),
                'paused' => $reconnecting ? true : $current->paused,
                'authorization_generation' => (int) $current->authorization_generation + ($reconnecting ? 1 : 0)]);
        }), 'aryeo-api.store-authorization');
    }

    public function accessToken(AryeoApiInstallation $installation): string
    {
        $installation->refresh();
        abort_unless($installation->status === 'connected', 409, 'connection_required');
        if ($installation->token_expires_at?->gt(now()->addMinute())) {
            return $installation->credentials['access_token'];
        }
        $refresh = LockedWrite::run(fn () => DB::transaction(function () use ($installation) {
            $installation->refresh();
            if ($installation->token_expires_at?->gt(now()->addMinute())) {
                return null;
            }
            abort_if($installation->refresh_started_at, 409, 'refresh_in_progress_or_unknown');
            $refresh = $installation->credentials['refresh_token'] ?? null;
            abort_unless($refresh, 409, 'reconnect_required');
            $installation->update(['refresh_started_at' => now()]);

            return $refresh;
        }), 'aryeo-api.refresh');
        if ($refresh === null) {
            return $installation->credentials['access_token'];
        }
        try {
            $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
            abort_unless(! empty($tokens['refresh_token']), 409, 'refresh_rotation_missing');
            $me = $this->assertIdentity($installation, $tokens['access_token']);
            abort_unless(($installation->credentials['_subject_id'] ?? null) === $me['subject_id'], 409, 'oauth_subject_changed');
            $tokens['_subject_id'] = $me['subject_id'];
            $this->store($installation, $tokens);

            return $tokens['access_token'];
        } catch (\Throwable $e) {
            AryeoApiInstallation::whereKey($installation->id)->where('authorization_generation', $installation->authorization_generation)
                ->update(['status' => 'reconnect_required', 'paused' => true]);
            throw $e;
        }
    }

    public function assertIdentity(AryeoApiInstallation $installation, string $token): array
    {
        app(RateBudget::class)->reserve();
        $r = Http::withToken($token)->acceptJson()->timeout(20)->withoutRedirecting()->get(app(Client::class)->base($installation).'/me');
        $me = $r->json('data');
        abort_unless($r->successful() && is_array($me) && ($me['tenant_id'] ?? null) === $installation->company_id
            && ($me['tenant_type'] ?? null) === 'company' && ($me['subject_type'] ?? null) === 'user', 409, 'oauth_company_or_principal_mismatch');
        abort_unless(is_string($me['subject_id'] ?? null) && ($me['actor_type'] ?? null) === 'application', 409, 'oauth_delegated_user_required');
        foreach (explode(' ', config('aryeo_api.delegated_scopes')) as $scope) {
            if ($scope === 'offline_access') {
                continue;
            }
            [$resource, $level] = explode(':', $scope);
            $actual = $me['authority'][$resource]['level'] ?? null;
            abort_unless($actual === 'manage' || ($level === 'read' && $actual === 'read'), 409, 'oauth_scopes_insufficient');
        }

        return $me;
    }
}
