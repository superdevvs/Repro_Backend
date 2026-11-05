<?php

namespace Tests\MySql\Support;

use App\Contracts\ListingStudio\ListingStudioOutcomeGuard;
use App\Contracts\ListingStudio\ListingStudioSourceResolver;
use App\Models\ListingStudioOrderItem;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function configureStudioFinancialRace(): void
{
    if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'repro_mysql_test'
        || DB::connection()->getConfig('username') !== 'repro_test') {
        throw new \RuntimeException('Financial race fixtures require the isolated MySQL test account.');
    }
    config(['logging.default' => 'null', 'mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array',
        'listing-studio.paid_enabled' => true, 'listing-studio.paid_checkout_enabled' => true,
        'listing-studio.stripe_enabled' => true, 'listing-studio.paid_services.grass' => true,
        'listing-studio.max_unpaid_orders_per_client' => 1]);
    Http::preventStrayRequests();
    \Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
    \Carbon\CarbonImmutable::setTestNow('2026-10-31 12:00:00');
    app()->instance(ListingStudioCatalogService::class, new class extends ListingStudioCatalogService
    {
        protected function aiReady(string $service): bool
        {
            return true;
        }

        public function checkoutAvailable(): bool
        {
            return true;
        }
    });
    // Keep the real quote, ledger, order and outbox services. Only the external
    // provider/source boundary is replaced; no image or payment provider runs.
    app()->instance(ListingStudioSourceResolver::class, new class implements ListingStudioSourceResolver
    {
        public function resolve(User $client, string $kind, string $sourceKind, string $sourceId): array
        {
            $media = ['disk' => 'listing_studio_orders', 'path' => 'uploads/'.$sourceId.'/source.jpg',
                'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'width' => 1600, 'height' => 1000, 'byteSize' => 4000];

            return ['kind' => $sourceKind, 'id' => $sourceId, 'clientId' => $client->id, 'service' => null,
                'expiresAt' => now()->addDay()->toIso8601String(), ...$media, 'original' => $media, 'preview' => $media];
        }

        public function pin(array $snapshot, string $itemId): void {}
    });
    app()->instance(ListingStudioOutcomeGuard::class, new class implements ListingStudioOutcomeGuard
    {
        public function assertDefinitiveStop(ListingStudioOrderItem $item): void {}

        public function assertDeliverableOutput(ListingStudioOrderItem $item, array $descriptor): void {}
    });
}

function configureStudioRevisionRace(): void
{
    configureStudioFinancialRace();
    config(['listing-studio.ai_revisions_enabled' => true]);
    app()->instance(\App\Services\Studio\StudioProviderSettings::class, new class extends \App\Services\Studio\StudioProviderSettings
    {
        public function route(string $capability, ?array $stored = null): array
        {
            return ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit'];
        }

        public function readiness(string $capability, array $route, ?array $stored = null): array
        {
            return ['ready' => true];
        }
    });
    app()->instance(\App\Services\ListingStudio\ListingStudioPaidMedia::class, new class extends \App\Services\ListingStudio\ListingStudioPaidMedia
    {
        public function read(array $descriptor): string
        {
            return 'media-boundary-fixture';
        }
    });
}
