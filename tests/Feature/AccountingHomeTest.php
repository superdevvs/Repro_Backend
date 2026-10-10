<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingHomeTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function payload(User $client)
    {
        return ['operation_key' => 'd064fa22-1e2b-4209-aa00-46369d802089', 'client_id' => $client->id, 'address' => '124 Maple Avenue, Arlington', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-20', 'lines' => [['description' => 'Photography', 'quantity' => 3, 'price' => 100.01]], 'discount' => 10.02, 'tax_rate' => 8.25, 'notes' => 'Test draft'];
    }

    public function test_manual_invoice_cent_arithmetic_idempotency_and_conflict(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $p = $this->payload($client);
        $r = $this->postJson('/api/admin/accounting-home/invoices', $p)->assertCreated()->assertJsonPath('data.status', 'draft');
        $id = $r->json('data.id');
        $this->assertEquals(313.94, (float) $r->json('data.total'));
        $this->assertEquals(23.93, (float) $r->json('data.tax'));
        $this->postJson('/api/admin/accounting-home/invoices', $p)->assertCreated()->assertJsonPath('data.id', $id);
        $this->withExceptionHandling();
        $p['lines'][0]['price'] = 200;
        $this->postJson('/api/admin/accounting-home/invoices', $p)->assertStatus(409);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_manual_invoice_partial_then_full_payment_reconciles_cash_and_balances(): void
    {

        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $id = $this->postJson('/api/admin/accounting-home/invoices', $this->payload($client))->json('data.id');
        $this->postJson('/api/admin/invoices/'.$id.'/mark-paid', ['amount_paid' => 100, 'paid_at' => '2026-10-03', 'payment_method' => 'cash'])->assertOk();
        $this->getJson('/api/admin/accounting-home?start=2026-10-01&end=2026-10-09')->assertOk()->assertJsonPath('data.received', 100)->assertJsonPath('data.open', 213.94)->assertJsonPath('data.paid', 0);
        $this->postJson('/api/admin/invoices/'.$id.'/mark-paid', ['amount_paid' => 213.94, 'paid_at' => '2026-10-05', 'payment_method' => 'cash'])->assertOk();
        $this->getJson('/api/admin/accounting-home?start=2026-10-01&end=2026-10-09')->assertOk()->assertJsonPath('data.received', 313.94)->assertJsonPath('data.open', 0);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_approval_is_an_obligation_without_cash_and_void_drafts_excluded(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        Invoice::factory()->create(['role' => 'photographer', 'period_start' => '2026-10-01', 'period_end' => '2026-10-07', 'issue_date' => '2026-10-08', 'total' => 500, 'total_amount' => 500, 'amount_paid' => 0, 'is_paid' => false, 'approval_status' => 'accounts_approved', 'status' => 'sent', 'paid_at' => null]);
        Invoice::factory()->create(['role' => 'client', 'client_id' => $client->id, 'status' => 'draft', 'total' => 200, 'total_amount' => 200, 'amount_paid' => 0, 'is_paid' => false, 'paid_at' => null]);
        $this->getJson('/api/admin/accounting-home?start=2026-10-01&end=2026-10-09')->assertOk()->assertJsonPath('data.recipients.photographer.unpaid', 500)->assertJsonPath('data.paid', 0)->assertJsonPath('data.open', 0);
    }

    public function test_search_limits_shoots_to_client_and_prevents_duplicate_invoice(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'address' => 'Maple House', 'status' => 'scheduled']);
        Shoot::factory()->create(['client_id' => $other->id, 'address' => 'Secret House']);
        Invoice::factory()->create(['role' => 'client', 'client_id' => $client->id, 'shoot_id' => $shoot->id, 'status' => 'sent']);
        $this->getJson('/api/admin/accounting-home/invoice-options?client_id='.$client->id.'&q=Maple')->assertOk()->assertJsonCount(1, 'data');
        $p = $this->payload($client);
        $p['shoot_id'] = $shoot->id;
        $this->postJson('/api/admin/accounting-home/invoices', $p)->assertStatus(409);
    }

    public function test_payment_retry_does_not_record_twice(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $id = $this->postJson('/api/admin/accounting-home/invoices', $this->payload($client))->json('data.id');
        $payload = ['operation_key' => '99c737a5-4bda-4405-bfa3-d1d881633cdb', 'amount_paid' => 100, 'paid_at' => '2026-10-03', 'payment_method' => 'cash'];
        $this->postJson('/api/admin/accounting-home/invoices/'.$id.'/payment', $payload)->assertOk();
        $this->postJson('/api/admin/accounting-home/invoices/'.$id.'/payment', $payload)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertEquals(100, Invoice::find($id)->totalPaid());
    }

    public function test_send_acceptance_and_retries_are_truthful(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $id = $this->postJson('/api/admin/accounting-home/invoices', $this->payload($client))->json('data.id');
        $this->mock(\App\Services\Messaging\MessagingService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->once()->andReturn(new \App\Models\Message(['id' => 11, 'status' => 'SENT']));
        });
        $payload = ['operation_key' => '99c737a5-4bda-4405-bfa3-d1d881633cdb', 'subject' => 'Invoice', 'message' => 'Test only'];
        $this->postJson('/api/admin/accounting-home/invoices/'.$id.'/send', $payload)->assertOk()->assertJsonPath('accepted', true);
        $this->postJson('/api/admin/accounting-home/invoices/'.$id.'/send', $payload)->assertOk();
        $this->assertEquals('sent', Invoice::find($id)->status);
    }

    public function test_failed_send_keeps_invoice_draft(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $id = $this->postJson('/api/admin/accounting-home/invoices', $this->payload($client))->json('data.id');
        $this->mock(\App\Services\Messaging\MessagingService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->once()->andReturn(new \App\Models\Message(['status' => 'FAILED']));
        });
        $this->postJson('/api/admin/accounting-home/invoices/'.$id.'/send', ['operation_key' => '99c737a5-4bda-4405-bfa3-d1d881633cdb', 'subject' => 'Invoice', 'message' => 'Test only'])->assertStatus(409)->assertJsonPath('accepted', false);
        $this->assertEquals('draft', Invoice::find($id)->status);
    }

    public function test_refunds_and_provider_duplicates_do_not_inflate_cash(): void
    {
        $this->actingAs($this->admin());
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $invoice = Invoice::factory()->create(['role' => 'client', 'issue_date' => '2026-10-01', 'client_id' => $client->id, 'shoot_id' => $shoot->id, 'status' => 'sent', 'total' => 1000, 'total_amount' => 1000, 'amount_paid' => 0, 'is_paid' => false, 'paid_at' => null]);
        $p = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD', 'status' => 'completed', 'processed_at' => '2026-10-02', 'stripe_payment_id' => 'pi_qa_unique']);
        Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD', 'status' => 'completed', 'processed_at' => '2026-10-02', 'stripe_payment_id' => 'pi_qa_unique']);
        $p->refunds()->make(['shoot_id' => $shoot->id, 'amount' => 100, 'provider' => 'stripe', 'provider_refund_id' => 're_qa', 'operation_key' => 'qa-refund', 'status' => 'succeeded'])
            ->forceFill(['created_at' => '2026-10-03'])->save();
        $this->getJson('/api/admin/accounting-home?start=2026-10-01&end=2026-10-09')->assertOk()->assertJsonPath('data.received', 900)->assertJsonPath('data.open', 100);
    }

    public function test_non_admin_cannot_read_home_or_create_invoice(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->actingAs($client);
        $this->getJson('/api/admin/accounting-home?start=2026-10-01&end=2026-10-09')->assertForbidden();
        $this->postJson('/api/admin/accounting-home/invoices', $this->payload($client))->assertForbidden();
    }
}
