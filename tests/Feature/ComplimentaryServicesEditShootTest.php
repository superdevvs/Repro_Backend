<?php

namespace Tests\Feature;

use App\Models\CompReshootItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentServiceAllocation;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComplimentaryServicesEditShootTest extends TestCase
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
            'name' => 'Photography',
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
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'company_notes' => 'Before edit',
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

    public static function payToggleChoices(): array
    {
        return [
            'neither recipient paid' => [false, false, ShootCompensation::MODE_NONE, ShootCompensation::MODE_NONE, 0.0, 0.0],
            'photographer only' => [true, false, ShootCompensation::MODE_STANDARD, ShootCompensation::MODE_NONE, 75.0, 0.0],
            'sales rep only' => [false, true, ShootCompensation::MODE_NONE, ShootCompensation::MODE_STANDARD, 0.0, 25.0],
            'both recipients paid' => [true, true, ShootCompensation::MODE_STANDARD, ShootCompensation::MODE_STANDARD, 75.0, 25.0],
        ];
    }

    #[DataProvider('payToggleChoices')]
    public function test_admin_can_add_comp_services_from_edit_with_independent_pay_toggles(
        bool $payPhotographer,
        bool $paySalesRep,
        string $photographerMode,
        string $salesRepMode,
        float $photographerAmount,
        float $salesRepAmount,
    ): void {
        $response = $this->patchJson(
            "/api/shoots/{$this->sourceShoot->id}",
            $this->payload($payPhotographer, $paySalesRep, [
                'company_notes' => 'Saved with comp services',
            ])
        );

        $response->assertOk()
            ->assertJsonPath('data.company_notes', 'Saved with comp services')
            ->assertJsonPath('data.created_complimentary_reshoot.shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
            ->assertJsonPath('data.created_complimentary_reshoot.reshoot_of_shoot_id', $this->sourceShoot->id)
            ->assertJsonPath('data.created_complimentary_reshoot.client_charge_total', 0)
            ->assertJsonPath('data.created_complimentary_reshoot.replayed', false)
            ->assertJsonPath('data.reshoot_summary.complimentary_count', 1);

        $childId = (int) $response->json('data.created_complimentary_reshoot.id');
        $child = Shoot::findOrFail($childId);
        $childItem = $child->serviceItems()->firstOrFail();

        $this->assertSame(Shoot::SHOOT_TYPE_STANDARD, $this->sourceShoot->fresh()->shoot_type);
        $this->assertSame(250.0, (float) $this->sourceShoot->fresh()->total_quote);
        $this->assertSame(1, $this->sourceShoot->serviceItems()->count());
        $this->assertSame(250.0, (float) $this->sourceItem->fresh()->price);
        $this->assertSame(0.0, (float) $child->base_quote);
        $this->assertSame(0.0, (float) $child->tax_amount);
        $this->assertSame(0.0, (float) $child->total_quote);
        $this->assertSame(0.0, (float) $childItem->price);
        $this->assertSame(250.0, (float) $childItem->nominal_value_snapshot);
        $this->assertSame(0.0, (float) $childItem->photographer_pay);
        $this->assertSame($this->service->id, $childItem->service_id);

        $this->assertDatabaseHas('comp_reshoot_items', [
            'shoot_id' => $child->id,
            'shoot_service_id' => $childItem->id,
            'source_shoot_service_id' => $this->sourceItem->id,
            'reason_code' => 'company_error',
            'reason_note' => 'Return visit approved from Edit Shoot.',
        ]);
        $this->assertDatabaseHas('shoot_compensations', [
            'shoot_id' => $child->id,
            'shoot_service_id' => $childItem->id,
            'recipient_type' => ShootCompensation::RECIPIENT_PHOTOGRAPHER,
            'mode' => $photographerMode,
            'amount' => $photographerAmount,
        ]);
        $this->assertDatabaseHas('shoot_compensations', [
            'shoot_id' => $child->id,
            'shoot_service_id' => null,
            'recipient_type' => ShootCompensation::RECIPIENT_SALES_REP,
            'mode' => $salesRepMode,
            'amount' => $salesRepAmount,
        ]);
        $this->assertDatabaseHas('invoices', [
            'shoot_id' => $child->id,
            'document_type' => Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT,
            'payment_required' => false,
            'total_amount' => 0,
        ]);
        $this->assertDatabaseHas('user_activity_logs', [
            'actor_user_id' => $this->admin->id,
            'target_id' => $child->id,
            'event_type' => 'complimentary_reshoot.created',
        ]);
    }

    public function test_edit_comp_mode_is_admin_only(): void
    {
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        Sanctum::actingAs($editingManager);

        $this->patchJson(
            "/api/shoots/{$this->sourceShoot->id}",
            $this->payload(false, false)
        )->assertForbidden()
            ->assertJsonPath('message', 'Only Admin and Super Admin can add complimentary services.');

        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
    }

    public function test_other_reason_requires_a_note(): void
    {
        $payload = $this->payload(false, false);
        $payload['complimentary_service_options']['reason_code'] = 'other';
        unset($payload['complimentary_service_options']['reason_note']);

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('complimentary_service_options.reason_note');

        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
    }

    public function test_comp_options_reject_post_transaction_status_mutations(): void
    {
        $originalStatus = $this->sourceShoot->status;
        $payload = $this->payload(false, false, [
            'status' => Shoot::STATUS_DELIVERED,
        ]);

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('complimentary_service_options');

        $this->assertSame($originalStatus, $this->sourceShoot->fresh()->status);
        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
    }

    public function test_foreign_source_item_rejects_and_rolls_back_ordinary_edits(): void
    {
        $otherShoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
        ]);
        $otherService = Service::factory()->create();
        $foreignItem = ShootService::create([
            'shoot_id' => $otherShoot->id,
            'service_id' => $otherService->id,
            'photographer_id' => $this->photographer->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        $payload = $this->payload(false, false, [
            'company_notes' => 'This must roll back',
        ]);
        $payload['complimentary_service_options']['service_items'][0] = [
            'source_shoot_service_id' => $foreignItem->id,
            'service_id' => $otherService->id,
            'photographer_id' => $this->photographer->id,
        ];

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame('Before edit', $this->sourceShoot->fresh()->company_notes);
        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
        $this->assertSame(0, CompReshootItem::query()->count());
    }

    public function test_unavailable_comp_schedule_rejects_and_rolls_back_ordinary_edits(): void
    {
        $payload = $this->payload(false, false, [
            'company_notes' => 'This must also roll back',
        ]);
        // With no configured slots, the canonical backend fallback window is
        // 09:00-18:00. This must be rejected even for an admin.
        $payload['complimentary_service_options']['scheduled_at'] = '2026-09-15 20:30:00';

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('complimentary_service_options.service_items.0.scheduled_at');

        $this->assertSame('Before edit', $this->sourceShoot->fresh()->company_notes);
        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
        $this->assertSame(0, CompReshootItem::query()->count());
    }

    public function test_conflicting_comp_schedule_rejects_and_rolls_back_ordinary_edits(): void
    {
        $blockingShoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'scheduled_at' => '2026-09-15 10:30:00',
            'scheduled_date' => '2026-09-15',
            'time' => '10:30',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        ShootService::create([
            'shoot_id' => $blockingShoot->id,
            'service_id' => $this->service->id,
            'photographer_id' => $this->photographer->id,
            'price' => 250,
            'quantity' => 1,
            'scheduled_at' => '2026-09-15 10:30:00',
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
        ]);
        $payload = $this->payload(false, false, [
            'company_notes' => 'Conflict must roll this back',
        ]);

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('complimentary_service_options.service_items.0.scheduled_at');

        $this->assertSame('Before edit', $this->sourceShoot->fresh()->company_notes);
        $this->assertSame(0, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
        $this->assertSame(0, CompReshootItem::query()->count());
    }

    public function test_patch_retry_with_same_idempotency_key_does_not_duplicate_child_or_audit(): void
    {
        $payload = $this->payload(true, true);

        $first = $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.created_complimentary_reshoot.replayed', false);
        $childId = (int) $first->json('data.created_complimentary_reshoot.id');

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.created_complimentary_reshoot.id', $childId)
            ->assertJsonPath('data.created_complimentary_reshoot.replayed', true);

        $this->assertSame(1, Shoot::query()->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)->count());
        $this->assertSame(1, Invoice::query()->where('shoot_id', $childId)->count());
        $this->assertSame(1, \App\Models\UserActivityLog::query()
            ->where('event_type', 'complimentary_reshoot.created')
            ->where('target_id', $childId)
            ->count());
    }

    public function test_comp_edit_keeps_the_paid_source_service_invoice_and_allocation_intact(): void
    {
        $sourceInvoice = app(InvoiceService::class)->generateForShoot($this->sourceShoot);
        $payment = Payment::factory()->create([
            'shoot_id' => $this->sourceShoot->id,
            'invoice_id' => $sourceInvoice->id,
            'amount' => 250,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $allocation = PaymentServiceAllocation::create([
            'payment_id' => $payment->id,
            'shoot_service_id' => $this->sourceItem->id,
            'amount' => 250,
        ]);
        $sourceInvoiceState = $sourceInvoice->fresh()->only([
            'id', 'shoot_id', 'total_amount', 'subtotal', 'tax', 'total',
        ]);

        $payload = $this->payload(false, false, [
            // The editor may still submit unchanged non-service fields. The
            // paid service set itself is deliberately absent in Comp Mode.
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'state' => $this->sourceShoot->state,
            'property_details' => $this->sourceShoot->property_details,
        ]);

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.created_complimentary_reshoot.client_charge_total', 0);

        $this->assertSame(250.0, (float) $this->sourceShoot->fresh()->total_quote);
        $this->assertSame(250.0, (float) $this->sourceItem->fresh()->price);
        $this->assertSame(75.0, (float) $this->sourceItem->fresh()->photographer_pay);
        $this->assertSame($sourceInvoiceState, $sourceInvoice->fresh()->only(array_keys($sourceInvoiceState)));
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'shoot_id' => $this->sourceShoot->id,
            'invoice_id' => $sourceInvoice->id,
            'amount' => 250,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('payment_service_allocations', [
            'id' => $allocation->id,
            'payment_id' => $payment->id,
            'shoot_service_id' => $this->sourceItem->id,
            'amount' => 250,
        ]);
    }

    public function test_comp_edit_rejects_mixing_standard_service_changes_into_the_same_save(): void
    {
        $payload = $this->payload(false, false);
        $payload['services'] = [[
            'id' => $this->service->id,
            'price' => 250,
        ]];

        $this->patchJson("/api/shoots/{$this->sourceShoot->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('complimentary_service_options');

        $this->assertSame(250.0, (float) $this->sourceShoot->fresh()->total_quote);
        $this->assertSame(250.0, (float) $this->sourceItem->fresh()->price);
        $this->assertSame(0, Shoot::query()
            ->where('shoot_type', Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT)
            ->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(bool $payPhotographer, bool $paySalesRep, array $extra = []): array
    {
        return array_merge($extra, [
            'complimentary_service_options' => [
                'idempotency_key' => (string) Str::uuid(),
                'reason_code' => 'company_error',
                'reason_note' => 'Return visit approved from Edit Shoot.',
                'pay_photographer' => $payPhotographer,
                'pay_sales_rep' => $paySalesRep,
                'scheduled_at' => '2026-09-15 10:30:00',
                'timezone' => 'America/New_York',
                'photographer_id' => $this->photographer->id,
                'service_items' => [[
                    'source_shoot_service_id' => $this->sourceItem->id,
                    'service_id' => $this->service->id,
                    'photographer_id' => $this->photographer->id,
                ]],
            ],
        ]);
    }
}
