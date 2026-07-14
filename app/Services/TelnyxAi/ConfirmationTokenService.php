<?php

namespace App\Services\TelnyxAi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConfirmationTokenService
{
    public function issue(string $tool, array $params, string $summary, ?int $voiceCallId = null): array
    {
        $token = Str::random(48);
        $ttl = (int) config('services.telnyx.ai_pending_action_ttl_minutes', 10);

        Cache::put($this->key($token), [
            'tool' => $tool,
            'params' => $params,
            'summary' => $summary,
            'voice_call_id' => $voiceCallId,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes($ttl));

        return [
            'confirmation_token' => $token,
            'summary' => $summary,
            'expires_in_seconds' => $ttl * 60,
        ];
    }

    public function resolve(string $token, string $tool, ?int $voiceCallId = null): ?array
    {
        $key = $this->key($token);
        $payload = Cache::get($key);

        if (
            ! is_array($payload)
            || ($payload['tool'] ?? null) !== $tool
            || ($payload['voice_call_id'] ?? null) !== $voiceCallId
        ) {
            return null;
        }

        return $payload;
    }

    public function storedResult(string $token): ?array
    {
        $payload = Cache::get($this->key($token));
        $result = is_array($payload) ? ($payload['result'] ?? null) : null;

        return is_array($result) ? $result : null;
    }

    public function storeResult(string $token, array $result): void
    {
        $key = $this->key($token);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            return;
        }

        $payload['result'] = $result;
        $payload['consumed_at'] = now()->toIso8601String();
        $ttl = (int) config('services.telnyx.ai_pending_action_ttl_minutes', 10);
        Cache::put($key, $payload, now()->addMinutes($ttl));
    }

    public function executionKey(string $token): string
    {
        return 'confirmation:'.hash('sha256', $token);
    }

    private function key(string $token): string
    {
        return 'telnyx-ai:tool-confirmation:'.hash('sha256', $token);
    }
}
