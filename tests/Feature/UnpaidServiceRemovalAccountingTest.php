<?php

namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, Payment, PaymentServiceAllocation, Service, Shoot, ShootService, User};
use App\Services\InvoiceService;
use App\Services\Invoices\UnpaidServiceRemovalAccounting;
use App\Support\ReportingWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnpaidServiceRemovalAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function fixture(): array
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $rep = User::factory()->create(['role' => 'salesRep', 'account_status' => 'active']);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'scheduled_date' => '2026-11-06', 'scheduled_at' => '2026-11-06 15:00:00',
            'completed_at' => '2026-11-07 15:00:00', 'admin_verified_at' => '2026-11-07 15:00:00',
            'photographer_id' => $photographer->id, 'rep_id' => $rep->id, 'state' => 'NJ',
            'base_quote' => 185, 'total_quote' => 185, 'tax_amount' => 0, 'tax_percent' => 0,
        ]);
        $unit = $shoot->units()->create(['client_key' => 'unit', 'label' => '1', 'kind' => 'unit', 'sqft' => 2004]);
        $photo = Service::factory()->unbracketedPhoto()->create(['price' => 125]);
        $fee = Service::factory()->noIntake()->create(['name' => 'Onsite Cancellation/hold fee',
            'price' => 60, 'financial_only' => true, 'photographer_required' => false, 'exclude_from_sales_commission' => true]);
        $lines = [];
        foreach ([[$photo, 125, 56.25], [$fee, 60, 50]] as [$service, $price, $pay]) {
            $lines[] = ShootService::create([
                'shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'client_key' => 'line-'.$service->id,
                'service_id' => $service->id, 'price' => $price, 'quantity' => 1, 'photographer_pay' => $pay,
                'photographer_id' => $photographer->id, 'workflow_status' => $service->financial_only ? 'pending' : 'delivered',
                'delivery_status' => $service->financial_only ? 'not_started' : 'delivered',
                'delivered_at' => $service->financial_only ? null : $shoot->completed_at,
            ]);
        }
        $clientInvoice = app(InvoiceService::class)->generateForShoot($shoot);
        $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $clientInvoice->id,
            'amount' => 60, 'status' => Payment::STATUS_COMPLETED]);
        PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $lines[1]->id, 'amount' => 60]);
        $shoot->syncPaymentStatusFromRecords(null, false);
        Sanctum::actingAs($actor);
        return [$shoot->fresh(), ...$lines, $clientInvoice, $payment, $actor, $rep];
    }

    private function remove(Shoot $shoot, array $lines): void
    {
        $payload = ['expected_units_revision' => $shoot->fresh()->units_revision,
            'service_lines' => array_map(fn ($line) => ['shoot_service_id' => $line->id,
                'client_key' => $line->client_key, 'shoot_unit_id' => $line->shoot_unit_id, 'service_id' => $line->service_id], $lines),
            'notify_client' => false, 'notify_photographer' => false];
        $response = $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertConflict()
            ->assertJsonPath('impact.all_removed_services_unpaid', true);
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + ['confirm_service_detach' => true,
            'service_detach_confirmation_token' => $response->json('confirmation_token')])->assertOk();
    }

    public static function commissionStates(): array
    {
        return [['pending'], ['accounts_approved'], ['paid'], ['flag_paid'], ['partially_paid']];
    }

    #[DataProvider('commissionStates')]
    public function test_unpaid_removal_reconciles_receipt_customer_invoice_and_commission_without_rewriting_money(string $state): void
    {
        [$shoot, $photo, $fee, $clientInvoice, $payment, $actor, $rep] = $this->fixture();
        [$start, $end] = ReportingWeek::containing('2026-11-06');
        $commission = Invoice::create(['role' => Invoice::ROLE_SALES_REP, 'user_id' => $rep->id, 'sales_rep_id' => $rep->id,
            'period_start' => $start, 'period_end' => $end, 'billing_period_start' => $start, 'billing_period_end' => $end,
            'approval_status' => $state === 'paid' ? 'accounts_approved' : $state,
            'status' => $state === 'paid' ? 'paid' : 'draft', 'amount_paid' => $state === 'paid' ? 48.75 : ($state === 'partially_paid' ? 10 : 0),
            'is_paid' => in_array($state, ['paid', 'flag_paid'], true), 'paid_at' => $state === 'paid' ? now() : null]);
        $commission->items()->create(['shoot_id' => $shoot->id, 'type' => 'charge', 'description' => 'Commission',
            'quantity' => 1, 'unit_amount' => 18.75, 'total_amount' => 18.75,
            'meta' => ['commission_rate' => 15, 'commissionable_gross' => 125]]);
        $other = $commission->items()->create(['type' => 'expense', 'description' => 'Unrelated earned pay',
            'quantity' => 1, 'unit_amount' => 30, 'total_amount' => 30]);
        $commission->refreshTotals();
        $commissionBefore = $commission->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();
        $this->remove($shoot, [$fee]);

        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertEquals(60, $shoot->fresh()->total_quote);
        $this->assertEquals(60, $clientInvoice->fresh()->total);
        $this->assertEquals(60, $clientInvoice->fresh()->amount_paid);
        $this->assertEquals(0, $clientInvoice->fresh()->balance_due);
        $this->getJson('/api/shoots/'.$shoot->id.'/payment-details')->assertOk()
            ->assertJsonPath('data.order_total', 60)->assertJsonPath('data.amount_due', 0)
            ->assertJsonCount(1, 'data.services');
        $this->assertEquals(30, Invoice::where('role', Invoice::ROLE_SALES_REP)->sum('total_amount'));
        $correction = InvoiceItem::where('meta->source', 'unpaid_service_removal')->sole();
        $this->assertEquals(-18.75, $correction->total_amount);
        $this->assertSame('pending_approval', $correction->invoice->approval_status);
        if (in_array($state, ['paid', 'flag_paid', 'partially_paid'], true)) $this->assertSame($commissionBefore, $commission->fresh()->getRawOriginal());
        else $this->assertSame($commission->id, $correction->invoice_id);
        $this->assertDatabaseHas('invoice_audit_events', ['invoice_id' => $correction->invoice_id, 'event' => 'admin_edit']);
        $this->patchJson('/api/shoots/'.$shoot->id, ['expected_units_revision' => $shoot->fresh()->units_revision,
            'service_lines' => [['shoot_service_id' => $fee->id, 'client_key' => $fee->client_key,
                'shoot_unit_id' => $fee->shoot_unit_id, 'service_id' => $fee->service_id]],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $this->assertSame(1, InvoiceItem::where('meta->source', 'unpaid_service_removal')->count());
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_earned_photo_and_retained_fee_pay_survive_invoice_regeneration_and_retries(): void
    {
        [$shoot, $photo, $fee] = $this->fixture();
        [$start, $end] = ReportingWeek::containing('2026-11-06');
        $invoice = app(InvoiceService::class)->generateForPeriod($start, $end)->sole();
        $this->assertEquals(106.25, $invoice->total_amount);
        $this->remove($shoot, [$fee]);
        $this->assertDatabaseCount('shoot_service_earning_snapshots', 2);
        for ($i = 0; $i < 2; $i++) {
            app(InvoiceService::class)->generateForPeriod($start, $end);
            $this->assertEquals(106.25, $invoice->fresh()->total_amount);
            $this->assertCount(2, $invoice->fresh()->items);
            $this->assertDatabaseCount('invoices', 2);
        }
    }

    public function test_last_fully_unpaid_service_can_be_removed_from_a_delivered_unit_shoot(): void
    {
        [$shoot, $photo, $fee, $clientInvoice, $payment] = $this->fixture();
        $payment->update(['status' => Payment::STATUS_FAILED]);
        $shoot->syncPaymentStatusFromRecords(null, false);
        $this->postJson('/api/photographer/availability/feasibility', ['shoot_id' => $shoot->id,
            'action_mode' => 'update', 'expected_units_revision' => $shoot->units_revision, 'service_lines' => []])
            ->assertOk()->assertJsonCount(0, 'data.visits');
        $this->remove($shoot->fresh(), []);
        $this->assertSame(0, $shoot->serviceItems()->count());
        $this->assertEquals(0, $clientInvoice->fresh()->total);
        $this->assertEquals(0, $clientInvoice->fresh()->balance_due);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
    }

    public function test_earned_pay_can_be_generated_after_removal_without_paying_a_reassigned_recipient_twice(): void
    {
        [$shoot, $photo, $fee] = $this->fixture();
        $originalRecipient = $photo->photographer_id;
        $this->remove($shoot, [$fee]);
        $newPhotographer = User::factory()->photographer()->create();
        $fee->forceFill(['photographer_id' => $newPhotographer->id])->saveQuietly();
        [$start, $end] = ReportingWeek::containing('2026-11-06');
        $invoices = app(InvoiceService::class)->generateForPeriod($start, $end);
        $this->assertEquals(106.25, $invoices->sole()->total_amount);
        $this->assertSame($originalRecipient, $invoices->sole()->photographer_id);
        $this->assertCount(0, app(InvoiceService::class)->generateForPeriod($start, $end, false, $newPhotographer->id));
    }

    public function test_a_paid_delivered_shoot_cannot_remove_its_last_service_as_an_unpaid_correction(): void
    {
        [$shoot] = $this->fixture();
        $this->patchJson('/api/shoots/'.$shoot->id, ['expected_units_revision' => $shoot->units_revision,
            'service_lines' => []])->assertUnprocessable();
        $this->assertSame(2, $shoot->serviceItems()->count());
    }

    public function test_legacy_unallocated_money_is_not_treated_as_proof_of_an_unpaid_line(): void
    {
        [$shoot, $photo, $fee, $clientInvoice, $payment, $actor] = $this->fixture();
        PaymentServiceAllocation::query()->delete();
        $service = app(UnpaidServiceRemovalAccounting::class);
        $before = $service->snapshot($shoot);
        $photo->delete();
        $service->reconcile($shoot->fresh(), $before, $actor);
        $this->assertDatabaseCount('shoot_service_earning_snapshots', 0);
    }

    public function test_unpaid_upcoming_service_can_be_removed_without_creating_earnings(): void
    {
        [$shoot, $photo, $fee] = $this->fixture();
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
            'completed_at' => null, 'admin_verified_at' => null, 'photos_uploaded_at' => null,
            'scheduled_at' => now()->addWeek(), 'scheduled_date' => now()->addWeek()->toDateString()])->saveQuietly();
        $photo->forceFill(['workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'delivered_at' => null])->saveQuietly();
        $this->remove($shoot->fresh(), [$fee]);
        $this->assertNull($photo->fresh());
        $this->assertDatabaseCount('shoot_service_earning_snapshots', 0);
        $this->assertEquals(60, $shoot->fresh()->total_quote);
    }

    public function test_legacy_invoice_payment_evidence_does_not_label_a_line_unpaid(): void
    {
        [$shoot, $photo, $fee, $invoice, $payment] = $this->fixture();
        $payment->delete();
        $shoot->forceFill(['payment_status' => 'unpaid'])->saveQuietly();
        $invoice->forceFill(['amount_paid' => 60, 'payments_total' => 60])->saveQuietly();
        $this->assertSame([], app(\App\Services\Shoots\ShootServiceItemSupport::class)
            ->unpaidItemIds($shoot->fresh(), $shoot->serviceItems()->get()));
        $this->assertFalse(app(\App\Services\Shoots\ShootServiceChangeGuard::class)->canRemoveAllServices($shoot->fresh(), auth()->user()));
    }

    public function test_accounting_failure_rolls_back_service_invoice_and_earned_snapshots_together(): void
    {
        [$shoot, $photo, $fee, $clientInvoice] = $this->fixture();
        $shoot->update(['sales_rep_pay_enabled' => true]);
        [$start, $end] = ReportingWeek::containing('2026-11-06');
        $commission = app(InvoiceService::class)->generateSalesRepInvoicesForPeriod($start, $end)->sole();
        $payload = ['expected_units_revision' => $shoot->units_revision,
            'service_lines' => [['shoot_service_id' => $fee->id, 'client_key' => $fee->client_key,
                'shoot_unit_id' => $fee->shoot_unit_id, 'service_id' => $fee->service_id]],
            'notify_client' => false, 'notify_photographer' => false];
        $confirmation = $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertConflict();
        InvoiceItem::creating(function (InvoiceItem $item) {
            if (data_get($item->meta, 'source') === 'unpaid_service_removal') {
                throw new \RuntimeException('Simulated accounting write failure');
            }
        });
        $this->patchJson('/api/shoots/'.$shoot->id, $payload + ['confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token')])->assertStatus(500);
        $this->assertNotNull($photo->fresh());
        $this->assertEquals(185, $shoot->fresh()->total_quote);
        $this->assertEquals(185, $clientInvoice->fresh()->total);
        $this->assertEquals(18.75, $commission->fresh()->total_amount);
        $this->assertDatabaseCount('shoot_service_earning_snapshots', 0);
        $this->assertSame(0, InvoiceItem::where('meta->source', 'unpaid_service_removal')->count());
    }
}
