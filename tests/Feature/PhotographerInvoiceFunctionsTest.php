<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotographerInvoiceFunctionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_invoice_uses_percent_catalog_pay_and_quantity(): void
    {
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $percentService = Service::factory()->create([
            'price' => 200,
            'photographer_pay' => null,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 45,
        ]);
        $fixedService = Service::factory()->create([
            'price' => 80,
            'photographer_pay' => 40,
            'photographer_pay_type' => Service::PAY_TYPE_FIXED,
        ]);

        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'service_id' => $percentService->id,
            'scheduled_date' => $start->copy()->addDays(2),
            'completed_at' => $start->copy()->addDays(2)->setTime(14, 0),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
        ]);
        $shoot->services()->attach($percentService->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => null,
            'photographer_id' => $photographer->id,
        ]);
        $shoot->services()->attach($fixedService->id, [
            'price' => 80,
            'quantity' => 2,
            'photographer_pay' => 40,
            'photographer_id' => $photographer->id,
        ]);

        $invoices = app(InvoiceService::class)->generateForPeriod($start, $end);

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertSame($photographer->id, $invoice->photographer_id);
        $this->assertSame(Invoice::ROLE_PHOTOGRAPHER, $invoice->role);
        // 45% of $200 = $90, plus 2 × $40 = $80
        $this->assertEquals(170.0, (float) $invoice->total_amount);
        $this->assertSame(2, $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->count());
    }

    public function test_photographer_can_edit_submit_and_not_touch_another_photographers_invoice(): void
    {
        $photographer = User::factory()->photographer()->create();
        $other = User::factory()->photographer()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'photographer_id' => $photographer->id,
            'client_id' => null,
            'shoot_id' => null,
            'status' => Invoice::STATUS_SENT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'billing_period_start' => now()->startOfWeek(),
            'billing_period_end' => now()->endOfWeek(),
            'period_start' => now()->startOfWeek(),
            'period_end' => now()->endOfWeek(),
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'total_amount' => 0,
            'amount_paid' => 0,
            'payment_required' => true,
        ]);
        $charge = $invoice->items()->create([
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'HDR photos',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
            'recorded_at' => now(),
        ]);
        $invoice->refreshTotals();

        $this->mock(MailService::class, function ($mock) {
            $mock->shouldReceive('sendInvoicePendingApprovalEmail')->once()->andReturnTrue();
        });

        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/invoices/{$invoice->id}/expenses", [
            'description' => 'Parking',
            'amount' => 25,
        ])->assertCreated();

        $this->postJson("/api/photographer/invoices/{$invoice->id}/charges", [
            'description' => 'Twilight',
            'amount' => 50,
            'quantity' => 1,
        ])->assertCreated();

        $expense = $invoice->fresh()->items()->where('type', InvoiceItem::TYPE_EXPENSE)->first();
        $this->assertNotNull($expense);

        $this->patchJson("/api/photographer/invoices/{$invoice->id}/items/{$expense->id}", [
            'amount' => 30,
        ])->assertOk();

        $addedCharge = $invoice->fresh()->items()
            ->where('type', InvoiceItem::TYPE_CHARGE)
            ->where('description', 'Twilight')
            ->first();
        $this->assertNotNull($addedCharge);

        $this->deleteJson("/api/photographer/invoices/{$invoice->id}/charges/{$addedCharge->id}")
            ->assertOk();

        $invoice->refresh();
        $this->assertEquals(130.0, (float) $invoice->total_amount);

        $this->postJson("/api/photographer/invoices/{$invoice->id}/submit-for-approval", [
            'notes' => 'Ready for accounts.',
        ])->assertOk()
            ->assertJsonPath('invoice.approval_status', Invoice::APPROVAL_STATUS_PENDING_APPROVAL);

        $this->postJson("/api/photographer/invoices/{$invoice->id}/expenses", [
            'description' => 'Too late',
            'amount' => 5,
        ])->assertStatus(422);

        Sanctum::actingAs($other);
        $this->getJson("/api/photographer/invoices/{$invoice->id}")->assertForbidden();
        $this->postJson("/api/photographer/invoices/{$invoice->id}/charges", [
            'description' => 'Nope',
            'amount' => 1,
        ])->assertForbidden();

        $this->assertSame(100.0, (float) $charge->fresh()->total_amount);
    }
}
