<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\CompReshootItem;
use App\Models\EditorPayout;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PayoutReportService;
use App\Services\ReproAi\Flows\InvoiceBillingFlow;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplimentaryReshootAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_earns_authoritative_compensation_and_generates_separate_payout_lines(): void
    {
        [$shoot, $serviceItem, $photographerCompensation, $repCompensation, $photographer, $rep] = $this->makeCompReshoot();

        $this->assertNull($photographerCompensation->earned_at);
        $this->assertNull($repCompensation->earned_at);

        $deliveredAt = now()->subHour();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => $deliveredAt,
        ]);

        $this->assertNotNull($photographerCompensation->fresh()->earned_at);
        $this->assertNotNull($photographerCompensation->fresh()->locked_at);
        $this->assertNull($repCompensation->fresh()->earned_at, 'Rep compensation waits for whole-shoot delivery.');

        $shoot->update([
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'admin_verified_at' => $deliveredAt,
            'completed_at' => $deliveredAt,
        ]);
        $this->assertNotNull($repCompensation->fresh()->earned_at);
        $this->assertNotNull($repCompensation->fresh()->locked_at);

        // Client cash on the same shoot must never settle either staff payout.
        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 400,
            'currency' => 'USD',
            'square_payment_id' => (string) Str::uuid(),
            'square_order_id' => (string) Str::uuid(),
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $start = now()->subDay()->startOfDay();
        $end = now()->endOfDay();
        $invoiceService = app(InvoiceService::class);
        $invoiceService->generateForPeriod($start, $end);
        $invoiceService->generateSalesRepInvoicesForPeriod($start, $end);

        $photographerInvoice = Invoice::query()
            ->where('role', Invoice::ROLE_PHOTOGRAPHER)
            ->where('photographer_id', $photographer->id)
            ->firstOrFail();
        $this->assertSame(120.0, (float) $photographerInvoice->total_amount);
        $this->assertSame(0.0, (float) $photographerInvoice->amount_paid);
        $this->assertFalse($photographerInvoice->is_paid);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $photographerInvoice->id,
            'shoot_compensation_id' => $photographerCompensation->id,
            'total_amount' => 120,
        ]);

        $repInvoice = Invoice::query()
            ->where('role', Invoice::ROLE_SALES_REP)
            ->where('sales_rep_id', $rep->id)
            ->firstOrFail();
        $repItem = $repInvoice->items()->firstOrFail();
        $this->assertSame(25.0, (float) $repInvoice->total_amount);
        $this->assertSame(0.0, (float) $repItem->meta['commissionable_gross']);
        $this->assertSame(0.0, (float) $repItem->meta['commission_amount']);
        $this->assertSame(25.0, (float) $repItem->meta['compensation_amount']);

        // Regeneration recalculates the same weekly invoices and preserves the
        // one-to-one compensation linkage.
        $invoiceService->generateForPeriod($start, $end);
        $invoiceService->generateSalesRepInvoicesForPeriod($start, $end);
        $this->assertSame(1, Invoice::where('role', Invoice::ROLE_PHOTOGRAPHER)->count());
        $this->assertSame(1, Invoice::where('role', Invoice::ROLE_SALES_REP)->count());
        $this->assertSame(1, $photographerCompensation->invoiceItem()->count());
        $this->assertSame(1, $repCompensation->invoiceItem()->count());
    }

    public function test_payout_requires_accounts_approval_and_compensation_status_is_derived_from_invoice(): void
    {
        [$shoot, $serviceItem, $photographerCompensation, , $photographer] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);

        app(InvoiceService::class)->generateForPeriod(now()->subDay(), now()->addDay());
        $invoice = Invoice::where('photographer_id', $photographer->id)->firstOrFail();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/invoices/{$invoice->id}/mark-paid", ['amount_paid' => 120])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Accounts approval'));
        $this->assertSame(ShootCompensation::STATUS_INVOICED, $photographerCompensation->fresh()->payout_status);

        $invoice->update(['approval_status' => Invoice::APPROVAL_STATUS_APPROVED]);
        $this->assertSame(ShootCompensation::STATUS_APPROVED, $photographerCompensation->fresh()->payout_status);

        $this->patchJson("/api/admin/invoices/{$invoice->id}/mark-paid", ['amount_paid' => 120])
            ->assertOk()
            ->assertJsonPath('data.is_paid', true);
        $this->assertSame(ShootCompensation::STATUS_PAID, $photographerCompensation->fresh()->payout_status);
        $this->assertNull($shoot->fresh()->photographer_paid_at, 'Comp reshoots use line-level settlement, not a whole-shoot flag.');
    }

    public function test_client_document_is_a_zero_dollar_no_payment_required_receipt_with_internal_nominal_metadata(): void
    {
        [$shoot] = $this->makeCompReshoot();

        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $item = $invoice->items()->firstOrFail();

        $this->assertSame(Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT, $invoice->document_type);
        $this->assertFalse($invoice->payment_required);
        $this->assertFalse($invoice->is_paid);
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertNull($invoice->due_date);
        $this->assertSame(0.0, (float) $invoice->subtotal);
        $this->assertSame(0.0, (float) $invoice->tax);
        $this->assertSame(0.0, (float) $invoice->total_amount);
        $this->assertSame(0.0, (float) $item->unit_amount);
        $this->assertSame(0.0, (float) $item->total_amount);
        $this->assertSame(400.0, (float) $item->meta['nominal_total_amount']);
        $this->assertSame('company_error', $item->meta['reason_code']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/admin/invoices/{$invoice->id}/mark-paid")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'does not require payment'));

        $billing = app(\App\Services\ClientBillingService::class)->getClientBilling($shoot->client);
        $receipt = collect($billing['items'])->firstWhere('invoiceId', $invoice->id);
        $this->assertSame('no_payment_required', $receipt['status']);
        $this->assertFalse($receipt['paymentRequired']);
        $this->assertSame(0, $billing['summary']['paid']['count']);
        $this->assertSame(1, $billing['summary']['noPaymentRequired']['count']);

        $download = $this->get("/api/invoices/{$invoice->id}/download")->assertOk();
        $rows = collect(preg_split('/\r\n|\r|\n/', trim($download->streamedContent())))
            ->map(fn (string $line) => str_getcsv($line))
            ->filter(fn (array $row) => isset($row[0]) && $row[0] !== '')
            ->keyBy(fn (array $row) => $row[0]);
        $this->assertSame('Complimentary Receipt', $rows['Document Type'][1]);
        $this->assertSame('No', $rows['Payment Required'][1]);
        $this->assertSame('No Payment Required', $rows['Status'][1]);
        $this->assertFalse($rows->has('Paid'));
    }

    public function test_client_collection_and_billable_adjustment_endpoints_reject_comp_even_if_totals_are_corrupted(): void
    {
        [$shoot] = $this->makeCompReshoot();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Simulate legacy/manual corruption without invoking the model's zeroing invariant.
        DB::table('shoots')->where('id', $shoot->id)->update([
            'base_quote' => 100,
            'total_quote' => 100,
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);

        $this->postJson("/api/shoots/{$shoot->id}/payment-intents", [
            'payment_method' => 'cash',
            'amount' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'payment_type' => 'cash',
            'amount' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-checkout", [
            'amount' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->assertDatabaseCount('payments', 0);

        $legacyPendingIntent = Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 25,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'status' => Payment::STATUS_PENDING,
        ]);
        $this->postJson(
            "/api/shoots/{$shoot->id}/payment-intents/{$legacyPendingIntent->id}/confirm"
        )->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame(Payment::STATUS_PENDING, $legacyPendingIntent->fresh()->status);

        $invoice = app(InvoiceService::class)->generateForShoot($shoot->fresh());
        DB::table('invoices')->where('id', $invoice->id)->update([
            'payment_required' => true,
            'subtotal' => 100,
            'total' => 100,
            'total_amount' => 100,
        ]);

        $this->patchJson("/api/admin/invoices/{$invoice->id}/mark-paid", [
            'amount_paid' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Improper client charge',
            'amount' => 25,
            'quantity' => 1,
            'bills_client' => true,
            'shoot_id' => $shoot->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('bills_client');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Improper client charge',
        ]);
    }

    public function test_normal_update_cannot_reclassify_reprice_or_change_service_lines_on_comp_reshoot(): void
    {
        [$shoot, $serviceItem] = $this->makeCompReshoot();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
        ])->assertUnprocessable()->assertJsonValidationErrors('shoot_type');

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'total_quote' => 25,
        ])->assertUnprocessable()->assertJsonValidationErrors('total_quote');

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [[
                'id' => $serviceItem->service_id,
                'price' => 0,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('services');

        $shoot->refresh();
        $this->assertSame(Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT, $shoot->shoot_type);
        $this->assertSame(0.0, (float) $shoot->total_quote);
        $this->assertSame(1, $shoot->serviceItems()->count());
    }

    public function test_delivered_shoot_status_earns_rep_compensation(): void
    {
        [$shoot, , , $repCompensation] = $this->makeCompReshoot();
        $this->assertNull($repCompensation->earned_at);

        $shoot->update(['status' => Shoot::STATUS_DELIVERED]);

        $this->assertNotNull($repCompensation->fresh()->earned_at);
        $this->assertNotNull($repCompensation->fresh()->locked_at);
    }

    public function test_payout_report_has_separate_complimentary_reshoot_accounting_summary(): void
    {
        [$shoot, $serviceItem] = $this->makeCompReshoot();
        $editor = User::factory()->create(['role' => 'editor']);
        EditorPayout::create([
            'editor_id' => $editor->id,
            'shoot_id' => $shoot->id,
            'service_id' => $serviceItem->service_id,
            'service_name' => 'Photo editing',
            'quantity_snapshot' => 1,
            'rate_snapshot' => 30,
            'payout_amount' => 30,
            'completed_at' => now(),
            'is_paid' => false,
        ]);
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        $shoot->update([
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'admin_verified_at' => now(),
            'completed_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/payout-report?role=all&start='.
            now()->subDay()->toDateString().'&end='.now()->addDay()->toDateString());

        $response->assertOk()
            ->assertJsonPath('complimentary_reshoots.shoot_count', 1)
            ->assertJsonPath('complimentary_reshoots.nominal_value_comped', 400)
            ->assertJsonPath('complimentary_reshoots.photographer_compensation', 120)
            ->assertJsonPath('complimentary_reshoots.sales_rep_compensation', 25)
            ->assertJsonPath('complimentary_reshoots.actual_editor_cost', 30)
            ->assertJsonPath('complimentary_reshoots.total_company_comp_cost', 175)
            ->assertJsonPath('complimentary_reshoots.revenue', 0)
            ->assertJsonPath('complimentary_reshoots.cash_collected', 0)
            ->assertJsonPath('complimentary_reshoots.accounts_receivable', 0)
            ->assertJsonPath('complimentary_reshoots.margin', null)
            ->assertJsonPath('complimentary_reshoots.margin_status', 'not_applicable')
            ->assertJsonPath('complimentary_reshoots.margin_display', 'N/A');

        $csv = $this->get('/api/admin/payout-report/download?role=all&start='.
            now()->subDay()->toDateString().'&end='.now()->addDay()->toDateString())
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('COMPLIMENTARY RESHOOT ROLLUP', $csv);
        $this->assertStringContainsString('"Nominal service value comped",400.00', $csv);
    }

    public function test_payout_report_keeps_rep_compensation_separate_from_commission(): void
    {
        [, $serviceItem, , $repCompensation, , $rep] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        $repCompensation->shoot->update([
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'admin_verified_at' => now(),
            'completed_at' => now(),
        ]);

        $summary = app(PayoutReportService::class)
            ->buildSalesRepSummaries(now()->subDay(), now()->addDay())
            ->firstWhere('id', $rep->id);

        $this->assertNotNull($summary);
        $this->assertSame(0.0, (float) $summary['gross_total']);
        $this->assertSame(0.0, (float) $summary['commission_total']);
        $this->assertSame(25.0, (float) $summary['compensation_total']);
        $this->assertSame(25.0, (float) $summary['payout_total']);
        $this->assertSame(1, $summary['shoot_count']);
    }

    public function test_current_payout_rollup_reconciles_a_late_adjustment_for_an_old_comp_shoot(): void
    {
        [$shoot, $serviceItem, $original, , $photographer] = $this->makeCompReshoot();
        $oldDate = now()->subMonths(2);
        $shoot->forceFill([
            'scheduled_at' => $oldDate,
            'scheduled_date' => $oldDate->toDateString(),
        ])->saveQuietly();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => $oldDate,
        ]);
        $original->forceFill([
            'earned_at' => $oldDate,
            'locked_at' => $oldDate,
        ])->saveQuietly();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            [
                'line_type' => ShootCompensation::LINE_TYPE_ADJUSTMENT,
                'amount' => 20,
                'note' => 'Late mileage correction.',
                'idempotency_key' => (string) Str::uuid(),
            ]
        )->assertCreated();

        $service = app(PayoutReportService::class);
        $start = now()->subDay();
        $end = now()->addDay();
        $photographerSummary = $service->buildPhotographerSummaries($start, $end)
            ->firstWhere('id', $photographer->id);
        $compRollup = $service->buildComplimentaryReshootSummary($start, $end);

        $this->assertSame(20.0, (float) $photographerSummary['compensation_total']);
        $this->assertSame(20.0, (float) $compRollup['photographer_compensation']);
        $this->assertSame(0, $compRollup['shoot_count']);
        $this->assertSame(0.0, (float) $compRollup['nominal_value_comped']);
        $this->assertSame('shoot_schedule', $compRollup['nominal_period_basis']);
        $this->assertSame('earned_or_completed', $compRollup['cost_period_basis']);
    }

    public function test_payout_report_includes_an_active_secondary_role_sales_rep(): void
    {
        [$shoot, , , , , $rep] = $this->makeCompReshoot();
        $rep->forceFill([
            'role' => 'client',
            'secondary_roles' => ['sales_rep'],
            'account_status' => 'active',
        ])->save();
        $shoot->update([
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'admin_verified_at' => now(),
            'completed_at' => now(),
        ]);

        $summary = app(PayoutReportService::class)
            ->buildSalesRepSummaries(now()->subDay(), now()->addDay())
            ->firstWhere('id', $rep->id);

        $this->assertNotNull($summary);
        $this->assertSame(25.0, (float) $summary['compensation_total']);
        $this->assertSame(0.0, (float) $summary['gross_total']);
    }

    public function test_cancelling_before_delivery_voids_planned_compensation_and_generates_no_payout(): void
    {
        [$shoot, , $photographerCompensation, $repCompensation] = $this->makeCompReshoot();

        $shoot->update([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
        ]);

        $this->assertNotNull($photographerCompensation->fresh()->voided_at);
        $this->assertNotNull($repCompensation->fresh()->voided_at);
        $this->assertNull($photographerCompensation->fresh()->earned_at);
        $this->assertStringContainsString('cancelled', $photographerCompensation->fresh()->void_reason);

        $service = app(InvoiceService::class);
        $this->assertCount(0, $service->generateForPeriod(now()->subDay(), now()->addDay()));
        $this->assertCount(0, $service->generateSalesRepInvoicesForPeriod(now()->subDay(), now()->addDay()));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_robbie_client_flow_cannot_mutate_comp_services_or_restore_positive_pricing(): void
    {
        [$shoot, $serviceItem] = $this->makeCompReshoot();
        $otherService = Service::factory()->create(['price' => 999]);

        try {
            app(\App\Services\ReproAi\ShootService::class)->updateFromAiConversation(
                $shoot,
                ['service_ids' => [$otherService->id]],
                $shoot->client
            );
            $this->fail('Expected Robbie complimentary-reshoot service guard to reject the mutation.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('services', $exception->errors());
        }

        $shoot->refresh();
        $this->assertSame(0.0, (float) $shoot->base_quote);
        $this->assertSame(0.0, (float) $shoot->total_quote);
        $this->assertSame([$serviceItem->service_id], $shoot->serviceItems()->pluck('service_id')->all());
        $this->assertSame(0.0, (float) $shoot->serviceItems()->firstOrFail()->price);
    }

    public function test_robbie_billing_flow_preserves_the_direct_zero_receipt_and_blocks_repricing(): void
    {
        [$shoot] = $this->makeCompReshoot();
        $createSession = AiChatSession::create([
            'user_id' => $shoot->client_id,
            'title' => 'Client billing',
            'topic' => 'general',
            'step' => 'create_invoice',
            'state_data' => ['shoot_id' => $shoot->id],
        ]);

        $createResult = app(InvoiceBillingFlow::class)->handle($createSession, 'create invoice');

        $this->assertFalse(data_get($createResult, 'assistant_messages.0.metadata.payment_required'));
        $this->assertSame(
            Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT,
            data_get($createResult, 'assistant_messages.0.metadata.document_type')
        );
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', [
            'shoot_id' => $shoot->id,
            'document_type' => Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT,
            'payment_required' => false,
            'total_amount' => 0,
        ]);

        // Simulate legacy-corrupt pricing and prove Robbie cannot mutate it or
        // turn it into a client-facing bill through the discount shortcut.
        DB::table('shoots')->where('id', $shoot->id)->update(['total_quote' => 500]);
        $discountSession = AiChatSession::create([
            'user_id' => $shoot->client_id,
            'title' => 'Client billing',
            'topic' => 'general',
            'step' => 'apply_discount',
            'state_data' => [
                'shoot_id' => $shoot->id,
                'discount_amount' => 100,
            ],
        ]);

        $discountResult = app(InvoiceBillingFlow::class)->handle($discountSession, 'apply $100');

        $this->assertTrue(data_get($discountResult, 'assistant_messages.0.metadata.blocked'));
        $this->assertSame(500.0, (float) $shoot->fresh()->total_quote);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_unlocked_service_reassignment_atomically_moves_compensation_recipient_and_locked_work_blocks_it(): void
    {
        [$shoot, $serviceItem, $compensation] = $this->makeCompReshoot();
        $replacement = User::factory()->photographer()->create();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $serviceItem->service_id,
            'photographer_id' => $replacement->id,
        ])->assertOk();

        $this->assertSame($replacement->id, (int) $serviceItem->fresh()->photographer_id);
        $this->assertSame($replacement->id, (int) $compensation->fresh()->recipient_user_id);
        $this->assertSame($admin->id, (int) $compensation->fresh()->updated_by);

        $serviceItem->fresh()->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        $third = User::factory()->photographer()->create();

        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $serviceItem->service_id,
            'photographer_id' => $third->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('service_photographers');

        $this->assertSame($replacement->id, (int) $serviceItem->fresh()->photographer_id);
        $this->assertSame($replacement->id, (int) $compensation->fresh()->recipient_user_id);
    }

    public function test_assignment_after_booking_populates_an_unpaid_none_decision_recipient(): void
    {
        [$shoot, $serviceItem, $compensation] = $this->makeCompReshoot();
        $compensation->update([
            'mode' => ShootCompensation::MODE_NONE,
            'amount' => 0,
            'recipient_user_id' => null,
        ]);
        $serviceItem->update(['photographer_id' => null]);
        $replacement = User::factory()->photographer()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $serviceItem->service_id,
            'photographer_id' => $replacement->id,
        ])->assertOk();

        $this->assertSame($replacement->id, (int) $serviceItem->fresh()->photographer_id);
        $this->assertSame($replacement->id, (int) $compensation->fresh()->recipient_user_id);
    }

    public function test_service_assignment_rejects_a_non_photographer_compensation_recipient(): void
    {
        [$shoot, $serviceItem, $compensation] = $this->makeCompReshoot();
        $invalidRecipient = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $serviceItem->service_id,
            'photographer_id' => $invalidRecipient->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('photographer_id');

        $this->assertNotSame($invalidRecipient->id, (int) $serviceItem->fresh()->photographer_id);
        $this->assertNotSame($invalidRecipient->id, (int) $compensation->fresh()->recipient_user_id);
    }

    public function test_internal_service_attach_cannot_bypass_fixed_comp_lines_but_can_reschedule_them(): void
    {
        [$shoot, $serviceItem] = $this->makeCompReshoot();
        $replacementService = Service::factory()->create(['price' => 999]);
        $support = app(ShootMutationSupportService::class);

        try {
            $support->attachServices($shoot, [[
                'id' => $replacementService->id,
                'price' => 0,
                'quantity' => 1,
            ]]);
            $this->fail('Expected the internal comp service-set guard to reject replacement.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('services', $exception->errors());
        }

        $rescheduledAt = now()->addDays(3)->startOfHour();
        $support->attachServices($shoot, [[
            'id' => $serviceItem->service_id,
            'price' => 0,
            'quantity' => 1,
            'photographer_id' => $serviceItem->photographer_id,
            'scheduled_at' => $rescheduledAt,
        ]]);

        $this->assertSame([$serviceItem->service_id], $shoot->serviceItems()->pluck('service_id')->all());
        $this->assertSame(
            $rescheduledAt->format('Y-m-d H:i:s'),
            $serviceItem->fresh()->scheduled_at?->format('Y-m-d H:i:s')
        );
    }

    public function test_comp_shoot_and_any_source_with_reshoot_descendants_cannot_be_hard_deleted(): void
    {
        [$compShoot] = $this->makeCompReshoot();
        $source = Shoot::factory()->create(['client_id' => $compShoot->client_id]);
        $compShoot->update([
            'reshoot_of_shoot_id' => $source->id,
            'root_shoot_id' => $source->id,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/shoots/{$source->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shoot');
        $this->deleteJson("/api/shoots/{$compShoot->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shoot');

        $this->assertDatabaseHas('shoots', ['id' => $source->id]);
        $this->assertDatabaseHas('shoots', ['id' => $compShoot->id]);

        foreach ([$source->id, $compShoot->id] as $protectedShootId) {
            $constraintBlockedDelete = false;
            try {
                DB::table('shoots')->where('id', $protectedShootId)->delete();
            } catch (QueryException) {
                $constraintBlockedDelete = true;
            }
            $this->assertTrue(
                $constraintBlockedDelete,
                'Database constraints must protect complimentary-reshoot audit rows when observers are bypassed.'
            );
        }

        $compShoot->update([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
        ]);
        $this->assertSame(Shoot::STATUS_CANCELLED, $compShoot->fresh()->status);
    }

    public function test_period_client_invoice_excludes_comp_and_preserves_its_only_direct_receipt(): void
    {
        [$compShoot] = $this->makeCompReshoot();
        $receipt = app(InvoiceService::class)->generateForShoot($compShoot);
        $standardService = Service::factory()->create(['price' => 180]);
        $standard = Shoot::factory()->create([
            'client_id' => $compShoot->client_id,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'scheduled_date' => now()->toDateString(),
            'base_quote' => 180,
            'tax_amount' => 0,
            'total_quote' => 180,
        ]);
        $standard->services()->attach($standardService->id, [
            'price' => 180,
            'quantity' => 1,
        ]);

        $periodInvoice = app(InvoiceService::class)->generateInvoice(
            $compShoot->client,
            Invoice::ROLE_CLIENT,
            now()->subDay(),
            now()->addDay()
        );

        $this->assertNotSame($receipt->id, $periodInvoice->id);
        $this->assertSame([$standard->id], $periodInvoice->shoots()->pluck('shoots.id')->all());
        $this->assertSame(180.0, (float) $periodInvoice->total_amount);
        $this->assertSame(Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT, $receipt->fresh()->document_type);
        $this->assertFalse($receipt->fresh()->payment_required);
        $this->assertSame(1, Invoice::query()
            ->where('shoot_id', $compShoot->id)
            ->where('document_type', Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT)
            ->count());

        [$onlyComp] = $this->makeCompReshoot();
        $onlyReceipt = app(InvoiceService::class)->generateForShoot($onlyComp);
        $periodResult = app(InvoiceService::class)->generateInvoice(
            $onlyComp->client,
            Invoice::ROLE_CLIENT,
            now()->subDay(),
            now()->addDay()
        );
        $this->assertSame($onlyReceipt->id, $periodResult->id);
        $this->assertSame(1, Invoice::where('user_id', $onlyComp->client_id)->count());
    }

    public function test_locked_compensation_supports_idempotent_corrections_reversals_and_payout_replay(): void
    {
        [$shoot, $serviceItem, $original, , $photographer] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $correctionPayload = [
            'line_type' => ShootCompensation::LINE_TYPE_ADJUSTMENT,
            'amount' => 30,
            'note' => 'Approved travel correction.',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $correction = $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            $correctionPayload
        )->assertCreated()
            ->assertJsonPath('data.line_type', ShootCompensation::LINE_TYPE_ADJUSTMENT)
            ->assertJsonPath('data.amount', 30)
            ->json('data');
        $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            $correctionPayload
        )->assertOk()->assertJsonPath('meta.replayed', true);

        $reversalPayload = [
            'line_type' => ShootCompensation::LINE_TYPE_REVERSAL,
            'amount' => 50,
            'note' => 'Reverse excess approved compensation.',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $reversal = $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            $reversalPayload
        )->assertCreated()
            ->assertJsonPath('data.amount', -50)
            ->json('data');

        $this->assertDatabaseHas('shoot_compensations', [
            'id' => $correction['id'],
            'adjusts_compensation_id' => $original->id,
            'amount' => 30,
        ]);
        $this->assertDatabaseHas('shoot_compensations', [
            'id' => $reversal['id'],
            'adjusts_compensation_id' => $original->id,
            'amount' => -50,
        ]);

        $invoiceService = app(InvoiceService::class);
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $invoice = Invoice::where('photographer_id', $photographer->id)->firstOrFail();
        $this->assertSame(100.0, (float) $invoice->fresh()->total_amount);
        $this->assertSame(3, $invoice->items()->whereNotNull('shoot_compensation_id')->count());
        $this->assertSame(1, DB::table('payout_generation_locks')
            ->where('recipient_role', Invoice::ROLE_PHOTOGRAPHER)
            ->count());

        $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            [
                'line_type' => ShootCompensation::LINE_TYPE_REVERSAL,
                'amount' => 101,
                'note' => 'Invalid over-reversal.',
                'idempotency_key' => (string) Str::uuid(),
            ]
        )->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_late_compensation_adjustment_is_earned_in_the_current_payout_period(): void
    {
        [$shoot, $serviceItem, $original] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now()->subMonths(2),
        ]);
        $original->forceFill([
            'earned_at' => now()->subMonths(2),
            'locked_at' => now()->subMonths(2),
        ])->saveQuietly();
        Sanctum::actingAs(User::factory()->admin()->create());

        $adjustmentId = $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            [
                'line_type' => ShootCompensation::LINE_TYPE_ADJUSTMENT,
                'amount' => 20,
                'note' => 'Late approved accounting correction.',
                'idempotency_key' => (string) Str::uuid(),
            ]
        )->assertCreated()->json('data.id');

        $adjustment = ShootCompensation::findOrFail($adjustmentId);
        $this->assertTrue($adjustment->earned_at->isToday());
        $this->assertTrue($adjustment->locked_at->isToday());
        $this->assertTrue($adjustment->earned_at->greaterThan($original->fresh()->earned_at));
    }

    public function test_correction_after_paid_original_creates_supplemental_invoice_without_mutating_original(): void
    {
        [$shoot, $serviceItem, $original, , $photographer] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $paidInvoice = Invoice::where('photographer_id', $photographer->id)->firstOrFail();
        $paidInvoice->forceFill([
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_PAID,
            'is_paid' => true,
            'amount_paid' => 120,
            'paid_at' => now(),
        ])->save();

        Sanctum::actingAs(User::factory()->admin()->create());
        $adjustmentId = $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            [
                'line_type' => ShootCompensation::LINE_TYPE_ADJUSTMENT,
                'amount' => 20,
                'note' => 'Late approved mileage correction.',
                'idempotency_key' => (string) Str::uuid(),
            ]
        )->assertCreated()->json('data.id');

        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());

        $invoices = Invoice::where('photographer_id', $photographer->id)->orderBy('id')->get();
        $this->assertCount(2, $invoices);
        $this->assertSame(120.0, (float) $invoices->first()->fresh()->total_amount);
        $this->assertSame(20.0, (float) $invoices->last()->fresh()->total_amount);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoices->last()->id,
            'shoot_compensation_id' => $adjustmentId,
            'total_amount' => 20,
        ]);
        $this->assertSame($paidInvoice->id, $original->fresh()->invoiceItem->invoice_id);
    }

    public function test_approved_standalone_reversal_invoice_can_settle_without_a_cash_payment(): void
    {
        [$shoot, $serviceItem, $original, , $photographer] = $this->makeCompReshoot();
        $serviceItem->update([
            'workflow_status' => ShootService::WORKFLOW_DELIVERED,
            'delivery_status' => ShootService::DELIVERY_DELIVERED,
            'delivered_at' => now(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $originalInvoice = Invoice::where('photographer_id', $photographer->id)->firstOrFail();
        $originalInvoice->forceFill([
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_PAID,
            'is_paid' => true,
            'amount_paid' => 120,
            'paid_at' => now(),
        ])->save();
        Sanctum::actingAs(User::factory()->admin()->create());

        $reversalId = $this->postJson(
            "/api/admin/shoots/{$shoot->id}/compensations/{$original->id}/adjustments",
            [
                'line_type' => ShootCompensation::LINE_TYPE_REVERSAL,
                'amount' => 20,
                'note' => 'Approved post-payment reversal.',
                'idempotency_key' => (string) Str::uuid(),
            ]
        )->assertCreated()->json('data.id');
        $invoiceService->generateForPeriod(now()->subDay(), now()->addDay());
        $reversalInvoice = Invoice::where('photographer_id', $photographer->id)
            ->where('id', '!=', $originalInvoice->id)
            ->firstOrFail();
        $this->assertSame(-20.0, (float) $reversalInvoice->total_amount);
        $reversalInvoice->forceFill([
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
        ])->save();

        $this->postJson("/api/admin/invoices/{$reversalInvoice->id}/mark-paid")
            ->assertOk();

        $reversalInvoice->refresh();
        $this->assertTrue((bool) $reversalInvoice->is_paid);
        $this->assertSame(Invoice::STATUS_PAID, $reversalInvoice->status);
        $this->assertSame(0.0, (float) $reversalInvoice->amount_paid);
        $this->assertNotNull($reversalInvoice->paid_at);
        $this->assertSame(
            ShootCompensation::STATUS_PAID,
            ShootCompensation::findOrFail($reversalId)->payout_status
        );
        $this->assertDatabaseCount('payments', 0);
    }

    private function makeCompReshoot(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $rep = User::factory()->create([
            'role' => 'salesRep',
            'account_status' => 'active',
            'metadata' => ['repDetails' => ['commissionPercentage' => 15]],
        ]);
        $service = Service::factory()->create(['name' => 'Photo package', 'price' => 400]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'rep_id' => $rep->id,
            'service_id' => $service->id,
            'shoot_type' => Shoot::SHOOT_TYPE_COMPLIMENTARY_RESHOOT,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::WORKFLOW_BOOKED,
            'scheduled_date' => now()->toDateString(),
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 0,
            'nominal_value_snapshot' => 400,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
            'workflow_status' => ShootService::WORKFLOW_PENDING,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
        ]);
        $serviceItem = $shoot->serviceItems()->firstOrFail();

        CompReshootItem::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $serviceItem->id,
            'service_id_snapshot' => $service->id,
            'service_name_snapshot' => $service->name,
            'nominal_unit_price_snapshot' => 400,
            'quantity_snapshot' => 1,
            'nominal_total_snapshot' => 400,
            'reason_code' => 'company_error',
            'responsibility' => CompReshootItem::RESPONSIBILITY_COMPANY,
        ]);

        $photographerCompensation = ShootCompensation::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $serviceItem->id,
            'scope_key' => ShootCompensation::serviceScopeKey($serviceItem->id),
            'recipient_type' => ShootCompensation::RECIPIENT_PHOTOGRAPHER,
            'recipient_user_id' => $photographer->id,
            'mode' => ShootCompensation::MODE_STANDARD,
            'calculation_method' => ShootCompensation::CALCULATION_FIXED,
            'quantity_snapshot' => 1,
            'basis_amount_snapshot' => 400,
            'rate_snapshot' => 120,
            'amount' => 120,
            'reason_code' => 'company_error',
            'policy_version' => '2026-09-01',
        ]);
        $repCompensation = ShootCompensation::create([
            'shoot_id' => $shoot->id,
            'scope_key' => ShootCompensation::shootScopeKey(),
            'recipient_type' => ShootCompensation::RECIPIENT_SALES_REP,
            'recipient_user_id' => $rep->id,
            'mode' => ShootCompensation::MODE_CUSTOM,
            'calculation_method' => ShootCompensation::CALCULATION_FIXED,
            'quantity_snapshot' => 1,
            'basis_amount_snapshot' => 400,
            'rate_snapshot' => 25,
            'amount' => 25,
            'reason_code' => 'company_error',
            'policy_version' => '2026-09-01',
        ]);

        return [$shoot->fresh(['client']), $serviceItem->fresh(), $photographerCompensation->fresh(), $repCompensation->fresh(['shoot']), $photographer, $rep];
    }
}
