<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ListingStudio\ListingStudioCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStudioLegacyBillingCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_catalog_roles_receive_canonical_billing_without_losing_legacy_fields_or_financial_mutations(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['listing-studio.paid_enabled' => false, 'listing-studio.paid_checkout_enabled' => false]);
        $legacy = app(ListingStudioCatalogService::class)->legacy();
        foreach (['admin', 'superadmin', 'client', 'salesRep', 'rep'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $response = $this->getJson('/api/listing-studio/catalog')->assertOk()
                ->assertJsonPath('data.billing.version', 'catalog-v1')
                ->assertJsonPath('data.billing.currency', 'usd')
                ->assertJsonPath('data.billing.creditScale', 100)
                ->assertJsonPath('data.billing.rules.creditRollover', false)
                ->assertJsonPath('data.billing.rules.mixedFunding', false)
                ->assertJsonPath('data.billing.checkoutAvailable', false);
            foreach ($legacy as $field => $value) {
                $response->assertJsonPath('data.'.$field, $value);
            }
            $plans = collect($response->json('data.billing.plans'))->keyBy('code');
            foreach (['starter' => [4900, 6000, 0], 'pro' => [9900, 13500, 20], 'studio' => [24900, 37500, 30]] as $code => [$price, $credit, $discount]) {
                $this->assertSame($price, $plans[$code]['monthlyPriceCents']);
                $this->assertSame($credit, $plans[$code]['monthlyCreditCents']);
                $this->assertSame($discount, $plans[$code]['revisionDiscountPercent']);
            }
            $services = collect($response->json('data.billing.services'))->keyBy('id');
            foreach (['staging' => [1500, 3000], 'decluttering' => [1200, 1500], 'grass' => [700, 1500], 'twilight' => [1000, 1500]] as $id => [$ai, $revision]) {
                $this->assertSame($ai, $services[$id]['aiPriceCents']);
                $this->assertSame($ai, $services[$id]['aiCreditCents']);
                $this->assertSame($revision, $services[$id]['revisionBasePriceCents']);
                $this->assertSame($revision, $services[$id]['revisionBaseCreditCents']);
            }
        }
        foreach (['listing_studio_requests', 'listing_studio_subscriptions', 'listing_studio_credit_entries',
            'listing_studio_orders', 'listing_studio_quotes', 'listing_studio_checkout_attempts', 'listing_studio_billing_requests'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' changed during catalog reads.');
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_catalog_extension_does_not_expand_roles_or_grant_admin_delegated_purchase_access(): void
    {
        config(['listing-studio.delegated_enabled' => true]);
        foreach (['editing_manager', 'editor', 'photographer'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/listing-studio/catalog')->assertForbidden();
        }
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/listing-studio/staff/clients/'.$client->id.'/billing-requests', [])->assertForbidden();
        $this->getJson('/api/listing-studio/client/account')->assertForbidden();
        $this->assertDatabaseCount('listing_studio_billing_requests', 0);
    }
}
