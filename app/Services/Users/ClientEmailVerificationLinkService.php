<?php

namespace App\Services\Users;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ClientEmailVerificationLinkService
{
    public const SIGNATURE_VERSION = '2';

    public function buildUrl(User $user, DateTimeInterface|int|null $expiresAt = null): string
    {
        $emailHash = $this->emailHashForUser($user);
        $expires = $this->normalizeExpiryTimestamp($expiresAt);
        $relativePath = route('api.email-verification.verify', [
            'user' => $user->id,
            'hash' => $emailHash,
        ], false);

        $query = http_build_query([
            'expires' => $expires,
            'signature' => $this->generateSignature((string) $user->getKey(), $emailHash, $expires),
            'signature_v' => self::SIGNATURE_VERSION,
        ]);

        return $this->buildAbsoluteApiUrl($relativePath . '?' . $query);
    }

    public function emailHashForUser(User $user): string
    {
        return sha1(Str::lower((string) $user->email));
    }

    public function hasExpectedHash(User $user, string $hash): bool
    {
        return hash_equals($this->emailHashForUser($user), $hash);
    }

    public function resolveExpiryTimestamp(mixed $expires): ?int
    {
        $normalized = filter_var($expires, FILTER_VALIDATE_INT);

        return $normalized === false ? null : $normalized;
    }

    public function isExpired(?int $expires): bool
    {
        return $expires === null || $expires < now()->timestamp;
    }

    public function hasValidSignature(User $user, string $hash, ?int $expires, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->isExpired($expires)) {
            return false;
        }

        foreach ($this->signingKeys() as $key) {
            $expectedSignature = $this->generateSignature((string) $user->getKey(), $hash, $expires, $key);

            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeExpiryTimestamp(DateTimeInterface|int|null $expiresAt = null): int
    {
        if ($expiresAt instanceof DateTimeInterface) {
            return $expiresAt->getTimestamp();
        }

        if (is_int($expiresAt)) {
            return $expiresAt;
        }

        return now()->addDays(7)->timestamp;
    }

    protected function generateSignature(string $userId, string $hash, int $expires, ?string $signingKey = null): string
    {
        $payload = implode('|', [
            $userId,
            Str::lower($hash),
            $expires,
        ]);

        return hash_hmac('sha256', $payload, $signingKey ?? $this->signingKey());
    }

    protected function signingKey(): string
    {
        return $this->normalizeConfiguredKey(Config::get('app.key'));
    }

    /**
     * @return array<int, string>
     */
    protected function signingKeys(): array
    {
        $configuredKeys = array_filter([
            Config::get('app.key'),
            ...((array) Config::get('app.previous_keys', [])),
        ], static fn (mixed $key): bool => is_string($key) && $key !== '');

        return array_values(array_unique(array_map(
            fn (mixed $key): string => $this->normalizeConfiguredKey($key),
            $configuredKeys,
        )));
    }

    protected function normalizeConfiguredKey(mixed $configuredKey): string
    {
        $configuredKey = is_string($configuredKey) ? $configuredKey : '';

        if (Str::startsWith($configuredKey, 'base64:')) {
            $decodedKey = base64_decode(Str::after($configuredKey, 'base64:'), true);

            if ($decodedKey !== false) {
                return $decodedKey;
            }
        }

        return $configuredKey;
    }

    protected function buildAbsoluteApiUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $apiBaseUrl = rtrim((string) Config::get('app.url', 'https://api.reprodashboard.com'), '/');

        return $apiBaseUrl . '/' . ltrim($path, '/');
    }
}
