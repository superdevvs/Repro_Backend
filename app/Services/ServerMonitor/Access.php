<?php

namespace App\Services\ServerMonitor;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class Access
{
    public function eligible(User $user): bool
    {
        $roles = array_merge([$user->role], is_array($user->secondary_roles) ? $user->secondary_roles : []);
        $roles = array_map(fn ($r) => strtolower(str_replace(['_', '-'], '', (string) $r)), $roles);
        return $user->isAccountEligibleForAuthentication() && in_array('superadmin', $roles, true);
    }

    private function key(): string
    {
        abort_unless(config('server-monitor.enabled'), 503, 'Server monitor is not configured.');
        $key = @file_get_contents(config('server-monitor.key_file'));
        abort_unless(is_string($key) && strlen(trim($key)) >= 64, 503, 'Server monitor is not configured.');
        return trim($key);
    }

    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

    public function issue(User $user, PersonalAccessToken $token): string
    {
        $body = $this->encode(json_encode(['v' => 1, 'aud' => 'repro-monitor', 'sub' => (string) $user->id,
            'tid' => (string) $token->id, 'iat' => now()->timestamp, 'exp' => now()->timestamp + 60,
            'jti' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
        return $body.'.'.$this->encode(hash_hmac('sha256', $body, $this->key(), true));
    }

    public function validate(string $ticket): array
    {
        abort_if(strlen($ticket) > 2048, 401);
        $parts = explode('.', $ticket);
        abort_unless(count($parts) === 2, 401);
        [$body, $signature] = $parts;
        abort_unless(hash_equals($this->encode(hash_hmac('sha256', $body, $this->key(), true)), $signature), 401);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/'), true), true);
        abort_unless(is_array($claims) && ($claims['v'] ?? null) === 1 && ($claims['aud'] ?? null) === 'repro-monitor'
            && is_int($claims['exp'] ?? null) && is_int($claims['iat'] ?? null)
            && $claims['exp'] > now()->timestamp && $claims['exp'] - $claims['iat'] <= 60 && $claims['iat'] <= now()->timestamp + 5, 401);
        $token = PersonalAccessToken::find($claims['tid'] ?? null);
        abort_unless($token && (!$token->expires_at || $token->expires_at->isFuture()), 401);
        $expiration = config('sanctum.expiration');
        abort_if($expiration && $token->created_at->lte(now()->subMinutes($expiration)), 401);
        $user = $token->tokenable;
        abort_unless($user instanceof User && (string) $user->id === ($claims['sub'] ?? null) && $this->eligible($user), 403);
        return ['id' => (string) $user->id, 'expiresAt' => $claims['exp']];
    }
}
