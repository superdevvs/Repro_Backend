<?php

namespace App\Services\Users;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TwoFactorAuthenticationService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const SETUP_TTL_MINUTES = 10;

    public function enabled(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }

    /**
     * @return array{secret:string, otpauth_uri:string, expires_in_seconds:int}
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->base32Encode(random_bytes(20));
        Cache::put($this->setupCacheKey($user), $secret, now()->addMinutes(self::SETUP_TTL_MINUTES));

        $issuer = (string) config('app.name', 'Repro Photos');
        $label = rawurlencode($issuer.':'.strtolower((string) $user->email));
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'secret' => $secret,
            'otpauth_uri' => "otpauth://totp/{$label}?{$query}",
            'expires_in_seconds' => self::SETUP_TTL_MINUTES * 60,
        ];
    }

    /**
     * @return list<string> Plain-text recovery codes. They are returned once and never stored as plain text.
     */
    public function confirmSetup(User $user, string $code): array
    {
        $secret = Cache::get($this->setupCacheKey($user));
        if (! is_string($secret) || $secret === '') {
            throw new DomainException('This setup has expired. Start two-factor setup again.');
        }

        $acceptedStep = $this->matchingTotpStep($secret, $code);
        if ($acceptedStep === null) {
            throw new DomainException('The authentication code is invalid.');
        }

        [$plainCodes, $hashedCodes] = $this->makeRecoveryCodes();
        $confirmedAt = now();

        DB::transaction(function () use ($user, $secret, $hashedCodes, $confirmedAt, $acceptedStep): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($this->enabled($lockedUser)) {
                throw new DomainException('Two-factor authentication is already enabled.');
            }

            $lockedUser->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => $hashedCodes,
                'two_factor_confirmed_at' => $confirmedAt,
                'two_factor_last_used_step' => $acceptedStep,
            ])->save();
        });

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $hashedCodes,
            'two_factor_confirmed_at' => $confirmedAt,
            'two_factor_last_used_step' => $acceptedStep,
        ]);

        Cache::forget($this->setupCacheKey($user));

        return $plainCodes;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        [$plainCodes, $hashedCodes] = $this->makeRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashedCodes])->save();

        return $plainCodes;
    }

    public function disable(User $user): void
    {
        Cache::forget($this->setupCacheKey($user));
        // Code verification may have consumed a TOTP step through a separately
        // locked model instance. Refresh so clearing that step is persisted too.
        $user->refresh();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }

    public function verifyUserCode(User $user, string $code, bool $consumeRecoveryCode = true): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }

        $normalized = $this->normalizeCode($code);
        if (preg_match('/^\d{6}$/', $normalized)) {
            // TOTP values are always consumed. A caller may opt out of consuming a
            // recovery code for a read-only check, but an accepted time-step must
            // never be reusable for a second authentication decision.
            return $this->consumeTotpStep($user, $normalized);
        }

        $candidateHash = $this->hashRecoveryCode($normalized);
        if (! $consumeRecoveryCode) {
            return $this->recoveryCodeIndex($user->two_factor_recovery_codes, $candidateHash) !== null;
        }

        return DB::transaction(function () use ($user, $candidateHash) {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser) {
                return false;
            }

            $recoveryCodes = $lockedUser->two_factor_recovery_codes;
            $index = $this->recoveryCodeIndex($recoveryCodes, $candidateHash);
            if ($index === null) {
                return false;
            }

            unset($recoveryCodes[$index]);
            $remainingCodes = array_values($recoveryCodes);
            $lockedUser->forceFill(['two_factor_recovery_codes' => $remainingCodes])->save();
            $user->forceFill(['two_factor_recovery_codes' => $remainingCodes]);

            return true;
        });
    }

    public function currentCodeForSecret(string $secret, ?int $timestamp = null): string
    {
        return $this->codeForStep($secret, intdiv($timestamp ?? now()->getTimestamp(), 30));
    }

    private function consumeTotpStep(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser || ! $this->enabled($lockedUser)) {
                return false;
            }

            $acceptedStep = $this->matchingTotpStep((string) $lockedUser->two_factor_secret, $code);
            if ($acceptedStep === null) {
                return false;
            }

            $lastUsedStep = $lockedUser->two_factor_last_used_step;
            if ($lastUsedStep !== null && $acceptedStep <= (int) $lastUsedStep) {
                return false;
            }

            $lockedUser->forceFill(['two_factor_last_used_step' => $acceptedStep])->save();
            $user->forceFill(['two_factor_last_used_step' => $acceptedStep]);

            return true;
        });
    }

    private function matchingTotpStep(string $secret, string $code): ?int
    {
        $normalized = $this->normalizeCode($code);
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return null;
        }

        $currentStep = intdiv(now()->getTimestamp(), 30);
        foreach ([-1, 0, 1] as $offset) {
            $candidateStep = $currentStep + $offset;
            if (hash_equals($this->codeForStep($secret, $candidateStep), $normalized)) {
                return $candidateStep;
            }
        }

        return null;
    }

    private function codeForStep(string $secret, int $counter): string
    {
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $this->normalizeCode($code), (string) config('app.key'));
    }

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private function makeRecoveryCodes(): array
    {
        $plainCodes = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->values()
            ->all();

        return [
            $plainCodes,
            array_map(fn (string $value) => $this->hashRecoveryCode($value), $plainCodes),
        ];
    }

    private function recoveryCodeIndex(mixed $codes, string $candidateHash): ?int
    {
        if (! is_array($codes)) {
            return null;
        }

        foreach ($codes as $index => $storedHash) {
            if (is_string($storedHash) && hash_equals($storedHash, $candidateHash)) {
                return (int) $index;
            }
        }

        return null;
    }

    private function setupCacheKey(User $user): string
    {
        return 'profile-security:two-factor-setup:'.$user->getKey();
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($value, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('The authenticator secret is invalid.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
