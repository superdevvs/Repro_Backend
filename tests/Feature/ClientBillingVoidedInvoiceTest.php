<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ClientBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rebooked shoot leaves the abandoned booking's invoice behind. Once accounts
 * voids it, the client billing page must stop asking the client to pay it, and
 * must not fall back to a "Shoot balance" row for the abandoned shoot either.
 */
class ClientBillingVoidedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_voided_and_cancelled_invoices_are_not_payable_on_client_billing(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $voided = $this->overdueInvoice($client, 'void', 371.00);
        $cancelled = $this->overdueInvoice($client, 'cancelled', 446.47);
        $open = $this->overdueInvoice($client, 'sent', 120.00);

        $billing = app(ClientBillingService::class)->getClientBilling($client);
        $items = collect($billing['items']);

        $this->assertSame(1, $billing['summary']['dueNow']['count']);
        $this->assertSame(120.0, $billing['summary']['dueNow']['amount']);
        $this->assertSame([$open->id], $items->where('bucket', 'due_now')->pluck('invoiceId')->all());

        foreach ([$voided, $cancelled] as $invoice) {
            $this->assertFalse($items->contains('invoiceId', $invoice->id), "invoice {$invoice->status} should not be listed");
            $this->assertFalse($items->contains('shootId', $invoice->shoot_id), "shoot of {$invoice->status} invoice should not fall back to a shoot balance");
        }
    }

    private function overdueInvoice(User $client, string $status, float $total): Invoice
    {
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_ON_HOLD,
            'workflow_status' => Shoot::STATUS_ON_HOLD,
            'base_quote' => $total,
            'tax_amount' => 0,
            'total_quote' => $total,
            'payment_status' => 'unpaid',
        ]);

        return Invoice::factory()->create([
            'user_id' => $client->id,
            'client_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'shoot_id' => $shoot->id,
            'status' => $status,
            'payment_required' => true,
            'issue_date' => now()->subDays(40),
            'due_date' => now()->subDays(30),
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'total_amount' => $total,
            'amount_paid' => 0,
            'is_paid' => false,
        ]);
    }
}
