<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_invoice_summary_returns_aggregated_totals_for_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-08-15 12:00:00'));

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        $client = User::factory()->create();

        $shootOne = Shoot::factory()->create([
            'client_id' => $client->id,
        ]);

        $shootTwo = Shoot::factory()->create([
            'client_id' => $client->id,
        ]);

        $invoiceOne = Invoice::factory()->create([
            'shoot_id' => $shootOne->id,
            'client_id' => $client->id,
            'issue_date' => Carbon::now()->startOfMonth()->addDays(1),
            'due_date' => Carbon::now()->startOfMonth()->addDays(10),
            'subtotal' => 200,
            'tax' => 20,
            'total' => 220,
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoiceOne->id,
            'shoot_id' => $shootOne->id,
            'amount' => 100,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => Carbon::now()->startOfMonth()->addDays(2),
        ]);

        $invoiceTwo = Invoice::factory()->create([
            'shoot_id' => $shootTwo->id,
            'client_id' => $client->id,
            'issue_date' => Carbon::now()->startOfMonth()->addDays(5),
            'due_date' => Carbon::now()->startOfMonth()->addDays(15),
            'subtotal' => 150,
            'tax' => 0,
            'total' => 150,
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $shootOne->id,
            'issue_date' => Carbon::now()->subMonth()->startOfMonth()->addDays(3),
            'due_date' => Carbon::now()->subMonth()->startOfMonth()->addDays(10),
            'subtotal' => 300,
            'tax' => 30,
            'total' => 330,
        ]);

        $response = $this->getJson('/api/reports/invoices/summary?range=month');

        $response->assertOk();

        $summary = $response->json('summary');

        $this->assertSame('month', $summary['range']);
        $this->assertSame(2, $summary['invoice_count']);
        $this->assertEquals(370.0, $summary['total_invoiced']);
        $this->assertEquals(100.0, $summary['total_paid']);
        $this->assertEquals(270.0, $summary['total_outstanding']);

        $breakdown = $response->json('breakdown');
        $this->assertCount(1, $breakdown);
        $this->assertEquals(370.0, $breakdown[0]['total_invoiced']);
        $this->assertEquals(100.0, $breakdown[0]['total_paid']);
        $this->assertEquals(270.0, $breakdown[0]['total_outstanding']);
    }

    public function test_weekly_invoice_report_always_uses_sunday_through_saturday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $response = $this->getJson('/api/reports/invoices/summary?range=week');

        $response->assertOk();
        $response->assertJsonPath('summary.range', 'week');
        $response->assertJsonPath('summary.start', '2026-08-30');
        $response->assertJsonPath('summary.end', '2026-09-05');
    }

    public function test_past_due_endpoint_returns_overdue_invoices(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-08-20 09:00:00'));

        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        $client = User::factory()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
        ]);

        $pastDue = Invoice::factory()->create([
            'shoot_id' => $shoot->id,
            'client_id' => $client->id,
            'issue_date' => Carbon::now()->subWeeks(3),
            'due_date' => Carbon::now()->subDays(5),
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
        ]);

        Payment::factory()->create([
            'invoice_id' => $pastDue->id,
            'shoot_id' => $shoot->id,
            'amount' => 200,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => Carbon::now()->subDays(10),
        ]);

        $fullyPaid = Invoice::factory()->create([
            'shoot_id' => $shoot->id,
            'client_id' => $client->id,
            'issue_date' => Carbon::now()->subWeeks(4),
            'due_date' => Carbon::now()->subDays(3),
            'subtotal' => 250,
            'tax' => 0,
            'total' => 250,
        ]);

        Payment::factory()->create([
            'invoice_id' => $fullyPaid->id,
            'shoot_id' => $shoot->id,
            'amount' => 250,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => Carbon::now()->subDays(2),
        ]);

        Invoice::factory()->create([
            'shoot_id' => $shoot->id,
            'client_id' => $client->id,
            'issue_date' => Carbon::now()->subWeek(),
            'due_date' => Carbon::now()->addDays(7),
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        $response = $this->getJson('/api/reports/invoices/past-due');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($pastDue->id, $data[0]['id']);
        $this->assertEquals($pastDue->due_date->toDateString(), $data[0]['due_date']);
        $this->assertEquals(500.0, $data[0]['total']);
        $this->assertEquals(200.0, $data[0]['total_paid']);
        $this->assertEquals(300.0, $data[0]['balance_due']);
        $this->assertSame($client->name, $data[0]['client']['name']);
    }
}
