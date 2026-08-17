<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PublicPaymentAccessToken;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceShootOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $photographer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->create(['role' => 'photographer']);
        $this->service = Service::factory()->create(['name' => 'HDR Photos', 'price' => 100]);

        Sanctum::actingAs($this->admin);
    }

    public function test_billable_adjustment_is_a_structured_order_line_across_shoot_endpoints(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();
        $shoot->forceFill(['cancellation_requested_at' => now()])->save();

        $response = $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Virtual Staging Charge',
            'amount' => 20,
            'quantity' => 2,
            'bills_client' => true,
            'charge_type' => 'virtual_staging',
        ]);

        $response->assertCreated()
            ->assertJsonPath('invoice.shoot.id', $shoot->id);
        $this->assertSame(140.0, (float) $response->json('invoice.subtotal'));
        $this->assertSame(146.0, (float) $response->json('invoice.total'));
        $this->assertSame(146.0, (float) $response->json('invoice.total_amount'));
        $this->assertSame(146.0, (float) $response->json('invoice.balance_due'));
        $this->assertSame(146.0, (float) $response->json('invoice.shoot.total_quote'));

        $this->assertSame(146.0, (float) $shoot->fresh()->total_quote);

        $show = $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->json('data');
        $this->assertOrderContainsAdjustment($show, 'Virtual Staging Charge', 2, 20.0, 40.0);

        $listing = $this->getJson('/api/shoots?tab=scheduled')->assertOk()->json('data.0');
        $this->assertOrderContainsAdjustment($listing, 'Virtual Staging Charge', 2, 20.0, 40.0);

        $resource = $this->getJson('/api/shoots/pending-cancellations')->assertOk()->json('data.0');
        $this->assertOrderContainsAdjustment($resource, 'Virtual Staging Charge', 2, 20.0, 40.0);

        $payment = $this->getJson("/api/shoots/{$shoot->id}/payment-details")->assertOk()->json('data');
        $this->assertOrderContainsAdjustment($payment, 'Virtual Staging Charge', 2, 20.0, 40.0);
        $this->assertContains('Virtual Staging Charge', collect($payment['services'])->pluck('name')->all());

        $paymentToken = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id,
            'created_by' => $this->admin->id,
        ]);
        $publicPayment = $this->getJson("/api/public/payments/{$paymentToken->token}")
            ->assertOk()
            ->json('data');
        $this->assertOrderContainsAdjustment($publicPayment, 'Virtual Staging Charge', 2, 20.0, 40.0);
        $this->assertContains('Virtual Staging Charge', collect($publicPayment['services'])->pluck('name')->all());

        $history = $this->getJson('/api/shoots/history?per_page=25')->assertOk()->json('data.0');
        $this->assertContains('Virtual Staging Charge', $history['services']);
        $this->assertOrderContainsAdjustment($history, 'Virtual Staging Charge', 2, 20.0, 40.0);

        $dashboard = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.upcoming_shoots.0.services');
        $dashboardLine = collect($dashboard)->firstWhere('label', 'Virtual Staging Charge');
        $this->assertNotNull($dashboardLine);
        $this->assertSame(2, $dashboardLine['quantity']);
        $this->assertSame(40.0, (float) $dashboardLine['total_amount']);
        $this->assertSame('virtual_staging', $dashboardLine['charge_type']);

        $csv = $this->get('/api/shoots/history/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Virtual Staging Charge', $csv);

        Sanctum::actingAs($this->client);
        $clientShoot = $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->json('data');
        $this->assertOrderContainsAdjustment($clientShoot, 'Virtual Staging Charge', 2, 20.0, 40.0);

        Sanctum::actingAs($this->photographer);
        $photographerShoot = $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->json('data');
        $this->assertNotContains(
            'Virtual Staging Charge',
            collect($photographerShoot['serviceItems'] ?? [])->pluck('name')->all()
        );
    }

    public function test_adjustments_are_aggregated_from_every_invoice_and_display_only_items_stay_invoice_only(): void
    {
        [$shoot, $olderInvoice] = $this->createShootAndInvoice();
        $olderInvoice->forceFill(['created_at' => now()->subDay()])->save();
        [, $newerInvoice] = $this->createInvoiceForShoot($shoot, 'INV-SECOND');

        $this->postJson("/api/admin/invoices/{$newerInvoice->id}/misc-items", [
            'description' => 'Second Invoice Billable Item',
            'amount' => 25,
            'bills_client' => true,
        ])->assertCreated();

        $this->postJson("/api/admin/invoices/{$olderInvoice->id}/misc-items", [
            'description' => 'Internal Note Only',
            'amount' => 999,
            'bills_client' => false,
        ])->assertCreated();

        $unchangedDisplayOnlyInvoice = $olderInvoice->fresh();
        $this->assertSame(106.0, (float) $unchangedDisplayOnlyInvoice->total);
        $this->assertSame(106.0, (float) $unchangedDisplayOnlyInvoice->balance_due);
        $this->assertSame(Invoice::STATUS_SENT, $unchangedDisplayOnlyInvoice->status);

        $show = $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->json('data');
        $names = collect($show['serviceItems'])->pluck('name')->all();

        $this->assertContains('Second Invoice Billable Item', $names);
        $this->assertNotContains('Internal Note Only', $names);
        $this->assertContains('Second Invoice Billable Item', $show['services_list']);
        $this->assertNotContains('Internal Note Only', $show['services_list']);
    }

    public function test_same_total_edit_and_delete_invalidate_cached_order_projections(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();

        $added = $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Original Adjustment Name',
            'amount' => 30,
            'bills_client' => true,
        ])->assertCreated();
        $itemId = $added->json('item.id');

        $this->getJson('/api/shoots?tab=scheduled')->assertOk();
        $this->getJson('/api/dashboard/overview')->assertOk();

        $this->patchJson("/api/admin/invoices/{$invoice->id}/misc-items/{$itemId}", [
            'description' => 'Renamed Adjustment',
            'amount' => 30,
            'quantity' => 1,
            'bills_client' => true,
            'charge_type' => 'misc',
        ])->assertOk();

        $listing = $this->getJson('/api/shoots?tab=scheduled')->assertOk()->json('data.0.services_list');
        $this->assertContains('Renamed Adjustment', $listing);
        $this->assertNotContains('Original Adjustment Name', $listing);

        $dashboard = $this->getJson('/api/dashboard/overview')->assertOk()->json('data.upcoming_shoots.0.services');
        $dashboardNames = collect($dashboard)->pluck('label')->all();
        $this->assertContains('Renamed Adjustment', $dashboardNames);
        $this->assertNotContains('Original Adjustment Name', $dashboardNames);

        $this->deleteJson("/api/admin/invoices/{$invoice->id}/misc-items/{$itemId}")->assertOk();

        $afterDelete = $this->getJson('/api/shoots?tab=scheduled')->assertOk()->json('data.0.services_list');
        $this->assertNotContains('Renamed Adjustment', $afterDelete);
        $this->assertSame(106.0, (float) $shoot->fresh()->total_quote);
    }

    public function test_idempotent_item_only_invoice_retry_returns_the_exact_affected_shoot(): void
    {
        $shoot = $this->createShoot();
        $shoot->forceFill(['total_quote' => 116])->save();
        $invoice = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 110,
            'tax' => 6,
            'total' => 116,
            'total_amount' => 116,
            'status' => Invoice::STATUS_SENT,
        ]);
        $item = $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Retry-safe charge',
            'quantity' => 1,
            'unit_amount' => 10,
            'total_amount' => 10,
            'meta' => [
                'source' => 'admin_misc',
                'bills_client' => true,
                'charge_type' => 'misc',
                'dedupe_key' => 'retry-safe-item',
            ],
        ]);

        $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Retry-safe charge',
            'amount' => 10,
            'bills_client' => true,
            'shoot_id' => $shoot->id,
            'dedupe_key' => 'retry-safe-item',
        ])->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('affected_shoot_ids.0', $shoot->id);

        $this->assertSame(1, $invoice->items()->where('type', InvoiceItem::TYPE_EXPENSE)->count());
        $this->assertSame(116.0, (float) $shoot->fresh()->total_quote);
        $this->assertSame(116.0, (float) $invoice->fresh()->total);
    }

    public function test_aggregate_invoice_requires_a_target_shoot_for_billable_adjustments(): void
    {
        [$firstShoot] = $this->createShootAndInvoice();
        $secondShoot = $this->createShoot('456 Second Ave');

        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 206,
            'tax' => 0,
            'total' => 206,
            'total_amount' => 206,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach([$firstShoot->id, $secondShoot->id]);

        $this->postJson("/api/admin/invoices/{$aggregate->id}/misc-items", [
            'description' => 'Ambiguous Charge',
            'amount' => 15,
            'bills_client' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('shoot_id');

        $this->postJson("/api/admin/invoices/{$aggregate->id}/misc-items", [
            'description' => 'Targeted Charge',
            'amount' => 15,
            'bills_client' => true,
            'shoot_id' => $secondShoot->id,
        ])->assertCreated()
            ->assertJsonPath('item.shoot_id', $secondShoot->id);

        $this->assertSame(106.0, (float) $firstShoot->fresh()->total_quote);
        $this->assertSame(121.0, (float) $secondShoot->fresh()->total_quote);

        $firstNames = collect($this->getJson("/api/shoots/{$firstShoot->id}")->json('data.serviceItems'))->pluck('name');
        $secondNames = collect($this->getJson("/api/shoots/{$secondShoot->id}")->json('data.serviceItems'))->pluck('name');
        $this->assertNotContains('Targeted Charge', $firstNames);
        $this->assertContains('Targeted Charge', $secondNames);
    }

    public function test_adjustment_toggle_move_update_and_delete_apply_only_the_locked_delta(): void
    {
        $firstShoot = $this->createShoot();
        $secondShoot = $this->createShoot('456 Second Ave');
        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 200,
            'tax' => 12,
            'total' => 212,
            'total_amount' => 212,
            'charges_total' => 200,
            'balance_due' => 212,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach([$firstShoot->id, $secondShoot->id]);

        $added = $this->postJson("/api/admin/invoices/{$aggregate->id}/misc-items", [
            'description' => 'Moveable charge',
            'amount' => 10,
            'bills_client' => true,
            'shoot_id' => $firstShoot->id,
        ])->assertCreated();
        $itemId = $added->json('item.id');
        $this->assertSame(116.0, (float) $firstShoot->fresh()->total_quote);
        $this->assertSame(222.0, (float) $aggregate->fresh()->total);

        $this->patchJson("/api/admin/invoices/{$aggregate->id}/misc-items/{$itemId}", [
            'description' => 'Moveable charge',
            'amount' => 10,
            'quantity' => 1,
            'bills_client' => false,
            'shoot_id' => $firstShoot->id,
        ])->assertOk();
        $this->assertSame(106.0, (float) $firstShoot->fresh()->total_quote);
        $this->assertSame(212.0, (float) $aggregate->fresh()->total);

        $this->patchJson("/api/admin/invoices/{$aggregate->id}/misc-items/{$itemId}", [
            'description' => 'Moved charge',
            'amount' => 20,
            'quantity' => 1,
            'bills_client' => true,
            'shoot_id' => $secondShoot->id,
        ])->assertOk();
        $this->assertSame(106.0, (float) $firstShoot->fresh()->total_quote);
        $this->assertSame(126.0, (float) $secondShoot->fresh()->total_quote);
        $this->assertSame(232.0, (float) $aggregate->fresh()->total);

        $this->patchJson("/api/admin/invoices/{$aggregate->id}/misc-items/{$itemId}", [
            'description' => 'Moved charge updated',
            'amount' => 30,
            'quantity' => 1,
            'bills_client' => true,
            'shoot_id' => $secondShoot->id,
        ])->assertOk();
        $this->assertSame(136.0, (float) $secondShoot->fresh()->total_quote);
        $this->assertSame(242.0, (float) $aggregate->fresh()->total);

        $this->deleteJson("/api/admin/invoices/{$aggregate->id}/misc-items/{$itemId}")->assertOk();
        $this->assertSame(106.0, (float) $secondShoot->fresh()->total_quote);
        $this->assertSame(212.0, (float) $aggregate->fresh()->total);
    }

    public function test_tax_and_completed_payments_remain_consistent_when_an_adjustment_is_added_and_removed(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();
        $processedAt = now()->subHour();
        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => null,
            'amount' => 106,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => $processedAt,
        ]);
        $invoice->forceFill([
            'amount_paid' => 0,
            'payments_total' => 0,
            'balance_due' => 0,
            'is_paid' => true,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => $processedAt,
        ])->save();

        $added = $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Rush Charge',
            'amount' => 20,
            'bills_client' => true,
        ])->assertCreated();

        $this->assertSame(120.0, (float) $added->json('invoice.subtotal'));
        $this->assertSame(6.0, (float) $added->json('invoice.tax'));
        $this->assertSame(126.0, (float) $added->json('invoice.total'));
        $this->assertSame(126.0, (float) $added->json('invoice.total_amount'));
        $this->assertSame(106.0, (float) $added->json('invoice.amount_paid'));
        $this->assertSame(106.0, (float) $added->json('invoice.payments_total'));
        $this->assertSame(20.0, (float) $added->json('invoice.balance_due'));
        $this->assertSame(Invoice::STATUS_SENT, $added->json('invoice.status'));
        $this->assertNull($added->json('invoice.paid_at'));

        $removed = $this->deleteJson(
            "/api/admin/invoices/{$invoice->id}/misc-items/{$added->json('item.id')}"
        )->assertOk();

        $this->assertSame(100.0, (float) $removed->json('invoice.subtotal'));
        $this->assertSame(106.0, (float) $removed->json('invoice.total'));
        $this->assertSame(106.0, (float) $removed->json('invoice.total_amount'));
        $this->assertSame(0.0, (float) $removed->json('invoice.balance_due'));
        $this->assertSame(Invoice::STATUS_PAID, $removed->json('invoice.status'));
        $this->assertNotNull($removed->json('invoice.paid_at'));
    }

    public function test_refunds_are_authoritative_and_stale_paid_aliases_are_not_reused(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice->id,
            'amount' => 106,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now()->subHour(),
        ]);
        PaymentRefund::create([
            'payment_id' => $payment->id,
            'shoot_id' => $shoot->id,
            'amount' => 30,
            'provider' => 'stripe',
            'provider_refund_id' => 'refund-partial-test',
        ]);
        $invoice->forceFill([
            'amount_paid' => 106,
            'payments_total' => 106,
            'balance_due' => 0,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now()->subHour(),
        ])->save();

        $added = $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Refund-aware adjustment',
            'amount' => 20,
            'bills_client' => true,
        ])->assertCreated();

        $this->assertSame(76.0, (float) $added->json('invoice.amount_paid'));
        $this->assertSame(76.0, (float) $added->json('invoice.payments_total'));
        $this->assertSame(50.0, (float) $added->json('invoice.balance_due'));
        $this->assertSame(Invoice::STATUS_SENT, $added->json('invoice.status'));
        $this->assertNull($added->json('invoice.paid_at'));
        $this->assertSame(50.0, $invoice->fresh()->balanceDue());
    }

    public function test_pivot_only_invoice_is_reused_and_single_shoot_manual_payment_is_synchronized(): void
    {
        $shoot = $this->createShoot();
        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 100,
            'tax' => 6,
            'total' => 106,
            'total_amount' => 106,
            'charges_total' => 100,
            'payments_total' => 0,
            'balance_due' => 106,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach($shoot->id);

        $invoiceCount = Invoice::count();
        $this->getJson("/api/shoots/{$shoot->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.id', $aggregate->id);
        $this->assertSame($invoiceCount, Invoice::count());

        $this->postJson("/api/admin/invoices/{$aggregate->id}/mark-paid", [
            'amount_paid' => 106,
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_PAID);

        $payment = Payment::where('shoot_id', $shoot->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame($aggregate->id, (int) $payment->invoice_id);
        $this->assertSame(106.0, (float) $aggregate->fresh()->amount_paid);
        $this->assertSame('paid', $shoot->fresh()->payment_status);
    }

    public function test_multi_shoot_invoice_manual_payment_requires_explicit_shoot_allocation(): void
    {
        $firstShoot = $this->createShoot();
        $secondShoot = $this->createShoot('456 Second Ave');
        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 200,
            'tax' => 12,
            'total' => 212,
            'total_amount' => 212,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach([$firstShoot->id, $secondShoot->id]);

        $this->postJson("/api/admin/invoices/{$aggregate->id}/mark-paid", [
            'amount_paid' => 106,
            'payment_method' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This invoice covers multiple shoots. Record the payment against a specific shoot so it can be allocated correctly.'
            );

        $this->assertSame(0, Payment::whereIn('shoot_id', [$firstShoot->id, $secondShoot->id])->count());
        $this->assertSame(0.0, (float) $aggregate->fresh()->amount_paid);
        $this->assertSame(Invoice::STATUS_SENT, $aggregate->fresh()->status);
    }

    public function test_shoot_payment_reconciles_every_related_client_invoice(): void
    {
        [$shoot, $directInvoice] = $this->createShootAndInvoice();
        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 100,
            'tax' => 6,
            'total' => 106,
            'total_amount' => 106,
            'payments_total' => 0,
            'balance_due' => 106,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach($shoot->id);

        $this->postJson("/api/shoots/{$shoot->id}/mark-paid", [
            'amount' => 40,
            'payment_type' => 'cash',
            'allocation_strategy' => 'oldest_unpaid',
        ])->assertOk();

        $this->assertSame(40.0, (float) $directInvoice->fresh()->amount_paid);
        $this->assertSame(66.0, (float) $directInvoice->fresh()->balance_due);
        $this->assertSame(40.0, (float) $aggregate->fresh()->amount_paid);
        $this->assertSame(66.0, (float) $aggregate->fresh()->balance_due);
    }

    public function test_reconciliation_preserves_a_valid_existing_aggregate_payment_link(): void
    {
        [$shoot] = $this->createShootAndInvoice();
        $aggregate = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'subtotal' => 100,
            'tax' => 6,
            'total' => 106,
            'total_amount' => 106,
            'status' => Invoice::STATUS_SENT,
        ]);
        $aggregate->shoots()->attach($shoot->id);
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $aggregate->id,
            'amount' => 25,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $this->app->make(InvoiceAdjustmentService::class)
            ->reconcileClientInvoicesForShoot($shoot, $payment, 'cash');

        $this->assertSame($aggregate->id, (int) $payment->fresh()->invoice_id);
    }

    public function test_unrelated_client_cannot_read_or_create_an_invoice_or_payment_details(): void
    {
        [$shoot] = $this->createShootAndInvoice();
        $otherClient = User::factory()->create(['role' => 'client']);
        $invoiceCount = Invoice::count();
        Sanctum::actingAs($otherClient);

        $this->getJson("/api/shoots/{$shoot->id}/invoice")->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/payment-details")->assertForbidden();
        $this->assertSame($invoiceCount, Invoice::count());
    }

    public function test_refresh_totals_includes_tax_in_total_and_balance(): void
    {
        [, $invoice] = $this->createShootAndInvoice();

        $invoice->forceFill([
            'total' => 0,
            'total_amount' => 0,
            'charges_total' => 0,
            'balance_due' => 0,
        ])->save();
        $invoice->refreshTotals();

        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->charges_total);
        $this->assertSame(106.0, (float) $invoice->total);
        $this->assertSame(106.0, (float) $invoice->total_amount);
        $this->assertSame(106.0, (float) $invoice->balance_due);
    }

    public function test_period_regeneration_preserves_only_admin_misc_expenses(): void
    {
        $shoot = $this->createShoot();
        $shoot->forceFill(['total_quote' => 115])->save();
        $start = Carbon::parse($shoot->scheduled_date)->startOfDay();
        $end = $start->copy()->endOfDay();
        $invoice = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'billing_period_start' => $start->toDateString(),
            'billing_period_end' => $end->toDateString(),
            'tax' => 6,
            'status' => Invoice::STATUS_SENT,
        ]);
        $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Preserved admin note',
            'quantity' => 1,
            'unit_amount' => 9,
            'total_amount' => 9,
            'meta' => ['source' => 'admin_misc', 'bills_client' => true],
        ]);
        $stale = $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Stale generated expense',
            'quantity' => 1,
            'unit_amount' => 777,
            'total_amount' => 777,
            'meta' => ['source' => 'legacy_generated'],
        ]);

        $this->assertSame(
            $invoice->id,
            Invoice::query()
                ->where('user_id', $this->client->id)
                ->where('role', Invoice::ROLE_CLIENT)
                ->whereDate('period_start', $start->toDateString())
                ->whereDate('period_end', $end->toDateString())
                ->value('id')
        );

        $regenerated = $this->app->make(InvoiceService::class)
            ->generateInvoice($this->client, Invoice::ROLE_CLIENT, $start, $end);

        $this->assertSame($invoice->id, $regenerated->id);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Preserved admin note',
        ]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $stale->id]);
        $this->assertSame(115.0, (float) $regenerated->total_amount);
        $this->assertSame(
            100.0,
            (float) $regenerated->items->firstWhere('type', InvoiceItem::TYPE_CHARGE)?->total_amount
        );
    }

    public function test_period_invoice_does_not_subtract_an_adjustment_owned_by_a_direct_invoice(): void
    {
        [$shoot, $directInvoice] = $this->createShootAndInvoice();
        $this->postJson("/api/admin/invoices/{$directInvoice->id}/misc-items", [
            'description' => 'Direct invoice adjustment',
            'amount' => 20,
            'bills_client' => true,
        ])->assertCreated();
        $start = Carbon::parse($shoot->scheduled_date)->startOfDay();
        $end = $start->copy()->endOfDay();
        $directInvoice->forceFill([
            'period_start' => $start->copy()->subYear()->toDateString(),
            'period_end' => $start->copy()->subYear()->toDateString(),
        ])->save();

        $periodInvoice = $this->app->make(InvoiceService::class)
            ->generateInvoice($this->client, Invoice::ROLE_CLIENT, $start, $end);

        $this->assertNotSame($directInvoice->id, $periodInvoice->id);
        $this->assertSame(126.0, (float) $periodInvoice->total_amount);
        $this->assertSame(
            120.0,
            (float) $periodInvoice->items->firstWhere('type', InvoiceItem::TYPE_CHARGE)?->total_amount
        );
        $this->assertSame(
            0,
            $periodInvoice->items->where('description', 'Direct invoice adjustment')->count()
        );
        $this->assertSame(
            1,
            InvoiceItem::where('description', 'Direct invoice adjustment')->count()
        );
    }

    public function test_service_repricing_and_invoice_regeneration_preserve_manual_adjustments(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();
        $added = $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Persistent Adjustment',
            'amount' => 35,
            'bills_client' => true,
        ])->assertCreated();
        $itemId = $added->json('item.id');

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [
                ['id' => $this->service->id, 'quantity' => 2],
            ],
        ])->assertOk();

        $freshShoot = $shoot->fresh();
        $this->assertSame(
            round((float) $freshShoot->base_quote + (float) $freshShoot->tax_amount + 35, 2),
            round((float) $freshShoot->total_quote, 2)
        );
        $this->assertDatabaseHas('invoice_items', [
            'id' => $itemId,
            'invoice_id' => $invoice->id,
            'description' => 'Persistent Adjustment',
            'total_amount' => 35,
        ]);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(
            round((float) $freshInvoice->subtotal + (float) $freshInvoice->tax, 2),
            round((float) $freshInvoice->total_amount, 2)
        );
        $this->assertSame((float) $freshInvoice->total, (float) $freshInvoice->total_amount);
    }

    public function test_cancellation_invoice_regeneration_counts_manual_adjustment_once(): void
    {
        [$shoot, $invoice] = $this->createShootAndInvoice();
        $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", [
            'description' => 'Cancellation Add-on',
            'amount' => 80,
            'bills_client' => true,
        ])->assertCreated();

        $shoot->forceFill([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
            'base_quote' => 60,
            'tax_amount' => 0,
            'total_quote' => 140,
        ])->save();

        $regenerated = $this->app->make(\App\Services\InvoiceService::class)
            ->generateForShoot($shoot->fresh(['services', 'payments']));

        $this->assertSame(140.0, (float) $regenerated->subtotal);
        $this->assertSame(140.0, (float) $regenerated->total);
        $this->assertSame(140.0, (float) $regenerated->total_amount);
        $this->assertSame(
            80.0,
            (float) $regenerated->items
                ->firstWhere('description', 'Cancellation Add-on')
                ?->total_amount
        );
        $this->assertSame(
            60.0,
            (float) $regenerated->items
                ->first(fn (InvoiceItem $item) => (bool) ($item->meta['cancellation_fee'] ?? false))
                ?->total_amount
        );
    }

    /**
     * @return array{0: Shoot, 1: Invoice}
     */
    private function createShootAndInvoice(): array
    {
        $shoot = $this->createShoot();
        [, $invoice] = $this->createInvoiceForShoot($shoot, 'INV-FIRST');

        return [$shoot, $invoice];
    }

    private function createShoot(string $address = '123 Main St'): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'address' => $address,
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'base_quote' => 100,
            'tax_amount' => 6,
            'total_quote' => 106,
            'payment_status' => 'unpaid',
            'created_by' => (string) $this->admin->id,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 100,
            'quantity' => 1,
            'photographer_pay' => 30,
            'photographer_id' => $this->photographer->id,
        ]);

        return $shoot;
    }

    /**
     * @return array{0: InvoiceItem, 1: Invoice}
     */
    private function createInvoiceForShoot(Shoot $shoot, string $number): array
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $this->client->id,
            'client_id' => $this->client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => $shoot->id,
            'invoice_number' => $number,
            'subtotal' => 100,
            'tax' => 6,
            'total' => 106,
            'total_amount' => 106,
            'amount_paid' => 0,
            'charges_total' => 100,
            'payments_total' => 0,
            'balance_due' => 106,
            'status' => Invoice::STATUS_SENT,
        ]);

        $item = $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'HDR Photos',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
            'recorded_at' => now(),
            'meta' => ['service_id' => $this->service->id],
        ]);

        return [$item, $invoice];
    }

    private function assertOrderContainsAdjustment(
        array $payload,
        string $description,
        int $quantity,
        float $unitAmount,
        float $totalAmount
    ): void {
        $items = collect($payload['serviceItems'] ?? $payload['service_items'] ?? $payload['order_items'] ?? []);
        $line = $items->firstWhere('name', $description);

        $this->assertNotNull($line, "Missing adjustment [{$description}] from order projection.");
        $this->assertTrue((bool) ($line['is_invoice_adjustment'] ?? false));
        $this->assertSame($quantity, $line['quantity']);
        $this->assertSame($unitAmount, (float) $line['unit_amount']);
        $this->assertSame($totalAmount, (float) $line['total_amount']);
        $this->assertSame($totalAmount, (float) $line['subtotal']);
    }
}
