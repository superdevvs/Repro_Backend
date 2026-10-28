<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootCardProjectionContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function editing_manager_cards_preserve_operational_contract_across_scheduled_and_delivered_pages(): void
    {
        $this->freezeTime();
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $photographer = User::factory()->photographer()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $scheduled = $this->createOperationalShoot(Shoot::STATUS_SCHEDULED, $photographer, $editor);
        $delivered = $this->createOperationalShoot(Shoot::STATUS_DELIVERED, $photographer, $editor);
        Sanctum::actingAs($manager);

        foreach (['scheduled' => $scheduled, 'delivered' => $delivered] as $tab => $shoot) {
            $url = '/api/shoots?tab='.$tab.'&per_page=10&include_files=false&include_filters=false&no_cache=true';
            $fullResponse = $this->getJson($url)->assertOk();
            $cardResponse = $this->getJson($url.'&view=card')->assertOk();
            $full = $fullResponse->json('data.0');
            $card = $cardResponse->json('data.0');

            $this->assertSame($shoot->id, $card['id']);
            $this->assertSame($fullResponse->json('meta'), $cardResponse->json('meta'));
            $this->assertArrayNotHasKey('filters', $cardResponse->json('meta'));
            foreach ([
                'status', 'workflow_status', 'scheduled_date', 'time', 'timezone',
                'scheduled_instant', 'schedule_timezone', 'cancellation_fee_window',
                'can_submit_raw', 'can_submit_edits', 'can_view_invoice',
                'can_view_video_editor_context', 'can_finalize_no_media', 'can_remove_all_services',
                'canManageBooking', 'canManageShootActions', 'canAdjustShootPricing',
                'property_details', 'services_list', 'service_presentation', 'services', 'service_items',
                'units_revision', 'editor_assignments', 'payment', 'total_paid', 'total_photographer_pay',
                'service_subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discounted_subtotal',
                'shoot_type', 'reshoot_of_shoot_id', 'root_shoot_id',
                'hold_requested_at', 'hold_requested_by', 'hold_reason',
                'cancellation_requested_at', 'cancellation_reason',
                'company_notes', 'editor_notes', 'photographer_notes', 'admin_verified_at',
            ] as $field) {
                $this->assertArrayHasKey($field, $full, 'Fixture must exercise '.$field);
                $this->assertArrayHasKey($field, $card, 'Compact response must preserve '.$field);
                $this->assertSame($full[$field], $card[$field], $tab.': '.$field);
            }
            $this->assertSame($full['client'], $card['client']);
            $this->assertSame(7, $card['units_revision']);
            $this->assertCount(2, $card['services']);
            $this->assertCount(2, $card['service_items']);
            $this->assertSame(55, $card['service_items'][0]['duration_minutes']);
            $this->assertSame($photographer->id, $card['service_items'][0]['photographer']['id']);
            $this->assertTrue($card['service_items'][0]['force_unlock_delivery']);
            $this->assertNotEmpty($card['scheduled_instant']);
            $this->assertSame('America/New_York', $card['schedule_timezone']);
            $this->assertSame(Arr::except($full['units'][0], ['provider_data']), Arr::except($card['units'][0], ['provider_data']));
            $this->assertSame('ready', $card['units'][0]['provider_data']['iguide_status']);
            $this->assertSame('https://example.test/tour', $card['units'][0]['provider_data']['iguide_tour_url']);
            $this->assertSame(['iguide_status' => 'ready'], $card['units'][0]['provider_data']['lines']['photo']);
            $this->assertSame([], $card['units'][0]['provider_data']['lines']['legacy']);

            // Real provider blobs and duplicate aliases must not accompany every list row.
            $this->assertNotEmpty($full['iguide_data']['synthetic_provider_blob']);
            $this->assertArrayNotHasKey('iguide_data', $card);
            $this->assertArrayNotHasKey('cubicasa_data', $card);
            $this->assertArrayNotHasKey('iguide_data', $card['units'][0]['provider_data']);
            foreach (['serviceItems', 'orderItems', 'order_items', 'shoot_units', 'files'] as $alias) {
                $this->assertArrayNotHasKey($alias, $card);
            }
            $this->assertLessThan(strlen($fullResponse->getContent()) / 2, strlen($cardResponse->getContent()));
        }
    }

    #[Test]
    public function compact_and_full_cache_entries_keep_their_own_contract(): void
    {
        $this->freezeTime();
        Cache::flush();
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $shoot = $this->createOperationalShoot(Shoot::STATUS_SCHEDULED, User::factory()->photographer()->create(), User::factory()->create(['role' => 'editor']));
        Sanctum::actingAs($manager);
        $url = '/api/shoots?tab=scheduled&include_files=false&include_filters=false';

        $full = $this->getJson($url)->assertOk()->json();
        $card = $this->getJson($url.'&view=card')->assertOk()->json();
        $this->assertSame($shoot->id, $card['data'][0]['id']);
        $this->assertArrayHasKey('iguide_data', $full['data'][0]);
        $this->assertArrayNotHasKey('iguide_data', $card['data'][0]);
        $this->assertSame($full, $this->getJson($url)->assertOk()->json());
        $this->assertSame($card, $this->getJson($url.'&view=card')->assertOk()->json());
    }

    #[Test]
    public function compact_photographer_card_preserves_positive_raw_capability_without_granting_invoice_access(): void
    {
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => User::factory()->unverified()->create(['role' => 'client'])->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'raw_photo_count' => 1,
        ]);
        Sanctum::actingAs($photographer);

        $card = $this->getJson('/api/shoots?tab=scheduled&include_files=false&include_filters=false&view=card&no_cache=true')
            ->assertOk()->assertJsonPath('data.0.id', $shoot->id)->json('data.0');
        $this->assertTrue($card['can_submit_raw']);
        $this->assertTrue($card['canSubmitRaw']);
        $this->assertFalse($card['can_view_invoice']);
        $this->assertFalse($card['canViewInvoice']);
        $this->assertFalse($card['client']['email_verified']);
        $this->assertFalse($card['client']['emailVerified']);
        $this->assertArrayNotHasKey('email_verified_at', $card['client']);
    }

    #[Test]
    public function all_dashboard_roles_keep_their_existing_capabilities_and_service_scope_in_compact_cards(): void
    {
        $this->freezeTime();
        $photographer = User::factory()->photographer()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->createOperationalShoot(Shoot::STATUS_DELIVERED, $photographer, $editor);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->update(['rep_id' => $rep->id]);
        $viewers = [
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'superadmin']),
            User::factory()->create(['role' => 'editing_manager']),
            $photographer, $editor, $rep, $shoot->client,
        ];
        foreach ($viewers as $viewer) {
            Sanctum::actingAs($viewer);
            $url = '/api/shoots?tab=delivered&include_files=false&include_filters=false&no_cache=true';
            $full = $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $shoot->id)->json('data.0');
            $card = $this->getJson($url.'&view=card')->assertOk()->assertJsonPath('data.0.id', $shoot->id)->json('data.0');
            foreach ([
                'status', 'workflow_status', 'services', 'service_items', 'service_presentation',
                'can_submit_raw', 'can_submit_edits', 'can_view_invoice', 'can_finalize_no_media',
                'canManageBooking', 'canManageShootActions', 'canAdjustShootPricing',
                'payment', 'total_paid', 'total_quote', 'editor_assignments', 'scheduled_instant',
                'company_notes', 'editor_notes', 'photographer_notes',
            ] as $field) {
                if (! array_key_exists($field, $full)) {
                    $this->assertArrayNotHasKey($field, $card, $viewer->role.': '.$field);
                    continue;
                }
                $this->assertArrayHasKey($field, $card, $viewer->role.': '.$field);
                $this->assertSame($full[$field], $card[$field], $viewer->role.': '.$field);
            }
            $this->assertArrayNotHasKey('external_booking_payload', $card);
            $this->assertArrayNotHasKey('iguide_data', $card);
        }
    }

    private function createOperationalShoot(string $status, User $photographer, User $editor): Shoot
    {
        $photo = Service::factory()->bracketedPhoto(35)->create(['name' => 'HDR Photos']);
        $video = Service::factory()->videoIntake()->create(['name' => 'Video']);
        $shoot = Shoot::factory()->create([
            'client_id' => User::factory()->create(['role' => 'client', 'email_verified_at' => now()])->id,
            'photographer_id' => $photographer->id,
            'editor_id' => $editor->id,
            'service_id' => $photo->id,
            'status' => $status,
            'workflow_status' => $status,
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:30',
            'timezone' => 'America/New_York',
            'address' => '123 Contract Test Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'property_details' => ['apt_suite' => 'Suite 2', 'sqft' => 1700, 'beds' => 3, 'baths' => 2],
            'units_revision' => 7,
            'company_notes' => 'Client company context', 'editor_notes' => 'Editing instructions',
            'photographer_notes' => 'Access instructions', 'hold_reason' => 'Requested date change',
            'hold_requested_at' => now(), 'hold_requested_by' => $photographer->id,
            'cancellation_requested_at' => now(), 'cancellation_reason' => 'Client request',
            'admin_verified_at' => $status === Shoot::STATUS_DELIVERED ? now()->subHour() : null,
            'iguide_data' => ['synthetic_provider_blob' => str_repeat('provider data ', 16000)],
            'cubicasa_data' => ['synthetic_provider_blob' => str_repeat('provider data ', 16000)],
            'payment_status' => 'unpaid', 'base_quote' => 300, 'total_quote' => 300, 'tax_amount' => 0,
            'discount_type' => 'fixed', 'discount_value' => 25, 'discount_amount' => 25,
        ]);
        $unit = $shoot->units()->create([
            'client_key' => 'unit-2', 'label' => 'Suite 2', 'kind' => 'unit', 'sqft' => 1700,
            'beds' => 3, 'baths' => 2, 'access_notes' => 'Front door', 'sort_order' => 1,
            'provider_data' => [
                'iguide_status' => 'ready', 'iguide_tour_url' => 'https://example.test/tour',
                'iguide_data' => ['synthetic_provider_blob' => str_repeat('unit provider data ', 8000)],
                'lines' => ['photo' => ['iguide_status' => 'ready', 'iguide_data' => ['nested_blob' => 'details']], 'legacy' => 'unstructured provider data'],
            ],
        ]);
        foreach ([$photo, $video] as $service) {
            $shoot->services()->attach($service->id, [
                'shoot_unit_id' => $unit->id, 'client_key' => 'service-'.$service->id,
                'price' => 150, 'quantity' => 1, 'photographer_pay' => 45,
                'photographer_id' => $photographer->id, 'editor_id' => $editor->id,
                'video_editor_id' => $editor->id, 'duration_minutes' => 55,
                'scheduled_at' => now()->addDay()->setTime(10, 30),
                'workflow_status' => $status === Shoot::STATUS_DELIVERED ? 'completed' : 'pending',
                'delivery_status' => $status === Shoot::STATUS_DELIVERED ? 'delivered' : 'not_started',
                'force_unlock_delivery' => true, 'is_deliverable' => true,
            ]);
        }

        return $shoot;
    }
}
