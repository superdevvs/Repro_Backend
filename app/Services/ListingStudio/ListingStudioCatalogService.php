<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Services\Studio\VirtualStagingOptions;

class ListingStudioCatalogService
{
    public const HISTORICAL_PLANS = [
        ['code' => 'plan_100', 'name' => '$100 plan'],
        ['code' => 'plan_200', 'name' => '$200 plan'],
        ['code' => 'plan_275', 'name' => '$275 plan'],
    ];

    public const HISTORICAL_SERVICES = [
        ['code' => 'reel_generation', 'name' => 'Social media reels'],
        ['code' => 'virtual_staging', 'name' => 'Virtual staging'],
        ['code' => 'listing_description', 'name' => 'Listing descriptions'],
        ['code' => 'exclusive_listings', 'name' => 'Exclusive listings'],
        ['code' => 'digital_enhancements', 'name' => 'Digital enhancements and decluttering'],
        ['code' => 'rush_delivery', 'name' => 'Rush delivery'],
    ];

    public function plans(): array
    {
        $plans = [];
        foreach (config('listing-studio.plans', []) as $code => $plan) {
            $plans[] = ['code' => $code, 'name' => $plan['name'],
                'monthlyPriceCents' => (int) $plan['price_cents'],
                'monthlyCreditCents' => (int) $plan['monthly_credit_cents'],
                'revisionDiscountPercent' => (int) ($plan['revision_discount_percent'] ?? 0)];
        }

        return $plans;
    }

    public function version(): string
    {
        return 'studio-v1-'.substr(hash('sha256', json_encode([config('listing-studio.services'), $this->plans(),
            VirtualStagingOptions::ROOMS, VirtualStagingOptions::STYLES], JSON_THROW_ON_ERROR)), 0, 24);
    }

    public function present(): array
    {
        $services = [];
        foreach (config('listing-studio.services', []) as $id => $service) {
            $ai = $this->available('ai_edit', $id);
            $revision = $this->available('human_revision', $id);
            $services[] = ['id' => $id, 'name' => $service['name'],
                'aiPriceCents' => $service['ai_price_cents'], 'aiCreditCents' => $service['ai_price_cents'],
                'revisionBasePriceCents' => $service['revision_price_cents'],
                'revisionBaseCreditCents' => $service['revision_price_cents'],
                'aiAvailable' => $ai, 'revisionAvailable' => $revision,
                'unavailableReason' => $ai && $revision ? null : 'service_unavailable'];
        }

        return ['version' => 'catalog-v1', 'currency' => 'usd', 'creditScale' => 100,
            'services' => $services, 'plans' => $this->plans(),
            'rules' => ['creditRollover' => false, 'mixedFunding' => false, 'photosPerAiOrder' => 1],
            'checkoutAvailable' => $this->checkoutAvailable(), 'dashboardPath' => '/listing-studio',
            'uploadConstraints' => ['mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                'maxFileBytes' => 10485760, 'maxPixels' => 40000000, 'maxFiles' => 1],
            'stagingOptions' => ['rooms' => VirtualStagingOptions::ROOMS, 'styles' => VirtualStagingOptions::STYLES,
                'defaults' => ['roomType' => 'living', 'style' => 'modern']],
            'revisionConstraints' => ['maxInstructionsLength' => 4000]];
    }

    public function available(string $kind, string $service): bool
    {
        return (bool) config('listing-studio.paid_enabled', false)
            && array_key_exists($service, config('listing-studio.services', []))
            && ($kind === 'human_revision' ? (bool) config('listing-studio.human_revisions_enabled', false)
                : ($kind === 'ai_edit' && (bool) config('listing-studio.paid_services.'.$service, false) && $this->aiReady($service)));
    }

    protected function aiReady(string $service): bool
    {
        if (! class_exists(ListingStudioPaidRecipe::class)) {
            return false;
        }
        try {
            return (ListingStudioPaidRecipe::readiness($service)['ready'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkoutAvailable(): bool
    {
        return (bool) config('listing-studio.paid_enabled', false)
            && (bool) config('listing-studio.paid_checkout_enabled', false)
            && (bool) config('listing-studio.stripe_enabled', false);
    }

    public function assertAvailable(string $kind, string $service): void
    {
        if (! $this->available($kind, $service)) {
            throw new PublicApiException('This editing service is temporarily unavailable.', 'service_unavailable', 503);
        }
    }

    public function price(string $kind, string $service, ?string $planCode): array
    {
        $serviceConfig = config('listing-studio.services.'.$service);
        if (! is_array($serviceConfig) || ! in_array($kind, ['ai_edit', 'human_revision'], true)) {
            throw new PublicApiException('Choose a supported editing service.', 'validation_failed', 422);
        }
        $base = (int) $serviceConfig[$kind === 'ai_edit' ? 'ai_price_cents' : 'revision_price_cents'];
        $percent = $kind === 'human_revision' && $planCode
            ? (int) config('listing-studio.plans.'.$planCode.'.revision_discount_percent', 0) : 0;
        $discount = intdiv($base * $percent + 50, 100);

        return ['currency' => 'usd', 'basePriceCents' => $base, 'discountPercent' => $percent,
            'discountCents' => $discount, 'totalPriceCents' => $base - $discount,
            'creditCostCents' => $base - $discount, 'planCode' => $planCode, 'catalogVersion' => $this->version()];
    }

    public function legacy(): array
    {
        return ['plans' => [...array_map(fn ($plan) => ['code' => $plan['code'], 'name' => $plan['name'],
            'reference_price_usd' => $plan['monthlyPriceCents'] / 100], $this->plans()),
            ['code' => 'custom', 'name' => 'Custom plan', 'reference_price_usd' => null]],
            'services' => array_map(fn ($item) => ['code' => $item['id'], 'name' => $item['name']], $this->present()['services']),
            'historical_plans' => self::HISTORICAL_PLANS, 'historical_services' => self::HISTORICAL_SERVICES];
    }
}
