<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesRepInvoiceClientNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_sales_rep_lines_store_the_shoot_client_without_changing_commission(): void
    {
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => ['repDetails' => ['commissionPercentage' => 10]],
        ]);
        $client = User::factory()->create(['role' => 'client', 'name' => 'Jane Smith']);
        $service = Service::factory()->create(['price' => 500, 'exclude_from_sales_commission' => false]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'address' => '1234 Main St',
            'scheduled_date' => '2026-04-06',
            'total_quote' => 500,
            'base_quote' => 500,
        ]);
        $shoot->services()->attach($service->id, ['price' => 500, 'quantity' => 1]);

        $invoices = app(InvoiceService::class)->generateSalesRepInvoicesForPeriod(
            Carbon::parse('2026-04-05')->startOfDay(),
            Carbon::parse('2026-04-11')->endOfDay(),
        );

        $item = $invoices->first()->fresh('items')->items->firstWhere('shoot_id', $shoot->id);
        $this->assertSame('Jane Smith', $item->meta['client_name']);
        $this->assertSame('Jane Smith', $item->client_name);
        $this->assertStringContainsString('1234 Main St', $item->description);
        $this->assertEquals(50.0, (float) $item->total_amount);
        $this->assertEquals(500.0, (float) $item->meta['commissionable_gross']);
    }

    public function test_existing_lines_without_stored_client_show_the_shoot_client_on_every_payout_view(): void
    {
        [$salesRep, $invoice, $shoot, $legacyItem, $storedItem, $expense] = $this->legacySalesRepInvoice();

        Sanctum::actingAs($salesRep);
        $index = $this->getJson('/api/salesrep/invoices')->assertOk();
        $lines = collect($index->json('data.0.items'))->keyBy('id');
        $this->assertSame('Jane Smith', $lines[$legacyItem->id]['client_name']);
        $this->assertSame('Stored Client', $lines[$storedItem->id]['client_name']);
        $this->assertNull($lines[$expense->id]['client_name']);

        $show = $this->getJson('/api/salesrep/invoices/'.$invoice->id)->assertOk();
        $this->assertSame('Jane Smith', collect($show->json('items'))->firstWhere('id', $legacyItem->id)['client_name']);

        $edit = $this->getJson('/api/salesrep/invoices/'.$invoice->id.'/edit')->assertOk();
        $this->assertSame('Jane Smith', collect($edit->json('invoice.items'))->firstWhere('id', $legacyItem->id)['client_name']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $detail = $this->getJson('/api/admin/invoices/'.$invoice->id.'/review-detail')->assertOk();
        $detailLines = collect($detail->json('data.items'))->keyBy('id');
        $this->assertSame('Jane Smith', $detailLines[$legacyItem->id]['client_name']);
        $this->assertSame('Stored Client', $detailLines[$storedItem->id]['client_name']);

        // Reading the invoice never writes the derived name back to the line.
        $this->assertArrayNotHasKey('client_name', $legacyItem->fresh()->meta);
        $this->assertSame(150.0, (float) $invoice->fresh()->total_amount);
    }

    public function test_payout_invoice_csv_names_each_lines_client(): void
    {
        [, $invoice, $shoot, $legacyItem, $storedItem] = $this->legacySalesRepInvoice();
        Sanctum::actingAs(User::factory()->admin()->create());

        $rows = $this->csvRows($this->get('/api/admin/invoices/'.$invoice->id.'/download')->assertOk()->streamedContent());

        $this->assertContains(['Type', 'Description', 'Client', 'Quantity', 'Unit Amount', 'Line Total', 'Shoot ID'], $rows);
        $this->assertContains([InvoiceItem::TYPE_CHARGE, $legacyItem->description, 'Jane Smith', '1', '100.00', '100.00', (string) $shoot->id], $rows);
        $this->assertContains([InvoiceItem::TYPE_CHARGE, $storedItem->description, 'Stored Client', '1', '50.00', '50.00', (string) $shoot->id], $rows);
    }

    public function test_client_invoice_csv_keeps_its_line_columns(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $invoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => null,
            'client_id' => $client->id,
        ]);
        $invoice->items()->create([
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Photos',
            'quantity' => 1,
            'unit_amount' => 10,
            'total_amount' => 10,
        ]);
        Sanctum::actingAs($client);

        $rows = $this->csvRows($this->get('/api/invoices/'.$invoice->id.'/download')->assertOk()->streamedContent());

        $this->assertContains(['Type', 'Description', 'Quantity', 'Unit Amount', 'Line Total', 'Shoot ID'], $rows);
    }

    public function test_invoice_generated_email_lists_the_client_under_each_line(): void
    {
        $html = view('emails.invoice_generated', [
            'invoice' => (object) [
                'invoice_number' => 'W-1',
                'status' => 'draft',
                'total_amount' => 100,
                'items' => collect([(object) [
                    'description' => 'Shoot #7 - 1234 Main St (Commission 10% on $1,000.00)',
                    'client_name' => 'Jane Smith',
                    'type' => 'charge',
                    'total_amount' => 100,
                ]]),
            ],
            'period' => 'Apr 5 - Apr 11, 2026',
            'recipientRole' => 'sales rep',
            'recipient' => (object) ['name' => 'Sam Rep'],
        ])->render();

        $this->assertStringContainsString('1234 Main St', $html);
        $this->assertStringContainsString('Client: Jane Smith', $html);
    }

    /**
     * A sales rep invoice generated before client names were stored, plus one
     * line that already stores a (different) client name and an expense.
     */
    private function legacySalesRepInvoice(): array
    {
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'name' => 'Jane Smith']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'address' => '1234 Main St',
            'scheduled_date' => '2026-04-06',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $salesRep->id,
            'role' => Invoice::ROLE_SALES_REP,
            'sales_rep_id' => $salesRep->id,
            'photographer_id' => null,
            'client_id' => null,
            'shoot_id' => null,
            'period_start' => '2026-04-05',
            'period_end' => '2026-04-11',
            'billing_period_start' => '2026-04-05',
            'billing_period_end' => '2026-04-11',
            'status' => Invoice::STATUS_DRAFT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
            'total' => 150,
            'total_amount' => 150,
        ]);
        $invoice->shoots()->attach($shoot->id);
        $legacyItem = $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Shoot #'.$shoot->id.' - 1234 Main St (Commission 10% on $1,000.00)',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
            'meta' => ['commissionable_gross' => 1000, 'commission_rate' => 10],
        ]);
        $storedItem = $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Complimentary reshoot #'.$shoot->id.' - 1234 Main St (Rep compensation)',
            'quantity' => 1,
            'unit_amount' => 50,
            'total_amount' => 50,
            'meta' => ['client_name' => 'Stored Client'],
        ]);
        $expense = $invoice->items()->create([
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Parking',
            'quantity' => 1,
            'unit_amount' => 0,
            'total_amount' => 0,
        ]);

        return [$salesRep, $invoice, $shoot, $legacyItem, $storedItem, $expense];
    }

    private function csvRows(string $content): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
