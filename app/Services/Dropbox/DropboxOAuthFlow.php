<?php

namespace App\Services\Dropbox;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class DropboxOAuthFlow
{
    public const COOKIE_NAME = 'repro_dropbox_oauth';
    public const TTL_SECONDS = 600;

    public function administrator(Request $request): User
    {
        $user = $request->user();
        abort_unless($this->isAdministrator($user), 403, 'Only an active administrator can manage the studio Dropbox.');
        abort_if($request->attributes->get('is_impersonating') || $request->hasHeader('X-Impersonate-User-Id'), 403, 'Exit impersonation before managing Dropbox.');

        // API mutations require an explicit bearer credential rather than
        // ambient authentication on routes without session CSRF checks.
        $token = $user->currentAccessToken();
        abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken
            && $token->can('dropbox:manage') && $this->tokenIsCurrent($token), 403, 'Sign in again before managing Dropbox.');

        return $user;
    }

    public function begin(Request $request, array $connection): array
    {
        $user = $this->administrator($request);
        $state = $this->randomValue(32);
        $browser = $this->randomValue(32);
        $verifier = $this->randomValue(64);
        $token = $user->currentAccessToken();
        $payload = array_merge($connection, [
            'admin_id' => $user->id,
            'token_id' => $token->getKey(),
            'token_fingerprint' => hash('sha256', $token->token),
            'password_fingerprint' => hash('sha256', $user->getAuthPassword()),
            'browser_hash' => hash('sha256', $browser),
            'code_verifier' => $verifier,
            'expires_at' => now()->timestamp + self::TTL_SECONDS,
        ]);
        Cache::put($this->key($state), Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), self::TTL_SECONDS);

        return [
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'cookie' => $this->cookie($browser, now()->timestamp + self::TTL_SECONDS),
        ];
    }

    public function consume(Request $request): array
    {
        $state = $request->query('state');
        $browser = $request->cookie(self::COOKIE_NAME);
        abort_unless(is_string($state) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)
            && is_string($browser) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $browser), 400, 'Dropbox authorization expired or is invalid.');

        return Cache::lock($this->key($state).':consume', 10)->block(3, function () use ($state, $browser) {
            $key = $this->key($state);
            $encrypted = Cache::get($key);
            abort_unless(is_string($encrypted), 400, 'Dropbox authorization expired or is invalid.');
            $payload = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($payload)
                && ($payload['expires_at'] ?? 0) > now()->timestamp
                && hash_equals((string) ($payload['browser_hash'] ?? ''), hash('sha256', $browser)), 400, 'Dropbox authorization expired or is invalid.');

            // Consume before exchange so a duplicate callback cannot race it.
            Cache::forget($key);
            $payload['administrator'] = $this->revalidate($payload);

            return $payload;
        });
    }

    /** Recheck under the connection lock after the provider round trips. */
    public function revalidate(array $payload): User
    {
        $user = User::find($payload['admin_id'] ?? null);
        abort_unless(($payload['expires_at'] ?? 0) > now()->timestamp && $this->isAdministrator($user), 403, 'Dropbox authorization is no longer valid.');
        $token = $user->tokens()->find($payload['token_id'] ?? null);
        abort_unless($token instanceof PersonalAccessToken && $this->tokenIsCurrent($token)
            && $token->can('dropbox:manage')
            && hash_equals((string) ($payload['token_fingerprint'] ?? ''), hash('sha256', $token->token))
            && hash_equals((string) ($payload['password_fingerprint'] ?? ''), hash('sha256', $user->getAuthPassword())), 403, 'Dropbox authorization is no longer valid.');

        return $user;
    }

    public function expiredCookie(): Cookie
    {
        return $this->cookie('', 1);
    }

    private function isAdministrator(?User $user): bool
    {
        return $user && $user->isAccountEligibleForAuthentication()
            && in_array(strtolower(trim((string) $user->role)), ['admin', 'superadmin'], true);
    }

    private function tokenIsCurrent(PersonalAccessToken $token): bool
    {
        $expiration = config('sanctum.expiration');

        return (! $token->expires_at || $token->expires_at->isFuture())
            && (! $expiration || $token->created_at->gt(now()->subMinutes($expiration)));
    }

    private function randomValue(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function key(string $state): string
    {
        return 'dropbox:oauth:'.hash('sha256', $state);
    }

    private function cookie(string $value, int $expires): Cookie
    {
        return new Cookie(self::COOKIE_NAME, $value, $expires, '/api/dropbox', null,
            ! app()->environment(['local', 'testing']), true, false, Cookie::SAMESITE_LAX);
    }
}
