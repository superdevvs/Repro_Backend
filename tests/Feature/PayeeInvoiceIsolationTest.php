<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayeeInvoiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_routes_never_expose_or_modify_a_client_invoice(): void
    {
        $client = User::factory()->create();
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id]);
        $clientInvoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'shoot_id' => $shoot->id,
            'role' => Invoice::ROLE_CLIENT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
        ]);
        $clientItem = $clientInvoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Client service',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
            'recorded_at' => now(),
        ]);
        $payoutInvoice = Invoice::factory()->create([
            'user_id' => $photographer->id,
            'client_id' => null,
            'photographer_id' => $photographer->id,
            'shoot_id' => null,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
        ]);

        Sanctum::actingAs($photographer);

        $index = $this->getJson('/api/photographer/invoices');
        $index->assertOk();
        $this->assertSame([$payoutInvoice->id], collect($index->json('data'))->pluck('id')->all());
        $this->getJson("/api/photographer/invoices/{$clientInvoice->id}")->assertForbidden();
        $this->postJson("/api/photographer/invoices/{$clientInvoice->id}/expenses", ['description' => 'Travel', 'amount' => 50])->assertForbidden();
        $this->deleteJson("/api/photographer/invoices/{$clientInvoice->id}/expenses/{$clientItem->id}")->assertForbidden();
        $this->postJson("/api/photographer/invoices/{$clientInvoice->id}/charges", ['description' => 'Misc', 'amount' => 1000])->assertForbidden();
        $this->deleteJson("/api/photographer/invoices/{$clientInvoice->id}/charges/{$clientItem->id}")->assertForbidden();
        $this->patchJson("/api/photographer/invoices/{$clientInvoice->id}/items/{$clientItem->id}", ['amount' => 999])->assertForbidden();
        $this->postJson("/api/photographer/invoices/{$clientInvoice->id}/reject")->assertForbidden();
        $this->postJson("/api/photographer/invoices/{$clientInvoice->id}/submit-for-approval")->assertForbidden();

        $this->assertFalse($clientInvoice->fresh()->canBeModifiedByPayee());
        $this->assertSame(1, $clientInvoice->items()->count());
        $this->assertSame(100.0, (float) $clientItem->fresh()->total_amount);
        $this->assertTrue($payoutInvoice->fresh()->canBeModifiedByPayee());
    }

    public function test_sales_rep_routes_never_expose_or_modify_a_client_invoice(): void
    {
        $client = User::factory()->create();
        $salesRep = User::factory()->create(['role' => Invoice::ROLE_SALES_REP]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $clientInvoice = Invoice::factory()->create([
            'user_id' => $client->id,
            'client_id' => $client->id,
            'sales_rep_id' => $salesRep->id,
            'shoot_id' => $shoot->id,
            'role' => Invoice::ROLE_CLIENT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
        ]);
        $clientItem = $clientInvoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Client adjustment',
            'quantity' => 1,
            'unit_amount' => 25,
            'total_amount' => 25,
            'recorded_at' => now(),
        ]);
        $payoutInvoice = Invoice::factory()->create([
            'user_id' => $salesRep->id,
            'client_id' => null,
            'sales_rep_id' => $salesRep->id,
            'shoot_id' => null,
            'role' => Invoice::ROLE_SALES_REP,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING,
        ]);

        Sanctum::actingAs($salesRep);

        $index = $this->getJson('/api/salesrep/invoices');
        $index->assertOk();
        $this->assertSame([$payoutInvoice->id], collect($index->json('data'))->pluck('id')->all());
        $this->getJson("/api/salesrep/invoices/{$clientInvoice->id}")->assertForbidden();
        $this->postJson("/api/salesrep/invoices/{$clientInvoice->id}/expenses", ['description' => 'Commission adjustment', 'amount' => 25])->assertForbidden();
        $this->deleteJson("/api/salesrep/invoices/{$clientInvoice->id}/expenses/{$clientItem->id}")->assertForbidden();
        $this->postJson("/api/salesrep/invoices/{$clientInvoice->id}/reject")->assertForbidden();
        $this->postJson("/api/salesrep/invoices/{$clientInvoice->id}/submit-for-approval")->assertForbidden();

        $this->assertFalse($clientInvoice->fresh()->canBeModifiedByPayee());
        $this->assertSame(1, $clientInvoice->items()->count());
        $this->assertTrue($payoutInvoice->fresh()->canBeModifiedByPayee());
    }
}
