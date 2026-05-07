<?php

namespace App\Services\Users;

class ClientDashboardOnboardingService
{
    public const VERSION = 1;

    public function applyEligibility(array $metadata, ?string $source = null): array
    {
        $preferences = $metadata['preferences'] ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }

        $onboarding = $preferences['clientDashboardOnboarding'] ?? [];
        if (!is_array($onboarding)) {
            $onboarding = [];
        }

        $preferences['clientDashboardOnboarding'] = array_replace($onboarding, array_filter([
            'eligible' => true,
            'version' => self::VERSION,
            'createdAt' => now()->toISOString(),
            'source' => $source,
        ], fn ($value) => $value !== null));

        $metadata['preferences'] = $preferences;

        return $metadata;
    }
}
