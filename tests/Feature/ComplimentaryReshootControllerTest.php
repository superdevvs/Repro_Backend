<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplimentaryReshootControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $photographer;

    private User $salesRep;

    private Service $service;

    private Shoot $sourceShoot;

    private ShootService $sourceItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->photographer()->create();
        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => ['repDetails' => ['commissionPercentage' => 10]],
        ]);
        $this->service = Service::factory()->create([
            'price' => 250,
            'pricing_type' => 'fixed',
            'photographer_pay' => 75,
            'photographer_pay_type' => Service::PAY_TYPE_FIXED,
            'exclude_from_sales_commission' => false,
        ]);
        $this->sourceShoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'rep_id' => $this->salesRep->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'base_quote' => 250,
            'tax_amount' => 0,
            'total_quote' => 250,
            'property_details' => ['sqft' => 2400],
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
        ]);
        $this->sourceItem = ShootService::create([
            'shoot_id' => $this->sourceShoot->id,
            'service_id' => $this->service->id,
            'photographer_id' => $this->photographer->id,
            'price' => 250,
            'quantity' => 1,
            'photographer_pay' => 75,
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'is_deliverable' => true,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_admin_creates_exact_zero_reshoot_with_explicit_compensation_rows(): void
    {
        $response = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('company_error')
        );

        $response->assertCreated()
            ->assertJsonPath('data.shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
            ->assertJsonPath('data.payment_status', Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED)
            ->assertJsonPath('data.client_charge.total', 0)
            ->assertJsonPath('data.lineage.parent.id', $this->sourceShoot->id)
            ->assertJsonPath('data.lineage.root.id', $this->sourceShoot->id)
            ->assertJsonPath('data.photographer_compensations.0.amount', 75)
            ->assertJsonPath('data.sales_rep_compensation.mode', ShootCompensation::MODE_NONE)
            ->assertJsonPath('data.sales_rep_compensation.amount', 0);

        $shoot = Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->firstOrFail();
        $this->assertSame(0.0, (float) $shoot->base_quote);
        $this->assertSame(0.0, (float) $shoot->tax_amount);
        $this->assertSame(0.0, (float) $shoot->total_quote);
        $this->assertSame(Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED, $shoot->payment_status);
        $this->assertTrue((bool) $shoot->bypass_paywall);

        $childItem = $shoot->serviceItems()->firstOrFail();
        $this->assertSame(0.0, (float) $childItem->price);
        $this->assertSame(250.0, (float) $childItem->nominal_value_snapshot);
        $this->assertSame(0.0, (float) $childItem->photographer_pay);

        $this->assertDatabaseCount('shoot_compensations', 2);
        $this->assertDatabaseHas('shoot_compensations', [
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $childItem->id,
            'recipient_type' => ShootCompensation::RECIPIENT_PHOTOGRAPHER,
            'mode' => ShootCompensation::MODE_STANDARD,
            'amount' => 75,
            'standard_amount_snapshot' => 75,
        ]);
        $this->assertDatabaseHas('shoot_compensations', [
            'shoot_id' => $shoot->id,
            'shoot_service_id' => null,
            'recipient_type' => ShootCompensation::RECIPIENT_SALES_REP,
            'mode' => ShootCompensation::MODE_NONE,
            'amount' => 0,
        ]);
        $this->assertDatabaseHas('invoices', [
            'shoot_id' => $shoot->id,
            'role' => 'client',
            'document_type' => 'complimentary_receipt',
            'payment_required' => false,
            'total_amount' => 0,
            'amount_paid' => 0,
            'is_paid' => false,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'shoot_id' => $shoot->id,
            'unit_amount' => 0,
            'total_amount' => 0,
        ]);

        $receipt = Invoice::query()->where('shoot_id', $shoot->id)->firstOrFail();
        $this->assertSame(Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT, $receipt->document_type);
        $this->assertFalse($receipt->payment_required);
        $this->assertFalse($receipt->is_paid);
        $this->assertSame(0.0, (float) $receipt->total_amount);
    }

    public function test_base_get_returns_booking_template_and_explicit_choice_policy(): void
    {
        $this->getJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots?reason_code=client_accommodation"
        )->assertOk()
            ->assertJsonPath('data.source.id', $this->sourceShoot->id)
            ->assertJsonPath('data.root.id', $this->sourceShoot->id)
            ->assertJsonPath('data.source_service_items.0.id', $this->sourceItem->id)
            ->assertJsonPath('data.source_service_items.0.nominal_total', 250)
            ->assertJsonPath('data.source_service_items.0.standard_photographer_pay', 75)
            ->assertJsonPath('data.sales_rep_standard.amount', 25)
            ->assertJsonFragment([
                'code' => 'client_accommodation',
                'suggested_sales_rep_mode' => ShootCompensation::MODE_NONE,
                'requires_explicit_sales_rep_choice' => true,
            ]);

        $this->getJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoot-template"
        )->assertOk()
            ->assertJsonPath('data.source.id', $this->sourceShoot->id)
            ->assertJsonPath('data.policy_version', \App\Services\Shoots\ComplimentaryReshootReasonPolicy::VERSION);
    }

    public function test_frontend_zero_amount_for_none_is_ignored_and_client_accommodation_requires_rep_choice(): void
    {
        $payload = $this->payload('client_accommodation');
        unset($payload['sales_rep_compensation_mode']);
        $payload['items'][0]['photographer_compensation_mode'] = ShootCompensation::MODE_STANDARD;
        $payload['items'][0]['photographer_pay'] = 0;

        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertUnprocessable()
            ->assertJsonValidationErrors('sales_rep_compensation.mode');

        $payload['sales_rep_compensation_mode'] = ShootCompensation::MODE_NONE;
        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertCreated();
    }

    public function test_explicit_idempotency_key_replays_same_request_and_rejects_conflict(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload('missed_area');

        $first = $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        );
        $first->assertCreated()->assertJsonPath('meta.replayed', false);
        $shootId = $first->json('data.id');

        $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertOk()
            ->assertJsonPath('data.id', $shootId)
            ->assertJsonPath('meta.replayed', true);
        $this->assertSame(1, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
        $this->assertSame(1, Invoice::query()->where('shoot_id', $shootId)->count());

        $payload['reason_note'] = 'A materially different retry.';
        $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertConflict();
    }

    public function test_switching_to_standard_uses_booking_snapshot_after_catalog_rate_changes(): void
    {
        $create = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('missed_area')
        )->assertCreated();

        $shootId = (int) $create->json('data.id');
        $compensation = ShootCompensation::query()
            ->where('shoot_id', $shootId)
            ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
            ->firstOrFail();
        $this->assertSame(75.0, (float) $compensation->standard_amount_snapshot);
        $this->service->update(['photographer_pay' => 999]);

        $this->patchJson("/api/admin/shoots/{$shootId}/compensations", [
            'compensations' => [[
                'id' => $compensation->id,
                'mode' => ShootCompensation::MODE_STANDARD,
                'expected_updated_at' => $compensation->updated_at->toIso8601String(),
            ]],
        ])->assertOk()
            ->assertJsonPath('data.photographer_compensations.0.amount', 75);

        $this->assertSame(75.0, (float) $compensation->fresh()->amount);
    }

    public function test_bulk_update_is_atomic_and_rejects_any_earned_locked_row(): void
    {
        $create = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('company_error')
        )->assertCreated();
        $shootId = (int) $create->json('data.id');
        $rows = ShootCompensation::query()->where('shoot_id', $shootId)->orderBy('id')->get();
        $rows->last()->update(['locked_at' => now(), 'earned_at' => now()]);

        $this->patchJson("/api/admin/shoots/{$shootId}/compensations", [
            'compensations' => $rows->map(fn (ShootCompensation $row) => [
                'id' => $row->id,
                'mode' => ShootCompensation::MODE_CUSTOM,
                'amount' => 123,
            ])->all(),
        ])->assertConflict();

        $this->assertSame(75.0, (float) $rows->first()->fresh()->amount);
    }

    public function test_chain_keeps_immediate_parent_and_original_root(): void
    {
        $first = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('company_error')
        )->assertCreated();
        $firstShoot = Shoot::findOrFail($first->json('data.id'));
        $firstItem = $firstShoot->serviceItems()->firstOrFail();
        $this->service->update(['price' => 999]);

        $payload = $this->payload('weather_access');
        $payload['items'][0]['source_shoot_service_id'] = $firstItem->id;
        $second = $this->postJson(
            "/api/admin/shoots/{$firstShoot->id}/complimentary-reshoots",
            $payload
        )->assertCreated();

        $secondShoot = Shoot::findOrFail($second->json('data.id'));
        $this->assertSame($firstShoot->id, $secondShoot->reshoot_of_shoot_id);
        $this->assertSame($this->sourceShoot->id, $secondShoot->root_shoot_id);
        $this->assertSame(250.0, (float) $secondShoot->serviceItems()->firstOrFail()->nominal_value_snapshot);
        $this->assertSame(
            250.0,
            (float) $secondShoot->compReshootItems()->firstOrFail()->nominal_total_snapshot
        );
    }

    public function test_sales_rep_is_inherited_from_source_and_cannot_be_redirected(): void
    {
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $payload = $this->payload('company_error');
        $payload['rep_id'] = $otherRep->id;

        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertUnprocessable()
            ->assertJsonValidationErrors('rep_id');

        $this->assertDatabaseMissing('shoots', [
            'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
            'rep_id' => $otherRep->id,
        ]);
    }

    public function test_payable_compensation_requires_a_real_recipient_but_rep_none_is_still_audited(): void
    {
        $this->sourceShoot->update(['rep_id' => null, 'photographer_id' => null]);
        $this->sourceItem->update(['photographer_id' => null]);

        $missingPhotographer = $this->payload('company_error');
        $missingPhotographer['items'][0]['photographer_id'] = null;
        $missingPhotographer['sales_rep_compensation_mode'] = ShootCompensation::MODE_NONE;
        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $missingPhotographer
        )->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.photographer_id');

        $missingRep = $this->payload('company_error');
        $missingRep['items'][0]['photographer_compensation_mode'] = ShootCompensation::MODE_NONE;
        $missingRep['sales_rep_compensation_mode'] = ShootCompensation::MODE_STANDARD;
        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $missingRep
        )->assertUnprocessable()
            ->assertJsonValidationErrors('sales_rep_compensation.mode');

        $auditedNone = $this->payload('company_error');
        $auditedNone['items'][0]['photographer_compensation_mode'] = ShootCompensation::MODE_NONE;
        $auditedNone['sales_rep_compensation_mode'] = ShootCompensation::MODE_NONE;
        $created = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $auditedNone
        )->assertCreated();

        $this->assertDatabaseHas('shoot_compensations', [
            'shoot_id' => $created->json('data.id'),
            'shoot_service_id' => null,
            'scope_key' => ShootCompensation::shootScopeKey(),
            'recipient_type' => ShootCompensation::RECIPIENT_SALES_REP,
            'recipient_user_id' => null,
            'mode' => ShootCompensation::MODE_NONE,
            'amount' => 0,
        ]);
    }

    public function test_assigned_recipients_can_read_only_their_own_compensation_view(): void
    {
        $create = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('company_error')
        )->assertCreated();
        $shootId = (int) $create->json('data.id');

        Sanctum::actingAs($this->photographer);
        $photographerResponse = $this->getJson("/api/admin/shoots/{$shootId}/compensations")
            ->assertOk()
            ->assertJsonCount(1, 'data.photographer_compensations')
            ->assertJsonCount(1, 'data.compensations')
            ->assertJsonCount(1, 'data.service_items')
            ->assertJsonPath('data.photographer_compensations.0.recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER)
            ->assertJsonPath('data.sales_rep_compensation', null);
        $photographerData = $photographerResponse->json('data');
        $this->assertArrayNotHasKey('affected_source_items', $photographerData);
        $this->assertArrayNotHasKey('financial_summary', $photographerData);
        $this->assertArrayNotHasKey('reason_code', $photographerData['compensations'][0]);
        $this->assertArrayNotHasKey('basis_amount_snapshot', $photographerData['compensations'][0]);

        Sanctum::actingAs($this->salesRep);
        $salesRepResponse = $this->getJson("/api/admin/shoots/{$shootId}/compensations")
            ->assertOk()
            ->assertJsonCount(0, 'data.photographer_compensations')
            ->assertJsonCount(1, 'data.compensations')
            ->assertJsonCount(1, 'data.service_items')
            ->assertJsonPath('data.sales_rep_compensation.recipient_type', ShootCompensation::RECIPIENT_SALES_REP)
            ->assertJsonPath('data.compensations.0.recipient_type', ShootCompensation::RECIPIENT_SALES_REP);
        $salesRepData = $salesRepResponse->json('data');
        $this->assertArrayNotHasKey('affected_source_items', $salesRepData);
        $this->assertArrayNotHasKey('financial_summary', $salesRepData);

        $unassignedPhotographer = User::factory()->photographer()->create();
        Sanctum::actingAs($unassignedPhotographer);
        $this->getJson("/api/admin/shoots/{$shootId}/compensations")->assertNotFound();

        Sanctum::actingAs($this->client);
        $this->getJson("/api/admin/shoots/{$shootId}/compensations")->assertForbidden();
    }

    public function test_admin_modal_receives_affected_source_mapping_while_staff_and_client_do_not(): void
    {
        $payload = $this->payload('missed_area');
        $payload['reason_note'] = 'Primary bathroom was missed on the original visit.';
        $payload['scheduled_at'] = '2026-09-10T14:30:00Z';
        $create = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertCreated();
        $shootId = (int) $create->json('data.id');
        $childServiceItem = Shoot::findOrFail($shootId)->serviceItems()->firstOrFail();

        $this->getJson("/api/shoots/{$shootId}")
            ->assertOk()
            ->assertJsonPath('data.reshoot_service_links.0.reshoot_shoot_id', $shootId)
            ->assertJsonPath('data.reshoot_service_links.0.reshoot_shoot_service_id', $childServiceItem->id)
            ->assertJsonPath('data.reshoot_service_links.0.shoot_service_id', $childServiceItem->id)
            ->assertJsonPath('data.reshoot_service_links.0.source_shoot_id', $this->sourceShoot->id)
            ->assertJsonPath('data.reshoot_service_links.0.source_shoot_service_id', $this->sourceItem->id)
            ->assertJsonPath('data.reshoot_service_links.0.source_service_name', $this->service->name)
            ->assertJsonPath('data.reshoot_service_links.0.source_service.name', $this->service->name)
            ->assertJsonPath('data.reshoot_service_links.0.reason_code', 'missed_area')
            ->assertJsonPath('data.reshoot_service_links.0.reason_note', $payload['reason_note'])
            ->assertJsonPath('data.reshoot_service_links.0.responsibility', 'photographer')
            ->assertJsonPath('data.reshoot_service_links.0.responsible_staff_id', $this->photographer->id)
            ->assertJsonPath('data.reshoot_service_links.0.responsible_staff_name', $this->photographer->name)
            ->assertJsonPath('data.affected_source_items.0.reshoot_shoot_service_id', $childServiceItem->id);

        Sanctum::actingAs($this->photographer);
        $photographerData = $this->getJson("/api/shoots/{$shootId}")->assertOk()->json('data');
        foreach (['reshoot_service_links', 'affected_source_items', 'reshoot_reason_code', 'reshoot_reason_note', 'complimentary_reshoot_overview', 'compensation_summary', 'shoot_notes'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $photographerData);
        }

        Sanctum::actingAs($this->client);
        $clientResponse = $this->getJson("/api/shoots/{$shootId}")
            ->assertOk()
            ->assertJsonPath('data.reshoot_parent.id', $this->sourceShoot->id)
            ->assertJsonPath('data.reshoot_root.id', $this->sourceShoot->id)
            ->assertJsonPath('data.reshoot_parent.service_names.0', $this->service->name);
        $clientData = $clientResponse->json('data');
        foreach (['reshoot_service_links', 'affected_source_items', 'reshoot_reason_code', 'reshoot_reason_note', 'complimentary_reshoot_overview', 'compensation_summary', 'shoot_notes'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $clientData);
        }
    }

    public function test_complimentary_create_rejects_a_corrupt_cyclic_source_chain(): void
    {
        $second = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'rep_id' => $this->salesRep->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'address' => $this->sourceShoot->address,
            'city' => $this->sourceShoot->city,
            'state' => $this->sourceShoot->state,
            'zip' => $this->sourceShoot->zip,
            'reshoot_of_shoot_id' => $this->sourceShoot->id,
            'root_shoot_id' => $this->sourceShoot->id,
        ]);
        $this->sourceShoot->update([
            'reshoot_of_shoot_id' => $second->id,
            'root_shoot_id' => $this->sourceShoot->id,
        ]);

        $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $this->payload('company_error')
        )->assertUnprocessable()
            ->assertJsonValidationErrors('source_shoot');

        $this->assertSame(0, Shoot::query()
            ->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
            ->count());
    }

    public function test_overview_exposes_compact_reshoot_costs_only_to_admins(): void
    {
        $payload = $this->payload('company_error');
        $payload['reason_note'] = 'Dispatch selected the wrong service configuration.';
        $payload['scheduled_at'] = '2026-09-12T15:45:00Z';
        $create = $this->postJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots",
            $payload
        )->assertCreated();
        $shootId = (int) $create->json('data.id');

        $this->getJson("/api/shoots/{$this->sourceShoot->id}")
            ->assertOk()
            ->assertJsonPath('data.reshoot_summary.related_count', 1)
            ->assertJsonPath('data.reshoot_summary.complimentary_count', 1)
            ->assertJsonPath('data.complimentary_reshoots.0.id', $shootId)
            ->assertJsonPath('data.complimentary_reshoots.0.address', $this->sourceShoot->address)
            ->assertJsonPath('data.complimentary_reshoots.0.reason_code', 'company_error')
            ->assertJsonPath('data.complimentary_reshoots.0.reason_note', $payload['reason_note'])
            ->assertJsonPath('data.complimentary_reshoots.0.affected_service_names.0', $this->service->name);
        $this->getJson("/api/shoots/{$shootId}")
            ->assertOk()
            ->assertJsonPath('data.complimentary_reshoot_overview.reason_code', 'company_error')
            ->assertJsonPath('data.complimentary_reshoot_overview.client_charge_total', 0)
            ->assertJsonPath('data.complimentary_reshoot_overview.staff_compensation_total', 75)
            ->assertJsonPath('data.complimentary_reshoot_overview.editor_cost_status', 'pending')
            ->assertJsonPath('data.complimentary_reshoot_overview.company_cost_actual_to_date', 75)
            ->assertJsonPath('data.complimentary_reshoot_overview.company_cost_total', null);

        Sanctum::actingAs($this->client);
        $clientResponse = $this->getJson("/api/shoots/{$this->sourceShoot->id}")
            ->assertOk()
            ->assertJsonPath('data.reshoot_summary.related_count', 1)
            ->assertJsonPath('data.complimentary_reshoots.0.id', $shootId)
            ->assertJsonPath('data.complimentary_reshoots.0.address', $this->sourceShoot->address)
            ->assertJsonPath('data.complimentary_reshoots.0.affected_service_names.0', $this->service->name);
        $clientData = $clientResponse->json('data');
        $this->assertArrayNotHasKey('complimentary_reshoot_overview', $clientData);
        $this->assertArrayNotHasKey('comp_reshoot_items', $clientData);
        $this->assertArrayNotHasKey('compensations', $clientData);
        $this->assertArrayNotHasKey('reason_code', $clientData['complimentary_reshoots'][0]);
        $this->assertArrayNotHasKey('reason_note', $clientData['complimentary_reshoots'][0]);
    }

    public function test_non_admin_cannot_use_admin_reshoot_endpoints(): void
    {
        Sanctum::actingAs($this->client);

        $this->getJson(
            "/api/admin/shoots/{$this->sourceShoot->id}/complimentary-reshoots/template"
        )->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $reasonCode): array
    {
        $responsibility = match ($reasonCode) {
            'missed_area', 'quality_correction' => 'photographer',
            'company_error' => 'company',
            'client_accommodation' => 'client',
            'weather_access' => 'weather_access',
            default => 'other',
        };

        return [
            'reason_code' => $reasonCode,
            'reason_note' => $reasonCode === 'other' ? 'Manual review reason.' : null,
            'items' => [[
                'source_shoot_service_id' => $this->sourceItem->id,
                'service_id' => $this->service->id,
                'quantity' => 1,
                'photographer_id' => $this->photographer->id,
                'reason_code' => $reasonCode,
                'responsibility' => $responsibility,
                'photographer_compensation_mode' => in_array($reasonCode, ['missed_area', 'quality_correction'], true)
                    ? ShootCompensation::MODE_NONE
                    : ShootCompensation::MODE_STANDARD,
            ]],
            'sales_rep_compensation_mode' => ShootCompensation::MODE_NONE,
        ];
    }
}
