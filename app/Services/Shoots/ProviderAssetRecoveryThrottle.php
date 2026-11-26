<?php

namespace App\Services\Shoots;

use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bounded retry schedule for provider floorplan imports that keep failing.
 *
 * Recovery sweeps run every few minutes. Without a memory of earlier attempts,
 * an asset the provider never serves (an HTML page or an HTTP error) was
 * re-imported every 30 minutes forever: three job attempts and one failed job
 * per cycle, and the stuck shoots filled the sweep's work limit so newer shoots
 * were never reached. The first import of an asset set is immediate; repeats of
 * the same set wait 1 h, 2 h, 4 h ... up to 24 h, and stop after MAX_ATTEMPTS.
 * New provider links or keys start a fresh schedule.
 *
 * State lives in the database because every deploy clears the application cache.
 */
final class ProviderAssetRecoveryThrottle
{
    public const TABLE = 'provider_asset_recovery_attempts';

    public const FIRST_DELAY_MINUTES = 60;

    public const MAX_DELAY_MINUTES = 1440;

    public const MAX_ATTEMPTS = 10;

    /** The provider gates these assets behind a protected view and no access token is available. */
    public const OUTCOME_PROTECTED = 'provider-protected';

    /** @param array<int, mixed> $assets */
    public static function fingerprint(array $assets): string
    {
        $pairs = [];
        foreach ($assets as $asset) {
            if (is_array($asset) && ! empty($asset['asset_key']) && ! empty($asset['url'])) {
                $pairs[] = [(string) $asset['asset_key'], (string) $asset['url']];
            }
        }
        sort($pairs);

        return hash('sha256', json_encode($pairs));
    }

    public static function scopeKey(string $channel, int $shootId, ?int $shootServiceId): string
    {
        return $channel.':'.$shootId.':'.($shootServiceId ?: 0);
    }

    /** @return array<string, object> Current schedules for one channel, keyed by scope key. */
    public function load(string $channel, ?int $shootId = null): array
    {
        return DB::table(self::TABLE)
            ->where('channel', $channel)
            ->when($shootId !== null, fn ($query) => $query->where('shoot_id', $shootId))
            ->get()->keyBy('scope_key')->all();
    }

    public function find(string $channel, int $shootId, ?int $shootServiceId): ?object
    {
        return DB::table(self::TABLE)->where('scope_key', self::scopeKey($channel, $shootId, $shootServiceId))->first();
    }

    /** @param array<int, mixed> $assets */
    public function status(?object $state, array $assets): string
    {
        if (! $state || ! hash_equals((string) $state->fingerprint, self::fingerprint($assets))) {
            return 'due';
        }
        if ($state->gave_up_at !== null) {
            return 'abandoned';
        }

        return Carbon::parse($state->next_attempt_at)->lte(now()) ? 'due' : 'deferred';
    }

    /** @param array<int, mixed> $assets */
    public function allows(?object $state, array $assets): bool
    {
        return $this->status($state, $assets) === 'due';
    }

    /**
     * Record that an import for this asset set was just queued.
     *
     * @param  array<int, mixed>  $assets
     */
    public function record(string $channel, int $shootId, ?int $shootServiceId, array $assets, ?object $state): void
    {
        $now = now();
        $fingerprint = self::fingerprint($assets);
        $repeat = $state && hash_equals((string) $state->fingerprint, $fingerprint);
        $attempts = $repeat ? (int) $state->attempts + 1 : 1;
        $delay = min(self::MAX_DELAY_MINUTES, self::FIRST_DELAY_MINUTES * (2 ** ($attempts - 1)));
        $row = [
            'scope_key' => self::scopeKey($channel, $shootId, $shootServiceId),
            'channel' => $channel,
            'shoot_id' => $shootId,
            'shoot_service_id' => $shootServiceId ?: null,
            'fingerprint' => $fingerprint,
            'attempts' => $attempts,
            'first_attempt_at' => $repeat ? $state->first_attempt_at : $now,
            'last_attempt_at' => $now,
            'next_attempt_at' => $now->copy()->addMinutes($delay),
            'gave_up_at' => $attempts >= self::MAX_ATTEMPTS ? $now : null,
            'outcome' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        LockedWrite::run(fn () => DB::table(self::TABLE)->upsert([$row], ['scope_key'], [
            'fingerprint', 'attempts', 'first_attempt_at', 'last_attempt_at', 'next_attempt_at', 'gave_up_at', 'outcome', 'updated_at',
        ]), 'provider-assets.recovery-attempt');
    }

    /**
     * Stop retrying a shoot's provider assets now: the provider gates them behind a protected view
     * and no access token can be obtained. New provider links (a new fingerprint) start over.
     */
    public function markProtected(int $shootId, string $channel = 'recovery-iguide'): void
    {
        $now = now();
        LockedWrite::run(fn () => DB::table(self::TABLE)->where('channel', $channel)->where('shoot_id', $shootId)->update([
            'gave_up_at' => $now, 'next_attempt_at' => $now, 'outcome' => self::OUTCOME_PROTECTED, 'updated_at' => $now,
        ]), 'provider-assets.recovery-protected');
    }

    /** Forget a schedule once every asset in its scope exists locally. */
    public function clear(string $scopeKey): void
    {
        LockedWrite::run(fn () => DB::table(self::TABLE)->where('scope_key', $scopeKey)->delete(), 'provider-assets.recovery-clear');
    }
}
