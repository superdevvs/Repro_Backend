<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ClientBillingService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicePricingBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public static function discounts(): array
    {
        return ['fixed' => ['fixed', 31], 'percent' => ['percent', 10]];
    }

    #[DataProvider('discounts')]
    public function test_new_invoice_snapshots_discount_without_changing_amounts_or_drifting(string $type, float $value): void
    {
        $shoot = $this->shoot($type, $value);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $before = $invoice->getRawOriginal();
        $breakdown = $invoice->pricing_breakdown;

        $this->assertBreakdown($breakdown, 310, 31, 279, 14.79, 293.79);
        $this->assertSame('snapshot', $breakdown['discount_source']);
        $this->assertSame('gross', $breakdown['line_amount_basis']);
        $this->assertCount(2, $invoice->items);
        $this->assertSame(31.0, (float) $invoice->items->first()->meta['pricing_snapshot']['discount_amount']);
        $shoot->forceFill(['base_quote' => 310, 'discount_amount' => 0, 'discount_type' => null, 'discount_value' => null])->save();
        $shoot->client->forceFill(['client_discount_type' => 'percent', 'client_discount_value' => 50])->save();

        $this->assertSame($breakdown, $invoice->fresh()->pricing_breakdown);
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
    }

    public function test_legacy_existing_invoice_is_visible_through_api_client_billing_and_csv_without_writes(): void
    {
        [$shoot, $invoice] = $this->legacyInvoice();
        $before = $invoice->fresh()->getRawOriginal();
        $itemsBefore = $invoice->items()->get()->map->getRawOriginal()->all();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson("/api/admin/invoices/{$invoice->id}")->assertOk();
        $breakdown = $response->json('pricing_breakdown') ?? $response->json('invoice.pricing_breakdown') ?? $response->json('data.pricing_breakdown');
        $this->assertBreakdown($breakdown, 310, 31, 279, 14.79, 293.79);
        $this->assertSame('legacy_reconciled', $breakdown['discount_source']);
        $billing = app(ClientBillingService::class)->getClientBilling($shoot->client);
        $this->assertEquals($breakdown, $billing['items'][0]['pricing_breakdown']);
        $csv = $this->get("/api/invoices/{$invoice->id}/download")->assertOk()->streamedContent();
        $this->assertStringContainsString('"Subtotal Before Discount",310.00', $csv);
        $this->assertStringContainsString("Discount,'-31.00", $csv);
        $this->assertStringContainsString('Subtotal,279.00', $csv);
        $this->assertStringContainsString('Tax,14.79', $csv);
        $this->assertStringContainsString('Total,293.79', $csv);
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->assertSame($itemsBefore, $invoice->items()->get()->map->getRawOriginal()->all());
    }

    public function test_signed_billable_adjustments_and_display_only_rows_do_not_hide_or_double_discount(): void
    {
        $shoot = $this->shoot();
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $this->expense($invoice, 20, true);
        $this->expense($invoice, -30, true);
        $this->expense($invoice, -99, false);
        $invoice->forceFill(['subtotal' => 269, 'total' => 283.79, 'total_amount' => 283.79])->save();

        $breakdown = $invoice->fresh()->pricing_breakdown;
        $this->assertBreakdown($breakdown, 300, 31, 269, 14.79, 283.79);
        $this->assertSame(-10.0, $breakdown['invoice_adjustments_total']);
        $this->assertSame(310.0, $breakdown['service_subtotal']);
        $this->assertSame(0.0, $breakdown['pricing_adjustment_amount']);
    }

    public function test_legacy_signed_adjustments_reconcile_with_booked_discount(): void
    {
        [, $invoice] = $this->legacyInvoice();
        $this->expense($invoice, -30, true);
        $this->expense($invoice, -99, false);
        $invoice->forceFill(['subtotal' => 249, 'total' => 263.79, 'total_amount' => 263.79])->save();

        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 280, 31, 249, 14.79, 263.79);
    }

    public function test_unexplained_legacy_reduction_is_not_mislabeled_discount_and_remains_reconciled(): void
    {
        [$shoot, $invoice] = $this->legacyInvoice();
        $shoot->forceFill(['discount_amount' => 20, 'base_quote' => 290])->save();

        $breakdown = $invoice->fresh()->pricing_breakdown;
        $this->assertBreakdown($breakdown, 310, 0, 279, 14.79, 293.79, -31);
        $this->assertSame('unavailable', $breakdown['discount_source']);
        $shoot->forceFill(['discount_amount' => 90, 'base_quote' => 220])->save();
        $this->assertSame($breakdown, $invoice->fresh()->pricing_breakdown);
    }

    public function test_snapshot_discount_and_later_manual_total_override_are_shown_separately(): void
    {
        $invoice = app(InvoiceService::class)->generateForShoot($this->shoot());
        $invoice->forceFill(['subtotal' => 274, 'total' => 288.79, 'total_amount' => 288.79])->save();

        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 310, 31, 274, 14.79, 288.79, -5);
    }

    public function test_period_net_charge_snapshots_preserve_multiple_discounts_without_double_subtraction(): void
    {
        $first = $this->shoot();
        $second = $this->shoot('fixed', 31, $first->client);
        $invoice = app(InvoiceService::class)->generateInvoice($first->client, 'client', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(558.0, (float) $invoice->items->where('type', 'charge')->sum('total_amount'));
        $breakdown = $invoice->pricing_breakdown;
        $this->assertBreakdown($breakdown, 620, 62, 558, 29.58, 587.58);
        $this->assertSame('net', $breakdown['line_amount_basis']);
        $first->forceFill(['discount_amount' => 0, 'base_quote' => 310])->save();
        $second->services()->updateExistingPivot($second->services->first()->id, ['price' => 500]);
        $this->assertSame($breakdown, $invoice->fresh()->pricing_breakdown);
    }

    public function test_legacy_period_net_rows_do_not_invent_historical_discounts(): void
    {
        [$shoot, $invoice] = $this->legacyInvoice();
        $invoice->forceFill(['shoot_id' => null])->save();
        $invoice->items()->first()->forceFill(['total_amount' => 279, 'unit_amount' => 279, 'meta' => ['shoot_id' => $shoot->id]])->save();

        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 279, 0, 279, 14.79, 293.79);
    }

    public function test_signed_adjustment_add_edit_and_remove_preserve_snapshot_discount(): void
    {
        $invoice = app(InvoiceService::class)->generateForShoot($this->shoot());
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", ['description' => 'Credit', 'amount' => -30, 'bills_client' => true])->assertCreated();
        $item = $invoice->items()->where('type', InvoiceItem::TYPE_EXPENSE)->first();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 280, 31, 249, 14.79, 263.79);
        $this->putJson("/api/admin/invoices/{$invoice->id}/misc-items/{$item->id}", ['description' => 'Credit', 'amount' => -10, 'bills_client' => true])->assertOk();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 300, 31, 269, 14.79, 283.79);
        $this->deleteJson("/api/admin/invoices/{$invoice->id}/misc-items/{$item->id}")->assertOk();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 310, 31, 279, 14.79, 293.79);
    }

    public function test_period_regeneration_keeps_discount_and_signed_adjustment_separate(): void
    {
        $shoot = $this->shoot();
        $this->shoot('fixed', 31, $shoot->client);
        $start = Carbon::parse('2026-09-01');
        $end = Carbon::parse('2026-09-30');
        $service = app(InvoiceService::class);
        $invoice = $service->generateInvoice($shoot->client, 'client', $start, $end);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/admin/invoices/{$invoice->id}/misc-items", ['description' => 'Credit', 'amount' => -30, 'bills_client' => true, 'shoot_id' => $shoot->id])->assertCreated();
        $invoice = $service->generateInvoice($shoot->client, 'client', $start, $end);

        $this->assertBreakdown($invoice->pricing_breakdown, 590, 62, 528, 29.58, 557.58);
        $this->assertSame(-30.0, $invoice->pricing_breakdown['invoice_adjustments_total']);
        $item = $invoice->items->where('type', InvoiceItem::TYPE_EXPENSE)->first();
        $this->deleteJson("/api/admin/invoices/{$invoice->id}/misc-items/{$item->id}")->assertOk();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 620, 62, 558, 29.58, 587.58);
    }

    public function test_waived_cancelled_comp_and_payout_rows_are_not_shoot_discounts(): void
    {
        [, $invoice] = $this->legacyInvoice();
        $invoice->items()->first()->forceFill(['unit_amount' => 0, 'total_amount' => 0, 'meta' => ['waived_due_to_cancellation' => true, 'original_amount' => 310]])->save();
        $invoice->forceFill(['subtotal' => 0, 'tax' => 0, 'total' => 0, 'total_amount' => 0])->save();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 0, 0, 0, 0, 0);
        $invoice->forceFill(['document_type' => Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT, 'payment_required' => false])->save();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 0, 0, 0, 0, 0);
        $invoice->forceFill(['role' => 'photographer', 'document_type' => 'invoice', 'payment_required' => true, 'subtotal' => 279, 'tax' => 14.79, 'total' => 293.79, 'total_amount' => 293.79])->save();
        $this->assertBreakdown($invoice->fresh()->pricing_breakdown, 279, 0, 279, 14.79, 293.79);
    }

    private function assertBreakdown(?array $actual, float $before, float $discount, float $net, float $tax, float $total, float $adjustment = 0): void
    {
        $this->assertIsArray($actual);
        foreach (['subtotal_before_discount' => $before, 'discount_amount' => $discount, 'subtotal' => $net, 'tax' => $tax, 'total' => $total, 'pricing_adjustment_amount' => $adjustment] as $key => $expected) {
            $this->assertSame($expected, (float) $actual[$key], $key);
        }
        $this->assertSame($net, round($actual['subtotal_before_discount'] - $actual['discount_amount'] + $actual['pricing_adjustment_amount'], 2));
    }

    private function shoot(string $type = 'percent', float $value = 10, ?User $client = null): Shoot
    {
        $shoot = Shoot::factory()->create(['client_id' => $client?->id ?? User::factory()->create(['role' => 'client'])->id, 'base_quote' => 279, 'tax_amount' => 14.79, 'total_quote' => 293.79, 'discount_type' => $type, 'discount_value' => $value, 'discount_amount' => 31, 'scheduled_date' => '2026-09-08', 'payment_status' => 'unpaid']);
        $service = Service::factory()->create(['price' => 155]);
        $secondService = Service::factory()->create(['price' => 77.5]);
        $shoot->services()->attach($service->id, ['price' => 155, 'quantity' => 1]);
        $shoot->services()->attach($secondService->id, ['price' => 77.5, 'quantity' => 2]);

        return $shoot;
    }

    private function legacyInvoice(): array
    {
        $shoot = $this->shoot();
        $invoice = Invoice::factory()->create(['user_id' => $shoot->client_id, 'client_id' => $shoot->client_id, 'shoot_id' => $shoot->id, 'subtotal' => 279, 'tax' => 14.79, 'total' => 293.79, 'total_amount' => 293.79, 'amount_paid' => 0]);
        $invoice->items()->create(['shoot_id' => $shoot->id, 'type' => 'charge', 'description' => 'HDR Photos Premium iGuide', 'quantity' => 1, 'unit_amount' => 310, 'total_amount' => 310, 'meta' => ['service_id' => $shoot->services->first()->id]]);

        return [$shoot, $invoice];
    }

    private function expense(Invoice $invoice, float $amount, bool $billable): void
    {
        $invoice->items()->create(['shoot_id' => $invoice->shoot_id, 'type' => InvoiceItem::TYPE_EXPENSE, 'description' => 'Manual adjustment', 'quantity' => 1, 'unit_amount' => $amount, 'total_amount' => $amount, 'meta' => ['source' => 'admin_misc', 'bills_client' => $billable]]);
    }
}
