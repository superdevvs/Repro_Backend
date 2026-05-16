<?php

namespace App\Services\Messaging\AiSms;

use App\Models\AiChatSession;
use Carbon\CarbonImmutable;

class SmsConfirmationGate
{
    private const META_KEY = 'pending_action';

    /**
     * @var list<string>
     */
    private const AFFIRMATIVES = ['yes', 'y', 'confirm', 'ok', 'okay', 'sure', 'yep', 'yeah', 'do it', 'go ahead'];

    /**
     * Stash a pending destructive action on the session for the next inbound to confirm.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(AiChatSession $session, string $tool, array $payload, string $summary): array
    {
        $ttl = (int) config('services.telnyx.ai_pending_action_ttl_minutes', 10);
        $expiresAt = CarbonImmutable::now()->addMinutes($ttl);

        $entry = [
            'tool' => $tool,
            'payload' => $payload,
            'summary' => $summary,
            'created_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $meta = $session->meta ?? [];
        $meta[self::META_KEY] = $entry;
        $session->meta = $meta;
        $session->save();

        return $entry;
    }

    /**
     * Return the active (non-expired) pending action, or null.
     *
     * @return array<string, mixed>|null
     */
    public function pending(AiChatSession $session): ?array
    {
        $meta = $session->meta ?? [];
        $entry = $meta[self::META_KEY] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $expiresAt = $entry['expires_at'] ?? null;
        if (is_string($expiresAt) && CarbonImmutable::parse($expiresAt)->isPast()) {
            $this->clear($session);
            return null;
        }

        return $entry;
    }

    public function clear(AiChatSession $session): void
    {
        $meta = $session->meta ?? [];
        if (!array_key_exists(self::META_KEY, $meta)) {
            return;
        }

        unset($meta[self::META_KEY]);
        $session->meta = $meta;
        $session->save();
    }

    /**
     * Strict affirmative match for a one-line SMS reply.
     */
    public function isAffirmative(string $body): bool
    {
        $token = strtolower(trim($body));
        $token = preg_replace('/[\.!\?,;]+$/u', '', $token) ?? $token;

        if ($token === '') {
            return false;
        }

        foreach (self::AFFIRMATIVES as $needle) {
            if ($token === $needle || str_starts_with($token, $needle . ' ')) {
                return true;
            }
        }

        return false;
    }
}
