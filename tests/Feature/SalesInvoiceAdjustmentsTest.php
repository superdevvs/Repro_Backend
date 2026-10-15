<?php

namespace Tests\Feature;

use App\Models\{Invoice, InvoiceItem, Payment, Shoot, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesInvoiceAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
        // Production's configured sales role grants invoice updates. The catalog
        // default is intentionally narrower, so model that existing grant here.
        $permissions = app(\App\Services\RolePermissionService::class);
        $map = $permissions->defaultPermissionsByRole();
        $map['salesRep'][] = 'invoices-update';
        $permissions->updatePermissions($map);
    }

    private function invoice(User $rep, bool $fallback = false): array
    {
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $fallback ? null : $rep->id,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'base_quote' => 100, 'tax_amount' => 6, 'total_quote' => 106, 'payment_status' => 'unpaid']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'user_id' => $client->id, 'role' => 'client', 'shoot_id' => $shoot->id,
            'subtotal' => 100, 'tax' => 6, 'total' => 106, 'total_amount' => 106, 'amount_paid' => 0, 'payments_total' => 0, 'balance_due' => 106, 'is_paid' => false]);
        return [$shoot, $invoice];
    }

    private function payload(): array
    {
        return ['description' => 'Client adjustment', 'amount' => 20, 'quantity' => 1, 'bills_client' => true];
    }

    public function test_assigned_rep_can_add_update_and_remove_adjustments_on_paid_delivered_invoice_without_rewriting_payments_or_tax(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        [$shoot, $invoice] = $this->invoice($rep);
        $payment = Payment::create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 106, 'currency' => 'USD', 'status' => Payment::STATUS_COMPLETED, 'processed_at' => now()]);
        $before = $payment->fresh()->getRawOriginal();
        $url = '/api/admin/invoices/'.$invoice->id.'/misc-items';
        $id = $this->postJson($url, $this->payload() + ['dedupe_key' => 'rep-correction'])->assertCreated()->json('item.id');
        $this->postJson($url, $this->payload() + ['dedupe_key' => 'rep-correction'])->assertOk();
        $this->assertSame(126.0, (float) $invoice->fresh()->total);
        $this->assertSame(6.0, (float) $invoice->fresh()->tax);
        $this->assertSame(106.0, (float) $invoice->fresh()->amount_paid);
        $this->assertFalse((bool) $invoice->fresh()->is_paid);
        $this->assertSame(126.0, (float) $shoot->fresh()->total_quote);
        $this->patchJson($url.'/'.$id, array_replace($this->payload(), ['amount' => -10]))->assertOk();
        $this->assertSame(96.0, (float) $invoice->fresh()->total);
        $this->assertSame(96.0, (float) $shoot->fresh()->total_quote);
        $this->assertTrue((bool) $invoice->fresh()->is_paid);
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->assertSame(106.0, (float) $invoice->fresh()->total);
        $this->assertSame(106.0, (float) $shoot->fresh()->total_quote);
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_unassigned_shoot_uses_canonical_client_rep_and_secondary_rep_role(): void
    {
        $rep = User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['sales_rep']]); Sanctum::actingAs($rep);
        [, $invoice] = $this->invoice($rep, true);
        $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', $this->payload())->assertCreated();
    }

    public function test_wrong_rep_mixed_invoice_and_payouts_are_forbidden_without_mutation(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        [$shoot, $invoice] = $this->invoice($rep);
        [$otherShoot, $otherInvoice] = $this->invoice($other);
        Sanctum::actingAs($rep);
        $this->postJson('/api/admin/invoices/'.$otherInvoice->id.'/misc-items', $this->payload())->assertForbidden();
        $invoice->shoots()->attach($otherShoot->id);
        $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', $this->payload())->assertForbidden();
        $invoice->shoots()->detach();
        foreach (['photographer', 'salesRep'] as $role) {
            $invoice->update(['role' => $role]);
            $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', array_replace($this->payload(), ['bills_client' => false]))->assertForbidden();
        }
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(106.0, (float) $shoot->fresh()->total_quote);
    }

    public function test_explicit_assignment_wins_over_client_rep_fallback(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        [$shoot, $invoice] = $this->invoice($rep);
        $shoot->update(['rep_id' => User::factory()->create(['role' => 'client'])->id]);
        $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', $this->payload())->assertForbidden();
    }

    public function test_reassignment_revokes_existing_adjustment_edits_and_deletion(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        [$shoot, $invoice] = $this->invoice($rep);
        $url = '/api/admin/invoices/'.$invoice->id.'/misc-items';
        $id = $this->postJson($url, $this->payload())->assertCreated()->json('item.id');
        $shoot->update(['rep_id' => User::factory()->create(['role' => 'salesRep'])->id]);
        $this->patchJson($url.'/'.$id, $this->payload())->assertForbidden();
        $this->deleteJson($url.'/'.$id)->assertForbidden();
        $this->assertNotNull(InvoiceItem::find($id));
    }

    public function test_import_drafts_historical_balances_and_cancelled_shoots_remain_locked(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        foreach (['import_draft', 'cancelled', 'declined', 'historical'] as $state) {
            [$shoot, $invoice] = $this->invoice($rep);
            $shoot->update($state === 'historical'
                ? ['external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true]]]
                : ['status' => $state, 'workflow_status' => $state]);
            $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', $this->payload())->assertForbidden();
        }
        foreach ([['cancelled', 'delivered'], ['delivered', 'declined'], ['delivered', 'import_draft']] as [$status, $workflow]) {
            [$shoot, $invoice] = $this->invoice($rep);
            $shoot->update(['status' => $status, 'workflow_status' => $workflow]);
            $this->postJson('/api/admin/invoices/'.$invoice->id.'/misc-items', $this->payload())->assertForbidden();
        }
        $this->assertSame(0, InvoiceItem::count());
    }

    public function test_invoice_and_pricing_denials_apply_to_all_adjustment_mutations(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']); Sanctum::actingAs($rep);
        [, $invoice] = $this->invoice($rep);
        $url = '/api/admin/invoices/'.$invoice->id.'/misc-items';
        $id = $this->postJson($url, $this->payload())->assertCreated()->json('item.id');
        foreach (['invoices-update', 'shoot-pricing-update', 'accounting-view'] as $permission) {
            $rep->update(['permission_overrides' => ['allow' => [], 'deny' => [$permission]]]);
            $this->postJson($url, $this->payload())->assertForbidden();
            $this->patchJson($url.'/'.$id, $this->payload())->assertForbidden();
            $this->deleteJson($url.'/'.$id)->assertForbidden();
        }
        $this->assertSame(20.0, (float) InvoiceItem::findOrFail($id)->total_amount);
    }
}
