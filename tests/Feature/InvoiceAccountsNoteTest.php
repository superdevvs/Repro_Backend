<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceAccountsNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceAccountsNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_note_saves_reloads_updates_and_clears_without_changing_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = Invoice::factory()->create();
        $before = $invoice->fresh()->getRawOriginal();
        Sanctum::actingAs($admin);
        $url = "/api/admin/invoices/{$invoice->id}/accounts-note";
        $this->getJson($url)->assertOk()->assertJsonPath('data.note', '');
        $this->putJson($url, ['note' => 'Private settlement reference.'])->assertOk()
            ->assertJsonPath('data.author.id', $admin->id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.note', 'Private settlement reference.');
        $createdAt = InvoiceAccountsNote::firstOrFail()->created_at->toISOString();
        $this->travel(2)->minutes();
        $nextAdmin = User::factory()->admin()->create();
        Sanctum::actingAs($nextAdmin);
        $this->putJson($url, ['note' => 'Updated privately.'])->assertOk()
            ->assertJsonPath('data.author.id', $nextAdmin->id);
        $this->assertDatabaseCount('invoice_accounts_notes', 1);
        $this->assertSame($createdAt, InvoiceAccountsNote::firstOrFail()->created_at->toISOString());
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->putJson($url, ['note' => ''])->assertOk()->assertJsonPath('data.note', '');
        $this->travelBack();
    }

    public function test_payee_roles_cannot_read_or_write_private_accounts_notes(): void
    {
        $invoice = Invoice::factory()->create();
        foreach (['photographer', 'salesRep', 'client', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $url = "/api/admin/invoices/{$invoice->id}/accounts-note";
            $this->getJson($url)->assertForbidden();
            $this->putJson($url, ['note' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertDatabaseCount('invoice_accounts_notes', 0);
    }

    public function test_accounting_permission_denial_blocks_both_operations(): void
    {
        $invoice = Invoice::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create([
            'permission_overrides' => ['allow' => [], 'deny' => ['accounting-view']],
        ]));
        $url = "/api/admin/invoices/{$invoice->id}/accounts-note";
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, ['note' => 'Unauthorized'])->assertForbidden();
    }

    public function test_note_validation_and_authentication_are_enforced(): void
    {
        $invoice = Invoice::factory()->create();
        $url = "/api/admin/invoices/{$invoice->id}/accounts-note";
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson($url, ['note' => str_repeat('x', 5001)])->assertUnprocessable();
        $this->putJson($url, [])->assertUnprocessable();
        $this->putJson($url, ['note' => ['unexpected']])->assertUnprocessable();
        $this->assertDatabaseCount('invoice_accounts_notes', 0);
    }

    public function test_note_is_absent_from_payee_detail_and_general_invoice_list(): void
    {
        foreach (['photographer', 'salesRep'] as $role) {
            $payee = User::factory()->create(['role' => $role]);
            $invoice = Invoice::factory()->create([
                'user_id' => $payee->id,
                'role' => $role,
                'photographer_id' => $role === 'photographer' ? $payee->id : null,
                'sales_rep_id' => $role === 'salesRep' ? $payee->id : null,
                'billing_period_start' => '2026-09-20',
                'billing_period_end' => '2026-09-26',
                'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            ]);
            Sanctum::actingAs(User::factory()->admin()->create());
            $secret = "PRIVATE_ACCOUNTS_ONLY_{$invoice->id}";
            $this->putJson("/api/admin/invoices/{$invoice->id}/accounts-note", ['note' => $secret])->assertOk();
            Sanctum::actingAs($payee);
            $prefix = $role === 'photographer' ? 'photographer' : 'salesrep';
            foreach (["/api/{$prefix}/invoices/{$invoice->id}", '/api/invoices'] as $url) {
                $response = $this->getJson($url)->assertOk();
                $this->assertStringNotContainsString($secret, $response->getContent());
                $this->assertStringNotContainsString('accounts_note', $response->getContent());
            }
            $this->assertStringNotContainsString($secret, $invoice->fresh()->toJson());
        }
    }
}
