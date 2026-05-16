<?php

namespace App\Services\TelnyxAi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConfirmationTokenService
{
    public function issue(string $tool, array $params, string $summary): array
    {
        $token = Str::random(48);
        $ttl = (int) config('services.telnyx.ai_pending_action_ttl_minutes', 10);

        Cache::put($this->key($token), [
            'tool' => $tool,
            'params' => $params,
            'summary' => $summary,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes($ttl));

        return [
            'confirmation_token' => $token,
            'summary' => $summary,
            'expires_in_seconds' => $ttl * 60,
        ];
    }

    public function consume(string $token, string $tool): ?array
    {
        $key = $this->key($token);
        $payload = Cache::get($key);

        if (!is_array($payload) || ($payload['tool'] ?? null) !== $tool) {
            return null;
        }

        Cache::forget($key);

        return $payload;
    }

    private function key(string $token): string
    {
        return 'telnyx-ai:tool-confirmation:' . hash('sha256', $token);
    }
}
