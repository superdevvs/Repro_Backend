<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Request-scoped policy. No saved row means the deployment's current policy. */
class SchedulingBufferSettings
{
    public const KEY = 'scheduling.travel_buffers';

    private ?array $saved = null;

    public function current(bool $fresh = false): array
    {
        if ($fresh || $this->saved === null) {
            $raw = Schema::hasTable('settings') ? DB::table('settings')->where('key', self::KEY)->value('value') : null;
            $decoded = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            $this->saved = is_array($decoded) ? $decoded : [];
        }

        return array_replace($this->defaults(), $this->saved);
    }

    public function defaults(): array
    {
        return [
            'mode' => config('availability.hybrid_travel_enabled', false) ? 'google' : 'fixed',
            'fixed_minutes' => (int) config('availability.buffer_time_minutes', 15),
            'minimum_minutes' => 15, 'allowance_minutes' => 5,
            'fallback' => 'mileage', 'near_minutes' => 15, 'medium_minutes' => 30, 'far_minutes' => 45,
        ];
    }

    public function enabled(): bool
    {
        $this->current();

        // Explicit fixed mode also uses the shared evaluator, retaining overlap
        // protection, verified same-building handling and audited exceptions.
        return $this->saved !== [] || (bool) config('availability.hybrid_travel_enabled', false);
    }

    public function version(bool $fresh = false): string
    {
        return hash('sha256', json_encode([$this->current($fresh), $this->enabled()], JSON_THROW_ON_ERROR));
    }
}
