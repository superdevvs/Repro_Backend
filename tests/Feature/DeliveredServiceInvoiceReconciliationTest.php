<?php

namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, Payment, PaymentRefund, Service, Shoot, ShootService, User};
use App\Services\InvoiceService;
use App\Services\Shoots\{ShootMutationSupportService, ShootServiceItemSupport};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Tests\TestCase;

class DeliveredServiceInvoiceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function shoot(User $client, array $prices = [100, 50]): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'photographer_id' => null,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'scheduled_date' => '2026-10-01', 'scheduled_at' => null, 'time' => null,
            'state' => 'VA', 'base_quote' => array_sum($prices), 'tax_amount' => 0,
            'tax_percent' => 0, 'total_quote' => array_sum($prices),
        ]);
        foreach ($prices as $index => $price) {
            $service = Service::factory()->noIntake()->create(['name' => 'Invoice service '.$index, 'price' => $price, 'photographer_required' => false]);
            $shoot->services()->attach($service->id, ['price' => $price, 'quantity' => 1,
                'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        }

        return $shoot;
    }

    private function payment(Shoot $shoot, float $amount): Payment
    {
        return Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null,
            'amount' => $amount, 'payment_method' => 'cash', 'processed_at' => Carbon::parse('2026-10-02 10:00:00')]);
    }

    private function reprice(Shoot $shoot, array $services, float $total): void
    {
        app(ShootMutationSupportService::class)->attachServices($shoot, $services);
        $shoot->forceFill(['base_quote' => $total, 'tax_amount' => 0, 'total_quote' => $total])->save();
        app(ShootServiceItemSupport::class)->reconcileAllocationsAfterServiceChange($shoot->fresh());
        $shoot->refresh()->syncPaymentStatusFromRecords();
    }

    public function test_direct_paid_invoice_retains_surviving_charge_identity_and_receipt_when_services_change(): void
    {
        $shoot = $this->shoot(User::factory()->create(['role' => 'client']));
        $payment = $this->payment($shoot, 150);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->generateForShoot($shoot->fresh());
        $items = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->orderBy('id')->get();
        [$retained, $removed] = [$items[0], $items[1]];
        $retained->update(['meta' => array_merge($retained->meta, ['external_reference' => 'retain-this-reference'])]);
        $paymentRow = $invoice->items()->create(['shoot_id' => $shoot->id, 'type' => InvoiceItem::TYPE_PAYMENT,
            'description' => 'Original receipt', 'quantity' => 1, 'unit_amount' => 150, 'total_amount' => 150,
            'recorded_at' => $payment->processed_at, 'meta' => ['payment_id' => $payment->id, 'receipt_reference' => 'unchanged']]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $receiptBefore = $paymentRow->fresh()->getRawOriginal();
        $added = Service::factory()->noIntake()->create(['price' => 25, 'photographer_required' => false]);

        $this->reprice($shoot, [['id' => $retained->meta['service_id'], 'quantity' => 2], ['id' => $added->id]], 225);
        $allocations = $shoot->payments()->sole()->serviceAllocations()->orderBy('id')->get()->toArray();
        foreach ([1, 2] as $attempt) {
            $invoices->refreshClientInvoicesForShoot($shoot->fresh(), true);
            $this->assertSame($retained->id, $invoice->items()->where('meta->shoot_service_id', $retained->meta['shoot_service_id'])->sole()->id);
            $this->assertSame('retain-this-reference', $retained->fresh()->meta['external_reference']);
            $this->assertSame(2, (int) $retained->fresh()->quantity);
            $this->assertSame(200.0, (float) $retained->fresh()->total_amount);
            $this->assertDatabaseMissing('invoice_items', ['id' => $removed->id]);
            $this->assertSame(2, $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->count());
            $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
            $this->assertSame($receiptBefore, $paymentRow->fresh()->getRawOriginal());
            $this->assertSame($allocations, $shoot->payments()->sole()->serviceAllocations()->orderBy('id')->get()->toArray());
        }
        $this->assertSame(225.0, (float) $invoice->fresh()->total);
        $this->assertSame(150.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(75.0, (float) $invoice->fresh()->balance_due);
        $this->assertFalse($invoice->fresh()->is_paid);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_reduced_total_keeps_net_payment_and_real_refund_separate_from_credit_due(): void
    {
        $shoot = $this->shoot(User::factory()->create(['role' => 'client']));
        $payment = $this->payment($shoot, 150);
        $refund = PaymentRefund::create(['payment_id' => $payment->id, 'shoot_id' => $shoot->id,
            'amount' => 20, 'provider' => 'stripe', 'provider_refund_id' => 're_actual_before_edit', 'status' => 'succeeded']);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->generateForShoot($shoot->fresh());
        $retained = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->orderBy('id')->firstOrFail();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $refundBefore = $refund->fresh()->getRawOriginal();

        $this->reprice($shoot, [['id' => $retained->meta['service_id']]], 100);
        $invoices->refreshClientInvoicesForShoot($shoot->fresh(), true);

        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame($refundBefore, $refund->fresh()->getRawOriginal());
        $this->assertSame(130.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(100.0, (float) $invoice->fresh()->total);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_due);
        $this->assertTrue($invoice->fresh()->is_paid);
        $this->assertSame(30.0, round($shoot->fresh()->calculateCanonicalTotalPaid() - (float) $shoot->fresh()->total_quote, 2));
        $this->assertDatabaseCount('payment_refunds', 1);
    }

    public function test_period_invoice_retains_charge_payment_and_adjustment_rows_for_other_shoots(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->shoot($client);
        $other = $this->shoot($client, [80]);
        $payment = $this->payment($shoot, 150);
        $otherPayment = $this->payment($other, 80);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->generateInvoice($client, Invoice::ROLE_CLIENT, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $invoice->forceFill(['issue_date' => '2026-10-03', 'due_date' => '2026-11-15'])->save();
        $charge = $invoice->items()->where('shoot_id', $shoot->id)->where('type', InvoiceItem::TYPE_CHARGE)->sole();
        $otherCharge = $invoice->items()->where('shoot_id', $other->id)->where('type', InvoiceItem::TYPE_CHARGE)->sole();
        $otherCharge->update(['meta' => array_merge($otherCharge->meta, ['external_reference' => 'other-shoot-reference', 'type' => 'external_period_reference'])]);
        $paymentRows = $invoice->items()->where('type', InvoiceItem::TYPE_PAYMENT)->orderBy('id')->get();
        foreach ($paymentRows as $row) $row->update(['meta' => array_merge($row->meta, ['receipt_reference' => 'receipt-'.$row->id])]);
        $paymentSnapshots = $paymentRows->mapWithKeys(fn ($row) => [$row->id => $row->fresh()->getRawOriginal()])->all();
        $paymentRecords = [$payment->id => $payment->fresh()->getRawOriginal(), $otherPayment->id => $otherPayment->fresh()->getRawOriginal()];
        $adjustment = $invoice->items()->create(['shoot_id' => $other->id, 'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Display-only accounting note', 'quantity' => 1, 'unit_amount' => 10, 'total_amount' => 10,
            'meta' => ['source' => 'admin_misc', 'bills_client' => false, 'accounting_reference' => 'keep']]);
        $adjustmentBefore = $adjustment->fresh()->getRawOriginal();
        $retainedServiceId = $shoot->serviceItems()->orderBy('id')->firstOrFail()->service_id;
        $added = Service::factory()->noIntake()->create(['price' => 25, 'photographer_required' => false]);

        $this->reprice($shoot, [['id' => $retainedServiceId, 'quantity' => 2], ['id' => $added->id]], 225);
        foreach ([1, 2] as $attempt) {
            $invoices->refreshClientInvoicesForShoot($shoot->fresh(), true);
            $this->assertSame($charge->id, $invoice->items()->where('shoot_id', $shoot->id)->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id);
            $this->assertSame($otherCharge->id, $invoice->items()->where('shoot_id', $other->id)->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id);
            $this->assertSame('other-shoot-reference', $otherCharge->fresh()->meta['external_reference']);
            $this->assertSame('external_period_reference', $otherCharge->fresh()->meta['type']);
            foreach ($paymentSnapshots as $id => $snapshot) $this->assertSame($snapshot, InvoiceItem::findOrFail($id)->getRawOriginal());
            foreach ($paymentRecords as $id => $snapshot) $this->assertSame($snapshot, Payment::findOrFail($id)->getRawOriginal());
            $this->assertSame($adjustmentBefore, $adjustment->fresh()->getRawOriginal());
            $this->assertSame(5, $invoice->items()->count());
        }
        $this->assertSame(305.0, (float) $invoice->fresh()->total);
        $this->assertSame(230.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(75.0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('2026-10-03', $invoice->fresh()->issue_date->toDateString());
        $this->assertSame('2026-11-15', $invoice->fresh()->due_date->toDateString());
        $this->assertSame(1, Invoice::query()->where('role', Invoice::ROLE_CLIENT)->count());
    }

    public function test_repeated_catalog_service_across_units_keeps_each_direct_charge_identity(): void
    {
        $shoot = $this->shoot(User::factory()->create(['role' => 'client']), []);
        $service = Service::factory()->noIntake()->create(['price' => 100, 'photographer_required' => false]);
        $first = $shoot->units()->create(['client_key' => 'unit-first', 'label' => 'First', 'kind' => 'unit']);
        $second = $shoot->units()->create(['client_key' => 'unit-second', 'label' => 'Second', 'kind' => 'unit']);
        $line = null;
        foreach ([$first, $second] as $unit) {
            $line = ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id,
                'client_key' => 'line-'.$unit->id, 'service_id' => $service->id, 'quantity' => 1, 'price' => 100]);
        }
        $shoot->forceFill(['base_quote' => 200, 'total_quote' => 200])->save();
        $invoice = app(InvoiceService::class)->generateForShoot($shoot->fresh());
        $before = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->get()->mapWithKeys(fn ($item) => [$item->meta['shoot_service_id'] => $item->id])->all();
        $line->update(['quantity' => 2]);
        $shoot->forceFill(['base_quote' => 300, 'total_quote' => 300])->save();

        app(InvoiceService::class)->refreshClientInvoicesForShoot($shoot->fresh(), true);

        $after = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->get()->mapWithKeys(fn ($item) => [$item->meta['shoot_service_id'] => $item->id])->all();
        $this->assertSame($before, $after);
        $this->assertSame(300.0, (float) $invoice->fresh()->total);
    }

    public function test_ordinary_invoice_without_payment_records_keeps_legacy_receipt_amount_and_metadata(): void
    {
        $shoot = $this->shoot(User::factory()->create(['role' => 'client']));
        $invoice = app(InvoiceService::class)->generateForShoot($shoot->fresh());
        $receipt = $invoice->items()->create(['shoot_id' => $shoot->id, 'type' => InvoiceItem::TYPE_PAYMENT,
            'description' => 'Previously recorded receipt', 'quantity' => 1, 'unit_amount' => 100, 'total_amount' => 100,
            'meta' => ['external_receipt' => 'original-receipt-reference']]);
        $receiptBefore = $receipt->fresh()->getRawOriginal();
        $service = $shoot->serviceItems()->orderBy('id')->firstOrFail();
        $this->reprice($shoot, [['id' => $service->service_id, 'quantity' => 2]], 200);

        app(InvoiceService::class)->refreshClientInvoicesForShoot($shoot->fresh(), true);

        $this->assertSame($receiptBefore, $receipt->fresh()->getRawOriginal());
        $this->assertSame(100.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(100.0, (float) $invoice->fresh()->balance_due);
        $this->assertDatabaseCount('payments', 0);
    }
}
