<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBillingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2025-10-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_client_billing_includes_invoice_items_and_uninvoiced_shoot_balances(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Client One']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = $this->createService();

        $invoiceShoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-10-10'), 250.00);
        $upcomingShoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-11-10'), 175.00);
        $upcomingShoot->update([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'payment_status' => 'unpaid',
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $invoiceShoot->id,
            'invoice_number' => 'INV-CLIENT-1',
            'issue_date' => '2025-10-10',
            'due_date' => '2025-10-15',
            'subtotal' => 250.00,
            'tax' => 0,
            'total' => 250.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $response->assertJsonPath('summary.dueNow.amount', 250);
        $response->assertJsonPath('summary.dueNow.count', 1);
        $response->assertJsonPath('summary.upcoming.amount', 175);
        $response->assertJsonPath('summary.upcoming.count', 1);
        $response->assertJsonPath('summary.paymentRequiredToReleaseCount', 1);
        $response->assertJsonCount(2, 'items');
        $response->assertJsonFragment([
            'source' => 'invoice',
            'number' => 'INV-CLIENT-1',
            'bucket' => 'due_now',
        ]);
        $response->assertJsonFragment([
            'source' => 'shoot_balance',
            'shootId' => $upcomingShoot->id,
            'bucket' => 'upcoming',
        ]);
    }

    public function test_client_billing_deduplicates_a_shoot_when_invoice_items_reference_it(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = $this->createService();

        $shoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-10-12'), 300.00);

        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-DEDUPE',
            'issue_date' => '2025-10-12',
            'due_date' => '2025-10-20',
            'subtotal' => 300.00,
            'tax' => 0,
            'total' => 300.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Shoot charge',
            'quantity' => 1,
            'unit_amount' => 300.00,
            'total_amount' => 300.00,
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('summary.dueNow.count', 1);
    }

    public function test_client_billing_keeps_draft_and_sent_invoices_open(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-DRAFT',
            'issue_date' => '2025-10-01',
            'due_date' => '2025-10-05',
            'subtotal' => 120.00,
            'tax' => 0,
            'total' => 120.00,
            'status' => Invoice::STATUS_DRAFT,
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-SENT',
            'issue_date' => '2025-10-02',
            'due_date' => '2025-10-06',
            'subtotal' => 180.00,
            'tax' => 0,
            'total' => 180.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $response->assertJsonPath('summary.dueNow.count', 2);
        $response->assertJsonFragment(['number' => 'INV-DRAFT', 'status' => 'overdue']);
        $response->assertJsonFragment(['number' => 'INV-SENT', 'status' => 'overdue']);
    }

    public function test_client_billing_separates_due_now_and_upcoming_buckets(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-OVERDUE',
            'issue_date' => '2025-10-01',
            'due_date' => now()->subDays(2)->toDateString(),
            'subtotal' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-UPCOMING',
            'issue_date' => '2025-10-01',
            'due_date' => now()->addDays(5)->toDateString(),
            'subtotal' => 75.00,
            'tax' => 0,
            'total' => 75.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $response->assertJsonPath('summary.dueNow.amount', 100);
        $response->assertJsonPath('summary.upcoming.amount', 75);
        $response->assertJsonFragment(['number' => 'INV-OVERDUE', 'bucket' => 'due_now', 'status' => 'overdue']);
        $response->assertJsonFragment(['number' => 'INV-UPCOMING', 'bucket' => 'upcoming', 'status' => 'pending']);
    }

    public function test_client_billing_updates_when_invoice_or_shoot_is_paid(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = $this->createService();

        $shoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-11-01'), 220.00);
        $shoot->update([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'payment_status' => 'unpaid',
        ]);

        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $this->createShoot($client, $photographer, $service, Carbon::parse('2025-10-01'), 140.00)->id,
            'invoice_number' => 'INV-PAY-ME',
            'issue_date' => '2025-10-01',
            'due_date' => now()->subDay()->toDateString(),
            'subtotal' => 140.00,
            'tax' => 0,
            'total' => 140.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($client);

        $initial = $this->getJson('/api/client/billing');
        $initial->assertJsonPath('summary.dueNow.count', 1);
        $initial->assertJsonPath('summary.upcoming.count', 1);
        $initial->assertJsonPath('summary.paid.count', 0);

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'amount_paid' => 140.00,
            'is_paid' => true,
            'paid_at' => now(),
        ]);

        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 220.00,
            'currency' => 'USD',
            'square_payment_id' => 'shoot-paid-in-full',
            'square_order_id' => 'shoot-order-paid',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $updated = $this->getJson('/api/client/billing');

        $updated->assertOk();
        $updated->assertJsonPath('summary.dueNow.count', 0);
        $updated->assertJsonPath('summary.upcoming.count', 0);
        $updated->assertJsonPath('summary.paid.count', 2);
        $updated->assertJsonPath('summary.paymentRequiredToReleaseCount', 0);
    }

    public function test_live_payment_balance_wins_over_a_stale_stored_balance(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = $this->createService();
        $shoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-10-01'), 140.00);
        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'invoice_number' => 'INV-STALE-BALANCE',
            'subtotal' => 140.00,
            'tax' => 0,
            'total' => 140.00,
            'total_amount' => 140.00,
            'amount_paid' => 0,
            'payments_total' => 0,
            'balance_due' => 140.00,
            'status' => Invoice::STATUS_SENT,
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'shoot_id' => $shoot->id,
            'amount' => 140.00,
            'currency' => 'USD',
            'payment_method' => 'other',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        Sanctum::actingAs($client);
        $response = $this->getJson('/api/client/billing');
        $response->assertOk();
        $response->assertJsonPath('summary.dueNow.count', 0);
        $response->assertJsonPath('summary.paid.count', 1);
        $response->assertJsonPath('items.0.balance', 0);
        $response->assertJsonPath('items.0.bucket', 'paid');
    }


    public function test_marking_related_shoot_paid_clears_kline_invoice_00038_balance_and_release_block(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Elizabeth Kline']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = $this->createService();

        $shoot = $this->createShoot($client, $photographer, $service, Carbon::parse('2025-10-01'), 1055.00);
        $shoot->update([
            'address' => '9137 Lakeland Valley Court',
            'city' => 'Springfield',
            'state' => 'VA',
            'zip' => '22153',
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'payment_status' => 'paid',
        ]);

        $invoice = Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'role' => 'legacy',
            'invoice_number' => '00038',
            'issue_date' => '2025-10-01',
            'due_date' => now()->subDay()->toDateString(),
            'subtotal' => 1055.00,
            'tax' => 105.30,
            'total' => 1160.30,
            'total_amount' => 1160.30,
            'amount_paid' => 105.30,
            'status' => Invoice::STATUS_SENT,
            'is_paid' => false,
        ]);
        $invoice->forceFill([
            'payments_total' => 105.30,
            'balance_due' => 1055.00,
        ])->save();

        Payment::create([
            'shoot_id' => $shoot->id,
            'invoice_id' => null,
            'amount' => 1055.00,
            'currency' => 'USD',
            'payment_method' => 'other',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('number', '00038');
        $this->assertNotNull($item);
        $this->assertSame(0, $item['balance']);
        $this->assertSame('paid', $item['status']);
        $this->assertSame('paid', $item['bucket']);
        $this->assertFalse($item['paymentRequiredToRelease']);
        $response->assertJsonPath('summary.dueNow.count', 0);
        $response->assertJsonPath('summary.paid.count', 1);
        $response->assertJsonPath('summary.paymentRequiredToReleaseCount', 0);
    }

    public function test_client_billing_is_scoped_to_the_authenticated_client(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $otherClient = User::factory()->create(['role' => 'client']);

        Invoice::factory()->create([
            'client_id' => $otherClient->id,
            'invoice_number' => 'INV-OTHER',
            'issue_date' => '2025-10-01',
            'due_date' => '2025-10-05',
            'subtotal' => 90.00,
            'tax' => 0,
            'total' => 90.00,
            'status' => Invoice::STATUS_SENT,
        ]);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/client/billing');

        $response->assertOk();
        $response->assertJsonCount(0, 'items');
    }

    public function test_non_clients_cannot_access_client_billing_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/client/billing');

        $response->assertForbidden();
    }

    private function createService(): Service
    {
        $category = Category::create(['name' => 'Residential']);

        return Service::create([
            'name' => 'Standard Shoot',
            'description' => 'A standard residential package',
            'price' => 250.00,
            'delivery_time' => 48,
            'category_id' => $category->id,
        ]);
    }

    private function createShoot(User $client, User $photographer, Service $service, Carbon $date, float $totalQuote): Shoot
    {
        return Shoot::create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'service_category' => 'Residential',
            'address' => '123 Main St',
            'city' => 'Sample City',
            'state' => 'CA',
            'zip' => '90001',
            'scheduled_date' => $date,
            'time' => '10:00',
            'base_quote' => $totalQuote - 25,
            'tax_amount' => 25,
            'total_quote' => $totalQuote,
            'payment_status' => 'partial',
            'payment_type' => 'card',
            'status' => 'completed',
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'created_by' => 'system',
        ]);
    }
}
