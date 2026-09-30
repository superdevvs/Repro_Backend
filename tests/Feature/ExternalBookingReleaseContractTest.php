<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalShootRequestedJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Services\Users\AccountCreatedNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalBookingReleaseContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.external_booking.api_key' => 'external-release-test-key']);
        Queue::fake();
        $this->withHeaders(['X-API-Key' => 'external-release-test-key']);
    }

    private function service(): Service
    {
        $service = Service::factory()->create(['price' => 100, 'pricing_type' => 'variable']);
        $service->sqftRanges()->createMany([
            ['sqft_from' => 0, 'sqft_to' => 1500, 'price' => 250],
            ['sqft_from' => 1501, 'sqft_to' => 3000, 'price' => 325],
        ]);

        return $service;
    }

    private function payload(Service $service, array $overrides = []): array
    {
        return array_replace([
            'client_name' => 'Contract Test Client',
            'client_email' => 'contract-client@example.com',
            'client_phone' => '2025550101',
            'address' => '123 Contract Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [['id' => $service->id, 'quantity' => 1]],
            'source' => 'reprophotos.com',
            'external_reference' => '8eeb2693-b19e-4a2b-8591-7d920c8ef641',
            'create_account' => false,
        ], $overrides);
    }

    public static function tierBoundaries(): array
    {
        return [[0, 250], [1500, 250], [1501, 325], [3000, 325]];
    }

    #[DataProvider('tierBoundaries')]
    public function test_supplied_sqft_uses_canonical_tier_for_quote_and_service_line(int $sqft, int $price): void
    {
        $service = $this->service();
        $response = $this->postJson('/api/external/book-shoot', $this->payload($service, [
            'sqft' => $sqft,
            'services' => [['id' => $service->id, 'quantity' => 1, 'price' => 1]],
        ]))->assertCreated();
        $shoot = Shoot::findOrFail($response->json('data.shoot_id'));
        $this->assertEquals($price, $shoot->base_quote);
        $this->assertEquals($price, $shoot->services->first()->pivot->price);
    }

    public function test_invalid_or_ambiguous_tier_rolls_back_without_reserving_reference(): void
    {
        $service = $this->service();
        $payload = $this->payload($service, ['sqft' => 3001]);
        $this->postJson('/api/external/book-shoot', $payload)->assertUnprocessable()->assertJsonValidationErrors('sqft');
        $this->assertDatabaseCount('shoots', 0);
        $this->assertDatabaseCount('external_booking_submissions', 0);
        Queue::assertNotPushed(ProcessExternalShootRequestedJob::class);

        $service->sqftRanges()->create(['sqft_from' => 1500, 'sqft_to' => 2000, 'price' => 290]);
        $this->postJson('/api/external/book-shoot', [...$payload, 'sqft' => 1500])
            ->assertUnprocessable()->assertJsonValidationErrors('sqft');
        $this->assertDatabaseCount('shoots', 0);
        $this->postJson('/api/external/book-shoot', [...$payload, 'sqft' => 1000])->assertCreated();
    }

    public function test_duplicate_reference_replays_original_response_without_notifications_even_after_price_changes(): void
    {
        $service = $this->service();
        $this->mock(AccountCreatedNotificationService::class)->shouldReceive('dispatch')->once()->andReturn([
            'email' => [], 'sms' => [],
        ]);
        $payload = $this->payload($service, ['sqft' => 1501, 'create_account' => true]);
        $first = $this->postJson('/api/external/book-shoot', $payload)->assertCreated();
        $service->sqftRanges()->update(['price' => 999]);
        $second = $this->postJson('/api/external/book-shoot', array_reverse($payload, true))
            ->assertOk()->assertJsonPath('idempotent_replay', true);

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('shoots', 1);
        $this->assertDatabaseCount('external_booking_submissions', 1);
        $this->assertDatabaseHas('external_booking_submissions', [
            'source' => 'reprophotos.com',
            'external_reference' => $payload['external_reference'],
            'shoot_id' => $first->json('data.shoot_id'),
        ]);
        Queue::assertPushed(ProcessExternalShootRequestedJob::class, 1);
        $this->assertSame(1, DB::table('shoot_activity_logs')->where('action', 'shoot_requested')->count());
    }

    public function test_reference_reuse_with_changed_payload_is_conflict(): void
    {
        $payload = $this->payload($this->service(), ['sqft' => 1500]);
        $this->postJson('/api/external/book-shoot', $payload)->assertCreated();
        $this->postJson('/api/external/book-shoot', [...$payload, 'address' => 'Another property'])
            ->assertConflict()->assertJsonValidationErrors('external_reference');
        $this->assertDatabaseCount('shoots', 1);
        Queue::assertPushed(ProcessExternalShootRequestedJob::class, 1);
    }

    public function test_legacy_callers_without_reference_or_sqft_keep_base_pricing(): void
    {
        $payload = $this->payload($this->service());
        unset($payload['external_reference']);
        $first = $this->postJson('/api/external/book-shoot', $payload)->assertCreated();
        $second = $this->postJson('/api/external/book-shoot', $payload)->assertCreated();
        $this->assertNotSame($first->json('data.shoot_id'), $second->json('data.shoot_id'));
        $this->assertEquals(100, Shoot::findOrFail($first->json('data.shoot_id'))->base_quote);
        $this->assertDatabaseCount('external_booking_submissions', 0);
    }

    public function test_fixed_pricing_and_lockbox_notes_are_preserved(): void
    {
        $service = $this->service();
        $service->update(['pricing_type' => 'fixed']);
        $response = $this->postJson('/api/external/book-shoot', $this->payload($service, [
            'sqft' => 9000,
            'notes' => 'Please call first.',
            'lockbox_code' => '0123',
            'lockbox_location' => 'Side entrance',
        ]))->assertCreated();
        $shoot = Shoot::findOrFail($response->json('data.shoot_id'));
        $this->assertEquals(100, $shoot->base_quote);
        $this->assertSame("Please call first.\nLockbox code: 0123\nLockbox location: Side entrance", $shoot->shoot_notes);
        $this->assertSame('0123', $shoot->external_booking_payload['lockbox_code']);
    }

    public function test_invalid_values_are_rejected_before_any_writes(): void
    {
        $payload = $this->payload($this->service());
        $this->postJson('/api/external/book-shoot', [...$payload, 'sqft' => -1, 'external_reference' => str_repeat('x', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors(['sqft', 'external_reference']);
        $this->assertDatabaseCount('shoots', 0);
        $this->assertDatabaseCount('external_booking_submissions', 0);
    }

    public function test_quantity_respects_catalog_multiple_booking_setting(): void
    {
        $service = $this->service();
        $service->update(['allow_multiple' => false]);
        $payload = $this->payload($service, [
            'sqft' => 1500,
            'services' => [['id' => $service->id, 'quantity' => 2]],
        ]);
        $this->postJson('/api/external/book-shoot', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.quantity');
        $this->assertDatabaseCount('shoots', 0);
        $this->assertDatabaseCount('external_booking_submissions', 0);

        $service->update(['allow_multiple' => true]);
        $response = $this->postJson('/api/external/book-shoot', $payload)->assertCreated();
        $shoot = Shoot::findOrFail($response->json('data.shoot_id'));
        $this->assertEquals(500, $shoot->base_quote);
        $this->assertEquals(250, $shoot->services->first()->pivot->price);
        $this->assertSame(2, (int) $shoot->services->first()->pivot->quantity);
    }
}
