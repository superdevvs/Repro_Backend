<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceStatusFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_filters_keep_issued_balances_and_exclude_terminal_or_no_payment_documents(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $overdue = $this->invoice();
        $partial = $this->invoice(['status' => 'partial', 'amount_paid' => 40]);
        $alreadyOverdue = $this->invoice(['status' => 'overdue']);

        foreach (['draft', 'void', 'cancelled', 'canceled', 'refunded'] as $status) {
            $this->invoice(['status' => $status]);
        }
        $this->invoice(['payment_required' => false]);
        $this->invoice(['document_type' => Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT]);
        $this->invoice(['status' => 'no_payment_required']);
        $this->invoice(['status' => 'paid', 'amount_paid' => 100, 'is_paid' => true]);
        $this->invoice(['due_date' => '2099-01-01']);

        foreach (['/api/admin/invoices', '/api/invoices/summary'] as $endpoint) {
            $response = $this->getJson($endpoint.'?role=client&status=overdue&start=2020-01-01&end=2020-01-31&per_page=100')
                ->assertOk();
            $this->assertEqualsCanonicalizing([$overdue->id, $partial->id, $alreadyOverdue->id], collect($response->json('data'))->pluck('id')->all());
        }

        $this->getJson('/api/admin/invoices?role=client&status=all&start=2020-01-01&end=2020-01-31&per_page=100')
            ->assertOk()->assertJsonCount(13, 'data');
        $this->assertDatabaseHas('invoices', ['id' => $partial->id, 'status' => 'partial', 'amount_paid' => 40]);
    }

    public function test_paid_filter_does_not_relabel_terminal_or_complimentary_records_as_paid(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $paid = $this->invoice(['status' => 'paid', 'amount_paid' => 100, 'is_paid' => true]);
        $settledByPayments = $this->invoice(['amount_paid' => 100]);
        $zeroValue = $this->invoice(['total' => 0, 'total_amount' => 0, 'subtotal' => 0]);
        foreach (['draft', 'void', 'cancelled', 'canceled', 'refunded'] as $status) {
            $this->invoice(['status' => $status, 'total' => 0, 'total_amount' => 0, 'subtotal' => 0, 'is_paid' => true]);
        }
        $this->invoice(['payment_required' => false, 'total' => 0, 'total_amount' => 0, 'subtotal' => 0, 'is_paid' => true]);
        $this->invoice(['document_type' => Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT, 'is_paid' => true]);

        $response = $this->getJson('/api/admin/invoices?role=client&status=paid&per_page=100')->assertOk();
        $this->assertEqualsCanonicalizing([$paid->id, $settledByPayments->id, $zeroValue->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_pending_filter_keeps_unsettled_issued_invoices_and_excludes_drafts_and_receipts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $sent = $this->invoice(['due_date' => '2099-01-01']);
        $partial = $this->invoice(['status' => 'partial', 'amount_paid' => 40, 'due_date' => '2099-01-01']);
        $pending = $this->invoice(['status' => 'pending', 'due_date' => null]);
        $this->invoice(['status' => 'draft', 'due_date' => '2099-01-01']);
        $this->invoice(['status' => 'void', 'due_date' => '2099-01-01']);
        $this->invoice(['payment_required' => false, 'due_date' => '2099-01-01']);
        $this->invoice(['status' => 'paid', 'amount_paid' => 100, 'is_paid' => true]);

        $response = $this->getJson('/api/admin/invoices?role=client&status=pending&per_page=100')->assertOk();
        $this->assertEqualsCanonicalizing([$sent->id, $partial->id, $pending->id], collect($response->json('data'))->pluck('id')->all());
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'role' => Invoice::ROLE_CLIENT, 'shoot_id' => null, 'status' => 'sent',
            'issue_date' => '2020-01-01', 'due_date' => '2020-01-02', 'period_start' => '2020-01-01', 'period_end' => '2020-01-02',
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'total_amount' => 100, 'amount_paid' => 0, 'is_paid' => false,
        ], $attributes));
    }
}
