<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootManagementAccess;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesShootManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function shoot(): Shoot
    {
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'rep_id' => null, 'scheduled_at' => null, 'scheduled_date' => null, 'time' => null, 'photographer_id' => null, 'state' => 'MD', 'tax_region' => 'MD', 'tax_percent' => 6]);
        $service = Service::factory()->noIntake()->create(['price' => 125, 'photographer_required' => false]);
        $shoot->services()->attach($service->id, ['price' => 99, 'quantity' => 1, 'duration_minutes' => 0]);
        return $shoot;
    }

    public function test_every_alias_and_secondary_rep_can_save_global_property_client_and_access_edits(): void
    {
        foreach (['salesRep', 'sales_rep', 'sales-rep', 'sales rep', 'rep', 'representative', 'secondary'] as $role) {
            $actor = User::factory()->create(['role' => $role === 'secondary' ? 'photographer' : $role, 'secondary_roles' => $role === 'secondary' ? ['sales_rep'] : []]);
            Sanctum::actingAs($actor);
            $shoot = $this->shoot();
            $client = User::factory()->create(['role' => 'client']);
            $response = $this->patchJson('/api/shoots/'.$shoot->id, ['client_id' => $client->id, 'address' => '42 Test Lane', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201', 'property_details' => ['beds' => 4, 'baths' => 2, 'sqft' => 2500, 'presenceOption' => 'lockbox', 'lockboxCode' => 'fixture'], 'services' => [['id' => $shoot->serviceItems()->sole()->service_id, 'price' => 999]], 'notify_client' => false, 'notify_photographer' => false]);
            $response->assertOk();
            $this->assertSame($client->id, $shoot->fresh()->client_id);
            $this->assertSame('42 Test Lane', $shoot->fresh()->address);
            $this->assertSame('fixture', $shoot->fresh()->property_details['lockboxCode']);
            $this->assertSame(99.0, (float) $shoot->serviceItems()->sole()->price);
        }
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_rep_discount_and_intentional_adjustment_are_separate_from_payment_confirmation(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        $shoot = $this->shoot();
        $this->patchJson('/api/shoots/'.$shoot->id, ['discount_type' => 'percent', 'discount_value' => 10])->assertOk();
        $this->assertSame(94.45, (float) $shoot->fresh()->total_quote);
        $this->patchJson('/api/shoots/'.$shoot->id, ['admin_adjusted_total_quote' => 120])->assertOk();
        $this->assertSame(120.0, (float) $shoot->fresh()->total_quote);
        $this->postJson('/api/shoots/'.$shoot->id.'/mark-paid', [])->assertForbidden();
        $rep->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoot-pricing-update']]]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['discount_value' => 5])->assertForbidden();
    }

    public function test_secondary_rep_role_does_not_demote_privileged_staff(): void
    {
        $management = app(ShootManagementAccess::class);
        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'secondary_roles' => ['sales_rep']]);
            Sanctum::actingAs($actor);
            $shoot = $this->shoot();
            $this->assertTrue($management->isSalesRep($actor));
            $this->assertFalse($management->isRestrictedSalesRep($actor));
            $payload = $role === 'editing_manager' ? ['address' => 'Existing manager rights'] : ['is_listing_hidden' => true];
            $this->patchJson('/api/shoots/'.$shoot->id, $payload + ['notify_client' => false, 'notify_photographer' => false])->assertOk();
            $this->assertSame($role === 'editing_manager' ? 'Existing manager rights' : true,
                $role === 'editing_manager' ? $shoot->fresh()->address : (bool) $shoot->fresh()->is_listing_hidden);
            $shoot->status = $shoot->workflow_status = 'delivered';
            $this->assertTrue($management->canEdit($shoot, $actor));
            $notes = app(\App\Services\Shoots\ShootNotesAccessService::class);
            foreach (['shoot' => 'client_visible', 'company' => 'internal', 'photographer' => 'photographer_only', 'editing' => 'internal', 'approval' => 'internal'] as $type => $visibility) {
                $this->assertTrue($notes->canCreate($shoot, $actor, $type, $visibility));
            }
        }
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_stale_edit_cannot_overwrite_a_newer_change(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $shoot = $this->shoot();
        $token = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data.editVersion');
        $this->assertNotEmpty($token);
        $shoot->update(['city' => 'New office correction']);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Stale draft', 'expected_edit_version' => $token])->assertConflict()->assertJsonPath('code', 'shoot_edit_conflict');
        $this->assertSame('New office correction', $shoot->fresh()->city);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Reviewed correction', 'expected_edit_version' => app(ShootManagementAccess::class)->editVersion($shoot->fresh())])->assertOk();
    }

    public function test_excluded_capabilities_locked_states_and_denied_accounts_stay_restricted(): void
    {
        $shoot = $this->shoot(); $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        $access = app(ShootAuthorizationSupport::class);
        $this->assertFalse($access->canUploadShootMedia($shoot, $rep));
        $this->assertFalse($access->canManageShootOperations($rep));
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-editor', [])->assertForbidden();
        $this->patchJson('/api/shoots/'.$shoot->id, ['editor_id' => User::factory()->create(['role' => 'editor'])->id])->assertForbidden();
        $this->patchJson('/api/shoots/'.$shoot->id, ['workflow_status' => 'delivered'])->assertForbidden();
        $shoot->update(['status' => 'cancelled', 'workflow_status' => 'cancelled']);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Blocked'])->assertForbidden();
        $shoot->update(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $rep->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-update']]]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Blocked'])->assertForbidden();
        foreach (['client', 'photographer', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Blocked'])->assertForbidden();
        }
    }

    public function test_rep_can_cancel_with_notifications_muted_and_permission_denial_is_enforced(): void
    {
        $rep = User::factory()->create(['role' => 'representative']); Sanctum::actingAs($rep); $shoot = $this->shoot();
        $this->postJson('/api/shoots/'.$shoot->id.'/cancel', ['reason' => 'Muted fixture', 'notify_client' => false, 'suppress_notifications' => true])->assertOk();
        $this->assertSame('cancelled', $shoot->fresh()->status);
        $rep->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-manage']]]);
        $this->postJson('/api/shoots/'.$this->shoot()->id.'/cancel', ['notify_client' => false])->assertForbidden();
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_reps_create_catalog_priced_bookings_but_cannot_assign_production(): void
    {
        $rep = User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['representative']]);
        Sanctum::actingAs($rep);
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->noIntake()->create(['price' => 125, 'photographer_required' => false]);
        $payload = ['client_id' => $client->id, 'address' => 'Muted fixture', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'services' => [['id' => $service->id, 'price' => 999]], 'notify_client' => false, 'notify_photographer' => false];
        $id = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        $this->assertSame(125.0, (float) Shoot::findOrFail($id)->serviceItems()->sole()->price);
        $payload['services'][0]['editor_id'] = User::factory()->create(['role' => 'editor'])->id;
        $this->postJson('/api/shoots', $payload)->assertForbidden();
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_secondary_rep_sees_global_lists_and_history_without_expanding_media(): void
    {
        $actor = User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['sales_rep']]); Sanctum::actingAs($actor);
        $shoot = $this->shoot();
        $this->getJson('/api/shoots?tab=all&no_cache=true')->assertOk()->assertJsonFragment(['id' => $shoot->id]);
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->getJson('/api/shoots/history')->assertOk()->assertJsonFragment(['id' => $shoot->id]);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $actor));
        $this->postJson('/api/shoots/'.$shoot->id.'/cancel', ['notify_client' => false])->assertForbidden();
    }

    public function test_permission_migration_preserves_existing_roles_and_individual_denials(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep', 'permission_overrides' => ['allow' => [], 'deny' => ['shoot-pricing-update']]]);
        \App\Models\Setting::updateOrCreate(['key' => 'permissions.role_map.v1'], ['value' => json_encode(['version' => 1, 'roles' => ['salesRep' => ['dashboard-view'], 'client' => ['booking-create']]])]);
        $migration = require database_path('migrations/2026_10_07_120000_add_scoped_shoot_management_permissions.php');
        $migration->up();
        $map = json_decode(\App\Models\Setting::where('key', 'permissions.role_map.v1')->value('value'), true);
        $this->assertContains('shoots-update', $map['roles']['salesRep']);
        $this->assertContains('dashboard-view', $map['roles']['salesRep']);
        $this->assertSame(['booking-create'], $map['roles']['client']);
        $this->assertSame(['shoot-pricing-update'], $rep->fresh()->permission_overrides['deny']);
        Sanctum::actingAs($rep);
        $shoot = $this->shoot();
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Allowed booking edit', 'discount_value' => 0, 'admin_adjusted_total_quote' => null, 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->patchJson('/api/shoots/'.$shoot->id, ['discount_type' => 'percent', 'discount_value' => 5])->assertForbidden();
    }

    public function test_secondary_reps_receive_complete_booking_metadata_without_media_capabilities(): void
    {
        foreach (['photographer', 'editor'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'secondary_roles' => ['salesRep']]);
            Sanctum::actingAs($actor);
            $shoot = $this->shoot();
            $unit = $shoot->units()->create(['client_key' => 'unit-fixture', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 1000, 'access_notes' => 'Muted access instruction', 'sort_order' => 0]);
            $shoot->units()->create(['client_key' => 'empty-unit', 'label' => 'Empty unit', 'kind' => 'unit', 'sqft' => 500, 'sort_order' => 1]);
            $shoot->serviceItems()->sole()->update(['shoot_unit_id' => $unit->id]);
            $response = $this->getJson('/api/shoots/'.$shoot->id)->assertOk();
            $response->assertJsonCount(1, 'data.services')->assertJsonPath('data.services.0.price', 99);
            $response->assertJsonCount(2, 'data.units')->assertJsonPath('data.units.0.access_notes', 'Muted access instruction');
            $this->assertNotNull($response->json('data.client'));
            $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $actor));
            $this->assertFalse(app(ShootAuthorizationSupport::class)->canManageShootOperations($actor));
        }
    }

    public function test_edit_token_is_not_a_client_facing_approval_modification(): void
    {
        $action = app(\App\Services\Shoots\Actions\ApproveShootAction::class);
        $method = new \ReflectionMethod($action, 'hasClientFacingRequestModifications');
        $this->assertFalse($method->invoke($action, ['expected_edit_version' => str_repeat('a', 64), 'notify_client' => false]));
        $this->assertTrue($method->invoke($action, ['expected_edit_version' => str_repeat('a', 64), 'address' => 'Actual property change']));
    }

    public function test_delivered_details_and_pricing_corrections_preserve_production_and_payment_history(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($rep);
        $shoot = $this->shoot();
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'rep_id' => $rep->id, 'payment_status' => 'unpaid']);
        $item = $shoot->serviceItems()->sole();
        $item->update(['workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $invoice = app(\App\Services\InvoiceService::class)->generateForShoot($shoot->fresh());
        $payment = \App\Models\Payment::create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 50, 'currency' => 'USD', 'status' => \App\Models\Payment::STATUS_COMPLETED, 'processed_at' => now()]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $lineBefore = $item->fresh()->getRawOriginal();

        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.canManageBooking', true);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'address' => 'Corrected delivered address', 'shoot_notes' => 'Corrected client instructions',
            'discount_type' => 'percent', 'discount_value' => 10,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'photographer_id' => null,
            'services' => [['id' => $item->service_id, 'quantity' => 1, 'duration_minutes' => 0]],
            'service_items' => [['service_id' => $item->service_id, 'quantity' => 1, 'duration_minutes' => 0]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('Corrected client instructions', $shoot->fresh()->shoot_notes);
        $this->assertSame(94.45, (float) $shoot->fresh()->total_quote);
        $this->assertSame(5.35, (float) $shoot->fresh()->tax_amount);
        $this->assertSame(94.45, (float) $invoice->fresh()->total);
        $this->assertSame(50.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame($lineBefore, $item->fresh()->getRawOriginal());
        $this->patchJson('/api/shoots/'.$shoot->id, ['admin_adjusted_total_quote' => 120, 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame(120.0, (float) $invoice->fresh()->total);
        $this->assertSame(6.79, (float) $shoot->fresh()->tax_amount);
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_delivered_sales_edits_do_not_reopen_scheduling_services_or_production(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['sales_rep']]));
        $shoot = $this->shoot();
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $item = $shoot->serviceItems()->sole();
        $photographer = User::factory()->photographer()->create();
        foreach ([
            ['scheduled_at' => '2026-12-01T12:00:00Z'],
            ['photographer_id' => $photographer->id],
            ['rep_id' => User::factory()->create(['role' => 'salesRep'])->id],
            ['service_id' => \App\Models\Service::factory()->create()->id],
            ['service_photographers' => [['service_id' => $item->service_id, 'photographer_id' => $photographer->id]]],
            ['services' => []],
            ['services' => [['id' => $item->service_id, 'quantity' => 2]]],
            ['services' => [['id' => $item->service_id, 'scheduled_at' => '2026-12-01T12:00:00Z']]],
            ['workflow_status' => 'editing'],
            ['editor_id' => User::factory()->create(['role' => 'editor'])->id],
        ] as $payload) {
            $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertForbidden();
        }
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertNull($shoot->fresh()->scheduled_at);
        $this->assertNull($shoot->fresh()->photographer_id);
        $this->assertCount(1, $shoot->fresh()->serviceItems);
        $this->postJson('/api/shoots/'.$shoot->id.'/cancel', ['notify_client' => false])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/put-on-hold', ['notify_client' => false])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/schedule', ['scheduled_at' => '2026-12-01T12:00:00Z'])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/apply-alternate-date', [])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographer', ['service_id' => $item->service_id, 'photographer_id' => $photographer->id])->assertForbidden();
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographers', ['service_photographers' => [['service_id' => $item->service_id, 'photographer_id' => $photographer->id]]])->assertForbidden();
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_delivered_multi_unit_echoes_do_not_modify_units_or_service_execution_rows(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $shoot = $this->shoot();
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $unit = $shoot->units()->create(['client_key' => 'delivered-unit', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 1000, 'sort_order' => 0]);
        $item = $shoot->serviceItems()->sole();
        $item->update(['client_key' => 'delivered-line', 'shoot_unit_id' => $unit->id, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $before = $item->fresh()->getRawOriginal();
        $context = ['units' => [['id' => $unit->id, 'client_key' => $unit->client_key, 'label' => $unit->label, 'kind' => $unit->kind, 'sqft' => 1000]],
            'service_lines' => [['shoot_service_id' => $item->id, 'client_key' => 'delivered-line', 'unit_client_key' => 'delivered-unit', 'service_id' => $item->service_id, 'quantity' => 1, 'duration_minutes' => 0]],
            'expected_units_revision' => $shoot->units_revision, 'notify_client' => false, 'notify_photographer' => false];
        $this->patchJson('/api/shoots/'.$shoot->id, $context + ['city' => 'Unit property correction'])->assertOk();
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $changed = $context;
        $changed['units'][0]['label'] = 'Changed execution unit';
        $this->patchJson('/api/shoots/'.$shoot->id, $changed)->assertForbidden();
        $changed = $context;
        $changed['service_lines'][0]['quantity'] = 2;
        $this->patchJson('/api/shoots/'.$shoot->id, $changed)->assertForbidden();
    }

    public function test_delivered_detail_only_full_form_echoes_preserve_agreed_price_and_booked_discount(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        foreach (['override', 'discount'] as $pricing) {
            $shoot = $this->shoot();
            $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered',
                'base_quote' => $pricing === 'override' ? 122.64 : 89.10,
                'tax_amount' => $pricing === 'override' ? 7.36 : 5.35,
                'total_quote' => $pricing === 'override' ? 130 : 94.45,
                'discount_type' => $pricing === 'discount' ? 'percent' : null,
                'discount_value' => $pricing === 'discount' ? 10 : null,
                'discount_amount' => $pricing === 'discount' ? 9.90 : 0]);
            $invoice = app(\App\Services\InvoiceService::class)->generateForShoot($shoot->fresh());
            $invoiceBefore = $invoice->fresh()->getRawOriginal();
            $before = $shoot->fresh()->only(['base_quote', 'tax_amount', 'total_quote', 'discount_type', 'discount_value', 'discount_amount']);
            $this->patchJson('/api/shoots/'.$shoot->id, ['client_id' => $shoot->client_id, 'state' => 'MD',
                'city' => 'Detail-only correction', 'shoot_notes' => 'Updated instructions',
                'notify_client' => false, 'notify_photographer' => false])->assertOk();
            $this->assertSame($before, $shoot->fresh()->only(array_keys($before)));
            $this->assertSame($invoiceBefore, $invoice->fresh()->getRawOriginal());
        }
    }

    public function test_delivered_aliases_are_editable_but_imports_and_denied_accounts_stay_locked(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($rep);
        $shoot = $this->shoot();
        foreach (['delivered', 'ready_for_client', 'admin_verified'] as $status) {
            $shoot->update(['status' => 'delivered', 'workflow_status' => $status]);
            $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Corrected '.$status, 'notify_client' => false, 'notify_photographer' => false])->assertOk();
            $this->assertSame($status, $shoot->fresh()->workflow_status);
        }
        foreach ([
            ['status' => 'import_draft', 'workflow_status' => 'delivered'],
            ['status' => 'declined', 'workflow_status' => 'declined'],
            ['status' => 'delivered', 'workflow_status' => 'delivered', 'external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true]]],
        ] as $attributes) {
            $shoot = $this->shoot();
            $shoot->update($attributes);
            $response = $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Blocked']);
            $attributes['status'] === 'import_draft' ? $response->assertNotFound() : $response->assertForbidden();
        }
        $shoot = $this->shoot();
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered', 'external_booking_payload' => null]);
        $rep->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-update']]]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => 'Blocked'])->assertForbidden();
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }
}
