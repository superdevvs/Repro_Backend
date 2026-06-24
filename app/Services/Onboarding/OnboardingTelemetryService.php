<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingEvent;
use App\Models\User;
use App\Services\Users\DashboardOnboardingService;
use Illuminate\Support\Facades\Log;

class OnboardingTelemetryService
{
    /**
     * Allowed onboarding telemetry event types.
     */
    public const EVENT_TYPES = [
        'started',
        'step_viewed',
        'step_back',
        'completed',
        'skipped',
        'replayed',
        'help_opened',
        'help_message',
    ];

    public function __construct(
        private readonly DashboardOnboardingService $onboarding,
    ) {
    }

    /**
     * Record a single onboarding event for the given user.
     *
     * Validates the event_type enum, that the user's role is an onboarded role,
     * and that the supplied onboarding_key (if any) matches the role's canonical
     * key. Throws InvalidArgumentException on a contract violation so callers can
     * surface a 422; persistence failures are swallowed (resilient writes) and
     * surfaced as null.
     *
     * @throws \InvalidArgumentException when the event fails validation
     */
    public function record(User $user, array $event): ?OnboardingEvent
    {
        $role = $user->role;

        if (!$this->onboarding->isOnboardedRole($role)) {
            throw new \InvalidArgumentException("Role [{$role}] is not an onboarded role.");
        }

        $eventType = $event['event_type'] ?? null;
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid onboarding event_type [{$eventType}].");
        }

        $canonicalKey = $this->onboarding->keyForRole($role);
        $providedKey = $event['onboarding_key'] ?? null;
        if ($providedKey !== null && $providedKey !== '' && $providedKey !== $canonicalKey) {
            throw new \InvalidArgumentException(
                "onboarding_key [{$providedKey}] does not match role [{$role}] (expected [{$canonicalKey}])."
            );
        }

        $version = $event['version'] ?? $this->onboarding->versionForRole($role);

        try {
            return OnboardingEvent::create([
                'user_id' => $user->id,
                'role' => $role,
                'onboarding_key' => $canonicalKey,
                'version' => $version !== null ? (int) $version : null,
                'event_type' => $eventType,
                'step_index' => isset($event['step_index']) && $event['step_index'] !== null
                    ? (int) $event['step_index']
                    : null,
                'step_target' => $event['step_target'] ?? null,
                'session_uuid' => $event['session_uuid'] ?? null,
                'source' => $event['source'] ?? null,
                'meta' => $event['meta'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Telemetry writes must never break the caller. Log and move on.
            Log::warning('Failed to record onboarding event', [
                'user_id' => $user->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a batch of events. Returns the number successfully persisted.
     * Individual invalid events are skipped without aborting the batch.
     */
    public function recordBatch(User $user, array $events): int
    {
        $recorded = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            try {
                if ($this->record($user, $event) !== null) {
                    $recorded++;
                }
            } catch (\InvalidArgumentException $e) {
                Log::debug('Skipped invalid onboarding event in batch', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $recorded;
    }
}
