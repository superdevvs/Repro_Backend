<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_generation_creates_records_and_links_shoots(): void
    {
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $service = Service::factory()->create();

        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        collect(range(0, 1))->each(function (int $index) use ($photographer, $client, $service, $start) {
            $shoot = Shoot::factory()->create([
                'photographer_id' => $photographer->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'scheduled_date' => $start->copy()->addDays($index + 1),
                'completed_at' => $start->copy()->addDays($index + 1)->setTime(14, 0),
                'workflow_status' => Shoot::WORKFLOW_COMPLETED,
                'base_quote' => 150 + ($index * 25),
                'tax_amount' => 10,
                'total_quote' => 160 + ($index * 25),
            ]);
            $shoot->services()->attach($service->id, [
                'price' => 160 + ($index * 25),
                'quantity' => 1,
                'photographer_pay' => 160 + ($index * 25),
                'photographer_id' => $photographer->id,
            ]);

            Payment::create([
                'shoot_id' => $shoot->id,
                'amount' => 160 + ($index * 25),
                'currency' => 'USD',
                'square_payment_id' => (string) Str::uuid(),
                'square_order_id' => (string) Str::uuid(),
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

        });

        $serviceInstance = app(InvoiceService::class);
        $invoices = $serviceInstance->generateForPeriod($start, $end);

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();

        $this->assertEquals($photographer->id, $invoice->photographer_id);
        $this->assertSame(2, $invoice->shoots()->count());
        $this->assertEquals(345.0, (float) $invoice->total_amount);
        $this->assertEquals(345.0, (float) $invoice->amount_paid);
    }

    public function test_regenerating_weekly_invoices_is_idempotent_and_creates_no_duplicates(): void
    {
        // Reproduces the weekly-invoice duplicate bug: billing_period_* is stored as a
        // datetime ("Y-m-d 00:00:00"), so the existing-invoice guard using a date-only
        // equality (`where(..., toDateString())`) never matched and every regeneration
        // created a duplicate. The fix switches the guard to whereDate().
        $photographer = User::factory()->photographer()->create();
        $rep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => ['repDetails' => ['commissionPercentage' => 15]],
        ]);
        $client = User::factory()->create();
        $service = Service::factory()->create(['exclude_from_sales_commission' => false]);

        $start = Carbon::now()->subWeek()->startOfWeek(Carbon::SUNDAY);
        $end = $start->copy()->addDays(6)->endOfDay();

        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'service_id' => $service->id,
            'scheduled_date' => $start->copy()->addDays(2),
            'completed_at' => $start->copy()->addDays(2)->setTime(14, 0),
            'admin_verified_at' => $start->copy()->addDays(2)->setTime(14, 0),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'base_quote' => 200,
            'tax_amount' => 0,
            'total_quote' => 200,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => 90,
            'photographer_id' => $photographer->id,
        ]);

        $svc = app(InvoiceService::class);

        // Generate twice (simulates manual run overlapping the scheduled weekly run).
        $svc->generateForPeriod($start, $end);
        $svc->generateForPeriod($start, $end);
        $svc->generateSalesRepInvoicesForPeriod($start, $end);
        $svc->generateSalesRepInvoicesForPeriod($start, $end);

        $this->assertSame(
            1,
            Invoice::where('photographer_id', $photographer->id)->where('role', Invoice::ROLE_PHOTOGRAPHER)->count(),
            'Photographer weekly invoice must not duplicate on regeneration.'
        );
        $this->assertSame(
            1,
            Invoice::where('sales_rep_id', $rep->id)->whereNull('photographer_id')->count(),
            'Sales-rep weekly invoice must not duplicate on regeneration.'
        );

        // Amounts still correct after idempotent recalculation.
        $photogInvoice = Invoice::where('photographer_id', $photographer->id)->first();
        $this->assertEquals(90.0, (float) $photogInvoice->total_amount);
        $repInvoice = Invoice::where('sales_rep_id', $rep->id)->whereNull('photographer_id')->first();
        $this->assertEquals(30.0, (float) $repInvoice->total_amount); // 200 * 15%
    }

    public function test_admin_can_list_and_mark_invoice_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $service = Service::factory()->create();

        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'scheduled_date' => $start->copy()->addDay(),
            'completed_at' => $start->copy()->addDay()->setTime(14, 0),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'total_quote' => 200,
            'base_quote' => 180,
            'tax_amount' => 20,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => 200,
            'photographer_id' => $photographer->id,
        ]);

        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 200,
            'currency' => 'USD',
            'square_payment_id' => (string) Str::uuid(),
            'square_order_id' => (string) Str::uuid(),
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        app(InvoiceService::class)->generateForPeriod($start, $end);
        $invoice = Invoice::first();

        Sanctum::actingAs($admin);

        $listResponse = $this->getJson('/api/admin/invoices');
        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.0.id', $invoice->id);

        $markResponse = $this->patchJson("/api/admin/invoices/{$invoice->id}/mark-paid", [
            'paid_at' => now()->toISOString(),
            'amount_paid' => 200,
        ]);
        $markResponse->assertOk();
        $markResponse->assertJsonPath('data.is_paid', true);
        $this->assertTrue($invoice->fresh()->is_paid);
        $this->assertEquals(200.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_admin_can_download_invoice_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $service = Service::factory()->create();

        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'scheduled_date' => $start->copy()->addDay(),
            'completed_at' => $start->copy()->addDay()->setTime(14, 0),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'total_quote' => 120,
            'base_quote' => 100,
            'tax_amount' => 20,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 120,
            'quantity' => 1,
            'photographer_pay' => 120,
            'photographer_id' => $photographer->id,
        ]);

        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 120,
            'currency' => 'USD',
            'square_payment_id' => (string) Str::uuid(),
            'square_order_id' => (string) Str::uuid(),
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        app(InvoiceService::class)->generateForPeriod($start, $end);
        $invoice = Invoice::first();

        Sanctum::actingAs($admin);

        $response = $this->get('/api/admin/invoices/' . $invoice->id . '/download');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertNotNull($response->headers->get('content-disposition'));
    }

    public function test_sales_rep_weekly_commission_generation_uses_sunday_to_saturday_scheduled_shoots_and_service_exclusions(): void
    {
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 15,
                ],
            ],
        ]);
        $client = User::factory()->create(['role' => 'client']);
        $baseService = Service::factory()->create([
            'name' => 'Base photo package',
            'price' => 1000,
            'exclude_from_sales_commission' => false,
        ]);
        $travelService = Service::factory()->create([
            'name' => 'Travel fee',
            'price' => 100,
            'exclude_from_sales_commission' => true,
        ]);

        $start = Carbon::parse('2026-04-05')->startOfDay(); // Sunday
        $end = Carbon::parse('2026-04-11')->endOfDay(); // Saturday

        $sundayShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-05',
            'total_quote' => 1100,
            'base_quote' => 1000,
        ]);
        $sundayShoot->services()->attach($baseService->id, ['price' => 1000, 'quantity' => 1]);
        $sundayShoot->services()->attach($travelService->id, ['price' => 100, 'quantity' => 1]);

        $saturdayShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-11',
            'total_quote' => 400,
            'base_quote' => 400,
        ]);
        $saturdayShoot->services()->attach($baseService->id, ['price' => 400, 'quantity' => 1]);

        $holdShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => null,
            'workflow_status' => 'on_hold',
            'total_quote' => 900,
            'base_quote' => 900,
        ]);
        $holdShoot->services()->attach($baseService->id, ['price' => 900, 'quantity' => 1]);

        $invoices = app(InvoiceService::class)->generateSalesRepInvoicesForPeriod($start, $end);

        $this->assertCount(1, $invoices);

        $invoice = $invoices->first()->fresh(['items', 'shoots']);
        $this->assertSame(Invoice::ROLE_SALES_REP, $invoice->role);
        $this->assertSame(Invoice::APPROVAL_STATUS_PENDING, $invoice->approval_status);
        $this->assertEquals(210.0, (float) $invoice->total_amount);
        $this->assertSame(2, $invoice->shoots()->count());
        $this->assertTrue($invoice->shoots->contains('id', $sundayShoot->id));
        $this->assertTrue($invoice->shoots->contains('id', $saturdayShoot->id));
        $this->assertFalse($invoice->shoots->contains('id', $holdShoot->id));
        $this->assertSame([], $invoice->unresolved_warnings);

        $sundayItem = $invoice->items
            ->where('type', InvoiceItem::TYPE_CHARGE)
            ->firstWhere('shoot_id', $sundayShoot->id);

        $this->assertEquals(150.0, (float) $sundayItem->total_amount);
        $this->assertEquals(1000.0, (float) $sundayItem->meta['commissionable_gross']);
        $this->assertEquals(100.0, (float) $sundayItem->meta['excluded_fees_total']);
        $this->assertEquals(15.0, (float) $sundayItem->meta['commission_rate']);
    }
}
