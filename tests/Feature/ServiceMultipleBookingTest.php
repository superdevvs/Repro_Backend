<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\UploadIntakeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceMultipleBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->service = Service::factory()->create([
            'price' => 100, 'allow_multiple' => true, 'pricing_type' => 'fixed',
            'photographer_required' => false, 'photographer_pay' => 40,
        ]);
        Sanctum::actingAs($this->client);
    }

    private function booking(array $extra = []): array
    {
        return array_merge([
            'address' => '100 Main Street', 'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201',
            'services' => [['id' => $this->service->id, 'quantity' => 3]],
        ], $extra);
    }

    private function createBooking(): Shoot
    {
        $id = $this->postJson('/api/shoots', $this->booking())->assertCreated()->json('data.id');
        return Shoot::findOrFail($id);
    }

    public function test_service_toggle_persists_and_is_returned_by_catalog_endpoints(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/admin/services', [
            'name' => 'Extra photos', 'price' => 25, 'delivery_time' => 24,
            'category_id' => Category::factory()->create()->id, 'allow_multiple' => true,
        ])->assertOk()->assertJsonPath('service.allow_multiple', true)->json('service.id');
        $this->getJson('/api/services/'.$id)->assertOk()->assertJsonPath('service.allow_multiple', true);
        $this->putJson('/api/admin/services/'.$id, ['description' => 'Retain toggle'])
            ->assertOk()->assertJsonPath('data.allow_multiple', true);
        $this->putJson('/api/admin/services/'.$id, ['allow_multiple' => false])
            ->assertOk()->assertJsonPath('data.allow_multiple', false);
        $this->assertFalse(Service::findOrFail($id)->allow_multiple);
    }

    public function test_booking_edit_and_approval_round_trip_quantity_and_server_totals(): void
    {
        $shoot = $this->createBooking();
        $this->assertSame(300.0, (float) $shoot->base_quote);
        $lineId = $shoot->serviceItems()->firstOrFail()->id;
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'services' => [['id' => $this->service->id, 'quantity' => 4]],
        ])->assertOk()->assertJsonPath('data.service_items.0.quantity', 4)
            ->assertJsonPath('data.service_items.0.allow_multiple', true)
            ->assertJsonPath('data.services.0.allow_multiple', true);
        $this->assertSame(400.0, (float) $shoot->fresh()->base_quote);
        $this->postJson('/api/shoots/'.$shoot->id.'/approve', [
            'service_items' => [['service_id' => $this->service->id, 'quantity' => 2]],
            'scheduled_at' => '2026-10-05 10:00:00', 'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertSame(200.0, (float) $shoot->fresh()->base_quote);
        $line = $shoot->serviceItems()->firstOrFail();
        $this->assertSame($lineId, $line->id);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(200.0, $line->subtotal);
    }

    public function test_disabled_option_rejects_new_multiple_bookings_in_both_payload_shapes(): void
    {
        $this->service->update(['allow_multiple' => false]);
        $this->postJson('/api/shoots', $this->booking())->assertUnprocessable()
            ->assertJsonValidationErrors('services.0.quantity');
        $this->postJson('/api/shoots', $this->booking([
            'services' => [['id' => $this->service->id]],
            'service_items' => [['service_id' => $this->service->id, 'quantity' => 3]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('services.0.quantity');
        $this->assertSame(0, Shoot::count());
        $this->postJson('/api/shoots', $this->booking([
            'services' => [['id' => $this->service->id, 'quantity' => 1]],
        ]))->assertCreated()->assertJsonPath('data.services.0.quantity', 1);
    }

    public function test_repeated_catalog_ids_cannot_replace_a_quantity_or_overcharge_a_single_saved_line(): void
    {
        $this->postJson('/api/shoots', $this->booking([
            'services' => [['id' => $this->service->id], ['id' => $this->service->id]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('services.0.id');
        $this->postJson('/api/shoots', $this->booking([
            'service_items' => [
                ['service_id' => $this->service->id, 'quantity' => 2],
                ['service_id' => $this->service->id, 'quantity' => 3],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('service_items.0.service_id');
        $this->assertSame(0, Shoot::count());
    }

    public function test_disabling_option_preserves_existing_quantity_and_allows_reduction_but_not_increase(): void
    {
        $shoot = $this->createBooking();
        $this->service->update(['allow_multiple' => false, 'price' => 999]);
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $this->service->id]]])
            ->assertOk()->assertJsonPath('data.service_items.0.quantity', 3)
            ->assertJsonPath('data.service_items.0.allow_multiple', false);
        $this->assertSame(300.0, (float) $shoot->fresh()->base_quote);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $this->service->id, 'quantity' => 4]],
        ])->assertUnprocessable()->assertJsonValidationErrors('services.0.quantity');
        $this->assertSame(3, $shoot->serviceItems()->firstOrFail()->quantity);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'service_items' => [['service_id' => $this->service->id, 'quantity' => 2]],
        ])->assertOk();
        $this->assertSame(200.0, (float) $shoot->fresh()->base_quote);
    }

    public function test_multiple_unit_lines_preserve_identity_quantity_and_booked_price_through_edit_and_approval(): void
    {
        $payload = $this->booking();
        unset($payload['services']);
        $payload['units'] = [['client_key' => 'unit-1', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 900]];
        $payload['service_lines'] = [['client_key' => 'line-1', 'unit_client_key' => 'unit-1', 'service_id' => $this->service->id, 'quantity' => 3]];
        $id = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        $shoot = Shoot::findOrFail($id);
        $line = $shoot->serviceItems()->firstOrFail();
        $this->assertSame(300.0, (float) $shoot->base_quote);
        $this->assertSame(3, $line->quantity);
        Sanctum::actingAs($this->admin);
        $this->service->update(['allow_multiple' => false, 'price' => 999]);
        $this->patchJson('/api/shoots/'.$id, [
            'expected_units_revision' => 1,
            'units' => [['id' => $line->shoot_unit_id, 'client_key' => 'unit-1', 'label' => 'Renamed', 'kind' => 'unit', 'sqft' => 900]],
        ])->assertOk()->assertJsonPath('data.service_lines.0.quantity', 3)
            ->assertJsonPath('data.service_lines.0.allow_multiple', false);
        $change = [
            'expected_units_revision' => 2,
            'service_lines' => [['shoot_service_id' => $line->id, 'client_key' => 'line-1', 'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $this->service->id, 'quantity' => 4]],
        ];
        $this->patchJson('/api/shoots/'.$id, $change)->assertUnprocessable()->assertJsonValidationErrors('service_lines.0.quantity');
        $change['service_lines'][0]['quantity'] = 2;
        $this->postJson('/api/shoots/'.$id.'/approve', $change + [
            'scheduled_at' => '2026-10-05 10:00:00', 'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertSame($line->id, $shoot->serviceItems()->firstOrFail()->id);
        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame(100.0, (float) $line->fresh()->price);
        $this->assertSame(200.0, (float) $shoot->fresh()->base_quote);
    }

    public function test_disabled_service_cannot_be_booked_multiple_times_inside_a_unit(): void
    {
        $this->service->update(['allow_multiple' => false]);
        $payload = $this->booking();
        unset($payload['services']);
        $payload['units'] = [['client_key' => 'u', 'label' => 'Unit', 'kind' => 'unit']];
        $payload['service_lines'] = [['client_key' => 'l', 'unit_client_key' => 'u', 'service_id' => $this->service->id, 'quantity' => 2]];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable()->assertJsonValidationErrors('service_lines.0.quantity');
    }

    public function test_known_photo_counts_scale_with_booked_quantity_and_unknown_counts_stay_unknown(): void
    {
        $this->service->update(['photo_count' => 10, 'quantity' => 50, 'uses_hdr_brackets' => true, 'upload_intake_type' => Service::INTAKE_PHOTO]);
        $shoot = $this->createBooking();
        $item = $shoot->serviceItems()->firstOrFail();
        $item->update(['bracket_mode' => 5]);
        $intake = app(UploadIntakeResolver::class);
        $brackets = app(BracketModeResolver::class);
        $this->assertSame(30, $intake->contractedPhotoCount($item->fresh()));
        $this->assertSame(150, $brackets->expectedRawForService($item->fresh()));
        $this->service->update(['photo_count' => null]);
        $this->assertNull($intake->contractedPhotoCount($item->fresh()));
        $this->assertNull($brackets->expectedRawForService($item->fresh()));
        $unit = $shoot->units()->create(['client_key' => 'u', 'label' => 'Unit', 'kind' => 'unit']);
        $item->update(['shoot_unit_id' => $unit->id, 'contracted_photo_count' => 12]);
        $this->assertSame(36, $intake->contractedPhotoCount($item->fresh()));
        $this->assertSame(180, $brackets->expectedRawForService($item->fresh()));
    }
}
