<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Invoice00038RepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_removes_only_verified_payee_lines_and_restores_paid_invoice(): void
    {
        $photographer = User::factory()->photographer()->create(['id' => 989]);
        $client = User::factory()->create(['id' => 1102]);
        $shoot = Shoot::factory()->create([
            'id' => 82,
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'total_quote' => 105.30,
            'payment_status' => 'paid',
        ]);
        $invoice = Invoice::factory()->create([
            'id' => 112,
            'user_id' => $client->id,
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'shoot_id' => $shoot->id,
            'invoice_number' => 'Invoice 00038',
            'role' => Invoice::ROLE_CLIENT,
            'subtotal' => 1155,
            'tax' => 5.30,
            'total' => 1160.30,
            'total_amount' => 1160.30,
            'amount_paid' => 105.30,
            'charges_total' => 1155,
            'payments_total' => 105.30,
            'balance_due' => 1055,
            'status' => Invoice::STATUS_SENT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_by' => $photographer->id,
            'modified_at' => now(),
            'modification_notes' => 'I added a lot of expenses',
        ]);

        foreach ([
            [228, 82, 'charge', '10 Exterior HDR Photos (3001-5000 SQFT)', 100],
            [231, null, 'charge', 'Misc', 1000],
            [232, null, 'expense', 'Travel', 50],
            [233, null, 'charge', 'People pleasing', 5],
        ] as [$id, $shootId, $type, $description, $amount]) {
            DB::table('invoice_items')->insert([
                'id' => $id,
                'invoice_id' => $invoice->id,
                'shoot_id' => $shootId,
                'type' => $type,
                'description' => $description,
                'quantity' => 1,
                'unit_amount' => $amount,
                'total_amount' => $amount,
                'recorded_at' => now(),
                'meta' => in_array($id, [231, 233], true) ? json_encode(['source' => 'photographer_added']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Payment::unguarded(fn () => Payment::create([
            'id' => 28,
            'invoice_id' => $invoice->id,
            'shoot_id' => $shoot->id,
            'amount' => 105.30,
            'currency' => 'USD',
            'payment_method' => 'other',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]));

        $migration = require database_path('migrations/2026_09_03_000001_repair_invoice_00038_payee_contamination.php');
        $migration->up();
        $migration->up();

        $invoice->refresh();
        $this->assertSame([228], $invoice->items()->pluck('id')->all());
        $this->assertSame(100.0, (float) $invoice->subtotal);
        $this->assertSame(5.3, (float) $invoice->tax);
        $this->assertSame(105.3, (float) $invoice->total);
        $this->assertSame(105.3, (float) $invoice->amount_paid);
        $this->assertSame(0.0, (float) $invoice->balance_due);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertTrue($invoice->is_paid);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(Invoice::APPROVAL_STATUS_PENDING, $invoice->approval_status);
        $this->assertNull($invoice->modified_by);
        $this->assertDatabaseCount('invoice_audit_events', 1);
        $this->assertDatabaseHas('invoice_audit_events', [
            'invoice_id' => 112,
            'event' => 'client_invoice_payee_contamination_repaired',
        ]);
    }
}
