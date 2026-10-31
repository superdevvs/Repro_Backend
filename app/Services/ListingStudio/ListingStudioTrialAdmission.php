<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** A browser proves its connection directly to our origin; the website cannot nominate its IP. */
class ListingStudioTrialAdmission
{
    public const TABLE = 'listing_studio_trial_admissions';

    public function mint(string $sessionHash, string $key): array
    {
        $this->assertEnabled();

        return $this->write(function () use ($sessionHash, $key) {
            $this->assertEnabled();
            // More expired rows leave than new rows enter, even between hourly sweeps.
            $this->prune(25);
            $live = DB::table(self::TABLE)->whereNull('consumed_at')->where('expires_at', '>', now());
            if ((clone $live)->where('session_hash', $sessionHash)->count() >= (int) config('listing-studio-trials.admission_session_limit', 3)
                || $live->count() >= (int) config('listing-studio-trials.admission_global_limit', 1000)) {
                throw new PublicApiException('Please wait a few minutes before starting another upload.', 'trial_admission_limit', 429);
            }
            $token = bin2hex(random_bytes(32));
            $expires = now()->addSeconds((int) config('listing-studio-trials.admission_seconds', 300));
            DB::table(self::TABLE)->insert(['token_hash' => hash('sha256', $token), 'session_hash' => $sessionHash,
                'idempotency_key' => $key, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

            return ['token' => $token, 'expiresAt' => $expires->toIso8601String(),
                'verifyUrl' => 'https://reprodashboard.com/api/listing-studio/trial-admissions/verify'];
        });
    }

    public function verify(string $token, string $challenge, string $ip, string $origin): array
    {
        $this->assertEnabled();
        $ipHash = ListingStudioVisitorIp::hash($ip);
        $rateKey = 'listing-studio-admission:'.$ipHash;
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            throw new PublicApiException('Too many verification attempts. Please wait a minute.', 'trial_rate_limit', 429);
        }
        RateLimiter::hit($rateKey, 60);
        $tokenHash = hash('sha256', $token);
        $challengeHash = hash('sha256', $challenge);
        $admission = $this->write(function () use ($tokenHash, $challengeHash, $ipHash) {
            $this->assertEnabled();
            $row = $this->findLive($tokenHash);
            if ($row->verified_at) {
                if (! hash_equals($row->ip_hash, $ipHash)) {
                    $this->invalid();
                }

                return $row;
            }
            // Retry a lost Siteverify response with its same validation UUID. A newer
            // challenge supersedes an older attempt without admitting its late result.
            if ($row->challenge_hash !== $challengeHash || $row->ip_hash !== $ipHash) {
                DB::table(self::TABLE)->where('token_hash', $tokenHash)->update([
                    'challenge_hash' => $challengeHash, 'ip_hash' => $ipHash,
                    'verification_id' => (string) Str::uuid(), 'updated_at' => now(),
                ]);
                $row = $this->findLive($tokenHash);
            }

            return $row;
        });
        if ($admission->verified_at) {
            return $this->present($admission);
        }
        try {
            $response = Http::asForm()->timeout(15)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('listing-studio-trials.turnstile_secret'), 'response' => $challenge,
                'remoteip' => $ip, 'idempotency_key' => $admission->verification_id,
            ]);
            $data = $response->successful() ? $response->json() : [];
        } catch (\Throwable) {
            throw new PublicApiException('The bot check is temporarily unavailable. Please try again.', 'trial_challenge_unavailable', 503);
        }
        if (! is_array($data) || ($data['success'] ?? false) !== true || ($data['action'] ?? null) !== 'listing_studio_trial'
            || ! in_array($data['hostname'] ?? '', config('listing-studio-trials.turnstile_hosts', []), true)
            || 'https://'.($data['hostname'] ?? '') !== $origin) {
            throw new PublicApiException('Please complete the bot check and try again.', 'trial_challenge_failed', 422);
        }

        return $this->write(function () use ($tokenHash, $admission, $challengeHash, $ipHash) {
            $this->assertEnabled();
            $row = $this->findLive($tokenHash);
            if ($row->verification_id !== $admission->verification_id || $row->challenge_hash !== $challengeHash || $row->ip_hash !== $ipHash) {
                $this->invalid();
            }
            DB::table(self::TABLE)->where('token_hash', $tokenHash)->update(['verified_at' => $row->verified_at ?? now(), 'updated_at' => now()]);

            return $this->present($row);
        });
    }

    /** Call within the existing trial guard transaction; any quota failure rolls this back. */
    public function consume(string $token, string $sessionHash, string $key, string $trialId): string
    {
        $row = $this->findLive(hash('sha256', $token));
        if (! $row->verified_at || ! $row->ip_hash || $row->consumed_at
            || ! hash_equals($row->session_hash, $sessionHash) || ! hash_equals($row->idempotency_key, $key)) {
            $this->invalid();
        }
        $updated = DB::table(self::TABLE)->where('token_hash', $row->token_hash)->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'trial_id' => $trialId, 'updated_at' => now()]);
        if ($updated !== 1) {
            $this->invalid();
        }

        return $row->ip_hash;
    }

    public function prune(int $limit): int
    {
        $expired = DB::table(self::TABLE)->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->pluck('token_hash');

        return DB::table(self::TABLE)->whereIn('token_hash', $expired)->delete();
    }

    private function findLive(string $tokenHash): object
    {
        $row = DB::table(self::TABLE)->where('token_hash', $tokenHash)->lockForUpdate()->first();
        if (! $row) {
            $this->invalid();
        }
        if (Carbon::parse($row->expires_at)->lte(now())) {
            throw new PublicApiException('This upload check expired. Please start the verification again.', 'trial_admission_expired', 410);
        }

        return $row;
    }

    private function assertEnabled(): void
    {
        if (! app(ListingStudioGuestTrialService::class)->enabled()) {
            throw new PublicApiException('Live photo editing is temporarily unavailable. You can still watch the sample walkthrough.', 'trial_unavailable', 503);
        }
    }

    private function invalid(): never
    {
        throw new PublicApiException('Please verify this upload again.', 'trial_admission_invalid', 422);
    }

    private function present(object $row): array
    {
        return ['verified' => true, 'expiresAt' => Carbon::parse($row->expires_at)->toIso8601String()];
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($callback) {
            DB::table('listing_studio_trial_guard')->where('id', 1)->increment('version');

            return $callback();
        }), 'listing-studio-admission');
    }
}
