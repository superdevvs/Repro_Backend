<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Service;
use App\Models\Shoot;
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

    /**
     * Task 23.3: a shoot whose service items are assigned to different
     * photographers must produce one weekly invoice per photographer, each
     * carrying ONLY that photographer's resolved service pay on the invoice
     * record (never the whole-shoot total). This pins the server-computed total
     * (23.1/23.2) for the per-service-assignment case.
     */
    public function test_weekly_invoice_total_is_per_service_assigned_photographer(): void
    {
        $photographerA = User::factory()->photographer()->create();
        $photographerB = User::factory()->photographer()->create();
        $client = User::factory()->create();
        $servicePhotos = Service::factory()->create(['name' => 'Photos']);
        $serviceVideo = Service::factory()->create(['name' => 'Video']);

        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        // One shoot, two service items assigned to two different photographers.
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographerA->id,
            'client_id' => $client->id,
            'service_id' => $servicePhotos->id,
            'scheduled_date' => $start->copy()->addDays(2),
            'completed_at' => $start->copy()->addDays(2)->setTime(14, 0),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'base_quote' => 500,
            'tax_amount' => 0,
            'total_quote' => 500,
        ]);
        $shoot->services()->attach($servicePhotos->id, [
            'price' => 300,
            'quantity' => 1,
            'photographer_pay' => 120,
            'photographer_id' => $photographerA->id,
        ]);
        $shoot->services()->attach($serviceVideo->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => 80,
            'photographer_id' => $photographerB->id,
        ]);

        $invoices = app(InvoiceService::class)->generateForPeriod($start, $end);

        // One invoice per resolved photographer.
        $this->assertCount(2, $invoices);

        $invoiceA = Invoice::where('photographer_id', $photographerA->id)
            ->where('role', Invoice::ROLE_PHOTOGRAPHER)->first();
        $invoiceB = Invoice::where('photographer_id', $photographerB->id)
            ->where('role', Invoice::ROLE_PHOTOGRAPHER)->first();

        $this->assertNotNull($invoiceA);
        $this->assertNotNull($invoiceB);

        // Each total comes from the invoice record and reflects ONLY that
        // photographer's assigned service pay — not the whole-shoot total (500).
        $this->assertEquals(120.0, (float) $invoiceA->total_amount);
        $this->assertEquals(80.0, (float) $invoiceB->total_amount);

        // Each invoice has exactly its one assigned service line.
        $this->assertSame(1, $invoiceA->items()->where('type', InvoiceItem::TYPE_CHARGE)->count());
        $this->assertSame(1, $invoiceB->items()->where('type', InvoiceItem::TYPE_CHARGE)->count());
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

        $response = $this->get('/api/admin/invoices/'.$invoice->id.'/download');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertNotNull($response->headers->get('content-disposition'));
    }

    public function test_client_can_download_invoice_csv_without_photographer_or_billing_period_columns(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $invoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'client_id' => $client->id,
            'shoot_id' => null,
            'photographer_id' => null,
            'invoice_number' => 'CLIENT 00042',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'billing_period_start' => null,
            'billing_period_end' => null,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'total' => 125,
            'total_amount' => null,
            'amount_paid' => 0,
            'is_paid' => false,
        ]);

        Sanctum::actingAs($client);

        $response = $this->get('/api/invoices/'.$invoice->id.'/download');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'invoice-client-00042-20260801-to-20260815.csv',
            (string) $response->headers->get('content-disposition')
        );

        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Invoice Number","CLIENT 00042"', $csv);
        $this->assertStringContainsString($client->name, $csv);
        $this->assertStringContainsString('"Billing Period","2026-08-01 - 2026-08-15"', $csv);
        $this->assertStringContainsString('Total,125.00', $csv);
    }

    public function test_invoice_index_uses_period_overlap_for_payouts_and_issue_date_for_clients(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();
        $salesRep = User::factory()->create(['role' => Invoice::ROLE_SALES_REP]);
        $client = User::factory()->create(['role' => 'client']);

        $overlappingPayout = Invoice::factory()->create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'shoot_id' => null,
            'client_id' => null,
            'photographer_id' => $photographer->id,
            'period_start' => '2026-08-25',
            'period_end' => '2026-09-02',
            'billing_period_start' => '2026-08-25',
            'billing_period_end' => '2026-09-02',
            'issue_date' => '2026-08-25',
            'due_date' => '2026-09-02',
        ]);
        $outsidePayout = Invoice::factory()->create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'shoot_id' => null,
            'client_id' => null,
            'photographer_id' => $photographer->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'billing_period_start' => '2026-08-01',
            'billing_period_end' => '2026-08-07',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-07',
        ]);
        $overlappingSalesRepPayout = Invoice::factory()->create([
            'user_id' => $salesRep->id,
            'role' => Invoice::ROLE_SALES_REP,
            'shoot_id' => null,
            'client_id' => null,
            'photographer_id' => null,
            'sales_rep_id' => $salesRep->id,
            'period_start' => '2026-08-31',
            'period_end' => '2026-09-06',
            'billing_period_start' => '2026-08-31',
            'billing_period_end' => '2026-09-06',
            'issue_date' => null,
            'due_date' => null,
        ]);
        $issuedClientInvoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'client_id' => $client->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-15',
            'billing_period_start' => null,
            'billing_period_end' => null,
            'issue_date' => '2026-08-31',
            'due_date' => '2026-09-15',
        ]);
        $legacyClientInvoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'client_id' => $client->id,
            'period_start' => '2026-08-31',
            'period_end' => '2026-09-15',
            'billing_period_start' => null,
            'billing_period_end' => null,
            'issue_date' => null,
            'due_date' => '2026-09-15',
        ]);
        $dueOnlyClientInvoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'client_id' => $client->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'billing_period_start' => null,
            'billing_period_end' => null,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson(
            '/api/admin/invoices?start=2026-08-31&end=2026-08-31&per_page=100'
        );

        $response->assertOk();
        $returnedIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            $overlappingPayout->id,
            $overlappingSalesRepPayout->id,
            $issuedClientInvoice->id,
            $legacyClientInvoice->id,
        ], $returnedIds);
        $this->assertNotContains($outsidePayout->id, $returnedIds);
        $this->assertNotContains($dueOnlyClientInvoice->id, $returnedIds);
    }

    public function test_invoice_index_rejects_invalid_date_ranges_and_page_sizes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/invoices?start=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start');

        $this->getJson('/api/admin/invoices?start=2026-09-01&end=2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');

        $this->getJson('/api/admin/invoices?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_invoice_csv_exports_items_refund_aware_totals_and_neutralized_formula_fields(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => '+SUM(1,1)',
        ]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => $shoot->id,
            'client_id' => $client->id,
            'invoice_number' => 'REFUND-CSV-1',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'billing_period_start' => null,
            'billing_period_end' => null,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'subtotal' => 125,
            'tax' => 0,
            'total' => 125,
            'total_amount' => 125,
            'amount_paid' => 125,
            'payments_total' => 125,
            'balance_due' => 0,
            'status' => Invoice::STATUS_PAID,
            'is_paid' => true,
        ]);
        $invoice->items()->create([
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => '=2+2',
            'quantity' => 1,
            'unit_amount' => 125,
            'total_amount' => 125,
        ]);
        $payment = Payment::create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice->id,
            'amount' => 125,
            'currency' => 'USD',
            'square_payment_id' => (string) Str::uuid(),
            'square_order_id' => (string) Str::uuid(),
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        PaymentRefund::create([
            'payment_id' => $payment->id,
            'shoot_id' => $shoot->id,
            'amount' => 25,
            'provider' => 'stripe',
            'provider_refund_id' => 'refund-csv-test',
        ]);

        Sanctum::actingAs($client);

        $response = $this->get('/api/invoices/'.$invoice->id.'/download');

        $response->assertOk();
        $csvStream = fopen('php://temp', 'w+');
        fwrite($csvStream, $response->streamedContent());
        rewind($csvStream);
        $rows = collect();
        while (($row = fgetcsv($csvStream)) !== false) {
            $rows->push($row);
        }
        fclose($csvStream);

        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Client', "'+SUM(1,1)"]));
        $this->assertTrue($rows->contains(fn (array $row) => $row === [
            InvoiceItem::TYPE_CHARGE,
            "'=2+2",
            '1',
            '125.00',
            '125.00',
            '',
        ]));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Amount Paid', '100.00']));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Balance', '25.00']));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Paid', 'No']));
    }

    public function test_payout_invoice_csv_does_not_treat_client_shoot_payments_as_payout_settlement(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'total_quote' => 500,
        ]);
        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 500,
            'currency' => 'USD',
            'square_payment_id' => (string) Str::uuid(),
            'square_order_id' => (string) Str::uuid(),
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'shoot_id' => null,
            'client_id' => null,
            'photographer_id' => $photographer->id,
            'period_start' => '2026-08-24',
            'period_end' => '2026-08-30',
            'billing_period_start' => '2026-08-24',
            'billing_period_end' => '2026-08-30',
            'issue_date' => null,
            'due_date' => null,
            'total' => 100,
            'total_amount' => 100,
            'amount_paid' => 500,
            // Payout generation may retain client receipts in this legacy
            // aggregate; it is not the amount paid to the photographer.
            'payments_total' => 500,
            'balance_due' => 100,
            'status' => Invoice::STATUS_DRAFT,
            'is_paid' => true,
        ]);
        $invoice->shoots()->attach($shoot->id);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/admin/invoices/'.$invoice->id.'/download');

        $response->assertOk();
        $csvStream = fopen('php://temp', 'w+');
        fwrite($csvStream, $response->streamedContent());
        rewind($csvStream);
        $rows = collect();
        while (($row = fgetcsv($csvStream)) !== false) {
            $rows->push($row);
        }
        fclose($csvStream);

        $this->assertTrue($rows->contains(fn (array $row) => ($row[0] ?? null) === (string) $shoot->id
            && ($row[4] ?? null) === '500.00'));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Amount Paid', '0.00']));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Balance', '100.00']));
        $this->assertTrue($rows->contains(fn (array $row) => $row === ['Paid', 'No']));
    }

    public function test_cors_exposes_invoice_download_filenames_to_browser_code(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $invoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'client_id' => $client->id,
        ]);
        Sanctum::actingAs($client);

        $response = $this
            ->withHeader('Origin', 'https://reprodashboard.com')
            ->get('/api/invoices/'.$invoice->id.'/download');

        $response->assertOk();
        $exposedHeaders = collect(explode(
            ',',
            strtolower((string) $response->headers->get('Access-Control-Expose-Headers'))
        ))->map(fn (string $header) => trim($header));

        $this->assertContains('content-disposition', $exposedHeaders->all());
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

    public function test_photographer_can_edit_pending_invoice_and_payload_exposes_can_edit(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = Invoice::create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'photographer_id' => $photographer->id,
            'period_start' => Carbon::now()->startOfWeek(),
            'period_end' => Carbon::now()->endOfWeek(),
            'billing_period_start' => Carbon::now()->startOfWeek(),
            'billing_period_end' => Carbon::now()->endOfWeek(),
            'total_amount' => 0,
            'amount_paid' => 0,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($photographer);

        $this->getJson("/api/photographer/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson(['can_edit' => true, 'edit_locked_reason' => null]);

        $this->postJson("/api/photographer/invoices/{$invoice->id}/expenses", [
            'description' => 'Parking',
            'amount' => 12.5,
        ])->assertCreated();
    }

    public function test_photographer_edit_is_blocked_with_named_reason_once_accounts_approved(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = Invoice::create([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'photographer_id' => $photographer->id,
            'period_start' => Carbon::now()->startOfWeek(),
            'period_end' => Carbon::now()->endOfWeek(),
            'billing_period_start' => Carbon::now()->startOfWeek(),
            'billing_period_end' => Carbon::now()->endOfWeek(),
            'total_amount' => 0,
            'amount_paid' => 0,
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($photographer);

        $this->getJson("/api/photographer/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson(['can_edit' => false])
            ->assertJsonPath('edit_locked_reason', fn ($reason) => is_string($reason) && str_contains($reason, 'approved by accounts'));

        $this->postJson("/api/photographer/invoices/{$invoice->id}/expenses", [
            'description' => 'Parking',
            'amount' => 12.5,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => is_string($message) && str_contains($message, 'approved by accounts'));
    }
}
