<?php

namespace App\Services\Users;

use App\Models\ClientEmailVerificationToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ClientEmailVerificationLinkService
{
    public const SIGNATURE_VERSION = '2';

    public function buildUrl(User $user, DateTimeInterface|int|null $expiresAt = null, array $context = []): string
    {
        $token = $this->issueVerificationToken($user, array_merge($context, [
            'expires_at' => $expiresAt,
        ]));

        return $this->buildUrlForIssuedToken($user, $token);
    }

    public function buildUrlForIssuedToken(User $user, ClientEmailVerificationToken $token): string
    {
        $plainToken = $token->plain_token;

        if (!is_string($plainToken) || trim($plainToken) === '') {
            throw new \RuntimeException('Unable to build a verification URL without a plain verification token.');
        }

        $relativePath = route('api.email-verification.verify', [
            'user' => $user->id,
            'hash' => $token->email_hash,
        ], false);

        return $this->buildAbsoluteApiUrl($relativePath . '?' . http_build_query([
            'token' => $plainToken,
        ]));
    }

    public function issueVerificationToken(User $user, array $context = []): ClientEmailVerificationToken
    {
        $emailSnapshot = Str::lower((string) $user->email);
        $emailHash = $this->emailHashForUser($user);
        $plainToken = Str::random(80);
        $tokenHash = hash('sha256', $plainToken);
        $expires = $this->normalizeExpiryTimestamp($context['expires_at'] ?? null);
        $issuedBy = $context['issued_by'] ?? null;
        $issuedContext = trim((string) ($context['issued_context'] ?? 'system')) ?: 'system';
        $metadata = (array) ($context['metadata'] ?? []);

        $this->supersedeActiveTokens($user, $emailHash);

        $token = ClientEmailVerificationToken::create([
            'user_id' => $user->id,
            'email_snapshot' => $emailSnapshot,
            'email_hash' => $emailHash,
            'token_hash' => $tokenHash,
            'expires_at' => now()->setTimestamp($expires),
            'issued_by' => is_numeric($issuedBy) ? (int) $issuedBy : null,
            'issued_context' => $issuedContext,
            'metadata' => $metadata,
        ]);

        $token->plain_token = $plainToken;

        return $token;
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

    public function consumeVerificationToken(User $user, string $hash, ?string $plainToken): ClientEmailVerificationResult
    {
        if (!is_string($plainToken) || trim($plainToken) === '') {
            return new ClientEmailVerificationResult(false, 'token_missing');
        }

        $token = ClientEmailVerificationToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plainToken))
            ->latest('id')
            ->first();

        if (!$token) {
            return new ClientEmailVerificationResult(false, 'token_invalid');
        }

        if (!hash_equals(Str::lower((string) $token->email_hash), Str::lower($hash))) {
            return new ClientEmailVerificationResult(false, 'hash_mismatch', $token);
        }

        if (!$this->hasExpectedHash($user, $hash)) {
            return new ClientEmailVerificationResult(false, 'hash_mismatch', $token);
        }

        if ($token->isExpired()) {
            return new ClientEmailVerificationResult(false, 'token_expired', $token);
        }

        if ($token->isUsed()) {
            return new ClientEmailVerificationResult(false, 'token_used', $token);
        }

        if ($token->isSuperseded()) {
            return new ClientEmailVerificationResult(false, 'token_superseded', $token);
        }

        $this->markTokenConsumed($token);

        return new ClientEmailVerificationResult(true, 'verified', $token->fresh());
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

    protected function supersedeActiveTokens(User $user, string $emailHash): void
    {
        ClientEmailVerificationToken::query()
            ->where('user_id', $user->id)
            ->where('email_hash', $emailHash)
            ->whereNull('used_at')
            ->whereNull('superseded_at')
            ->update([
                'superseded_at' => now(),
            ]);
    }

    protected function markTokenConsumed(ClientEmailVerificationToken $token): void
    {
        $now = now();

        $token->forceFill([
            'used_at' => $token->used_at ?? $now,
        ])->save();

        ClientEmailVerificationToken::query()
            ->where('user_id', $token->user_id)
            ->where('email_hash', $token->email_hash)
            ->where('id', '!=', $token->id)
            ->whereNull('used_at')
            ->whereNull('superseded_at')
            ->update([
                'superseded_at' => $now,
            ]);
    }
}
