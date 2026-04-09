<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceApprovalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_review_queue_filters_photographer_invoices_by_status_search_and_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

        $admin = User::factory()->admin()->create();
        $alice = User::factory()->photographer()->create([
            'name' => 'Alice Lens',
            'email' => 'alice@example.com',
        ]);
        $bob = User::factory()->photographer()->create([
            'name' => 'Bob Frame',
            'email' => 'bob@example.com',
        ]);
        $salesRep = User::factory()->create(['role' => 'salesrep']);

        $pendingInvoice = $this->createWeeklyInvoice($alice, [
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_at' => '2026-03-09 08:30:00',
            'modification_notes' => 'Ready for payout review.',
        ]);

        $this->createWeeklyInvoice($alice, [
            'billing_period_start' => '2026-03-09',
            'billing_period_end' => '2026-03-15',
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'approved_at' => '2026-03-16 09:00:00',
        ]);

        $this->createWeeklyInvoice($bob, [
            'billing_period_start' => '2026-03-16',
            'billing_period_end' => '2026-03-22',
            'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
            'rejected_at' => '2026-03-23 09:15:00',
            'rejection_reason' => 'Missing mileage receipt.',
        ]);

        Invoice::factory()->create([
            'user_id' => $salesRep->id,
            'sales_rep_id' => $salesRep->id,
            'photographer_id' => null,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'status' => Invoice::STATUS_DRAFT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'issue_date' => '2026-03-09',
            'due_date' => '2026-03-09',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/invoices/review-queue?role=photographer&approval_status=pending_approval&search=alice&start=2026-03-01&end=2026-03-31');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pendingInvoice->id);
        $response->assertJsonPath('data.0.photographer.name', 'Alice Lens');
        $response->assertJsonPath('data.0.approval_status', Invoice::APPROVAL_STATUS_PENDING_APPROVAL);
        $response->assertJsonPath('data.0.expense_count', 1);
        $response->assertJsonPath('summary.invoice_count', 1);
        $response->assertJsonPath('summary.needs_review_count', 1);
        $response->assertJsonPath('summary.approved_count', 0);
        $response->assertJsonPath('summary.returned_count', 0);
    }

    public function test_admin_review_queue_filters_sales_rep_invoices_by_status_search_and_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 10:00:00'));

        $admin = User::factory()->admin()->create();
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'name' => 'Rita Closer',
            'email' => 'rita@example.com',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 12,
                ],
            ],
        ]);
        $photographer = User::factory()->photographer()->create();

        $pendingInvoice = $this->createSalesRepInvoice($salesRep, [
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_at' => '2026-03-09 08:30:00',
            'modification_notes' => 'Commission adjustments added.',
        ]);

        $this->createSalesRepInvoice($salesRep, [
            'billing_period_start' => '2026-03-09',
            'billing_period_end' => '2026-03-15',
            'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
            'approved_at' => '2026-03-16 09:00:00',
        ]);

        $this->createWeeklyInvoice($photographer, [
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/invoices/review-queue?role=salesRep&approval_status=pending_approval&search=rita&start=2026-03-01&end=2026-03-31');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pendingInvoice->id);
        $response->assertJsonPath('data.0.salesRep.name', 'Rita Closer');
        $response->assertJsonPath('data.0.role', 'salesRep');
        $response->assertJsonPath('summary.invoice_count', 1);
        $response->assertJsonPath('summary.needs_review_count', 1);
    }

    public function test_admin_can_view_photographer_review_detail_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create([
            'name' => 'Nora Exposure',
            'email' => 'nora@example.com',
        ]);

        $invoice = $this->createWeeklyInvoice($photographer, [
            'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
            'billing_period_start' => '2026-03-23',
            'billing_period_end' => '2026-03-29',
            'modified_at' => '2026-03-30 10:00:00',
            'rejected_at' => '2026-03-30 12:00:00',
            'modified_by' => $photographer->id,
            'rejected_by' => $admin->id,
            'rejection_reason' => 'Please clarify the extra trip charge.',
            'modification_notes' => 'Added travel reimbursement note.',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/invoices/' . $invoice->id . '/review-detail');

        $response->assertOk();
        $response->assertJsonPath('data.id', $invoice->id);
        $response->assertJsonPath('data.photographer.name', 'Nora Exposure');
        $response->assertJsonPath('data.modification_notes', 'Added travel reimbursement note.');
        $response->assertJsonPath('data.rejection_reason', 'Please clarify the extra trip charge.');
        $response->assertJsonPath('data.modifiedBy.name', 'Nora Exposure');
        $response->assertJsonPath('data.rejectedBy.role', 'admin');
        $response->assertJsonPath('data.shoot_count', 1);
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonCount(1, 'data.shoots');
        $response->assertJsonFragment([
            'label' => 'Returned for changes',
            'reason' => 'Please clarify the extra trip charge.',
        ]);
    }

    public function test_admin_can_view_sales_rep_review_detail_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'name' => 'Cole Booker',
            'email' => 'cole@example.com',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 10,
                ],
            ],
        ]);

        $invoice = $this->createSalesRepInvoice($salesRep, [
            'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
            'modified_at' => '2026-03-30 10:00:00',
            'rejected_at' => '2026-03-30 12:00:00',
            'modified_by' => $salesRep->id,
            'rejected_by' => $admin->id,
            'rejection_reason' => 'Please review the client change order note.',
            'modification_notes' => 'Added comment about commission split.',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/invoices/' . $invoice->id . '/review-detail');

        $response->assertOk();
        $response->assertJsonPath('data.id', $invoice->id);
        $response->assertJsonPath('data.role', 'salesRep');
        $response->assertJsonPath('data.salesRep.name', 'Cole Booker');
        $response->assertJsonPath('data.payee.name', 'Cole Booker');
        $response->assertJsonPath('data.modification_notes', 'Added comment about commission split.');
        $response->assertJsonPath('data.rejection_reason', 'Please review the client change order note.');
    }

    public function test_approve_review_marks_linked_photographer_shoots_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:30:00'));

        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();

        $invoice = $this->createWeeklyInvoice($photographer, [
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_at' => '2026-04-08 18:00:00',
        ]);

        $shoot = $invoice->shoots()->firstOrFail();
        $this->assertNull($shoot->photographer_paid_at);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/invoices/' . $invoice->id . '/approve');

        $response->assertOk();
        $response->assertJsonPath('invoice.approval_status', Invoice::APPROVAL_STATUS_APPROVED);

        $invoice->refresh();
        $shoot->refresh();

        $this->assertSame(Invoice::APPROVAL_STATUS_APPROVED, $invoice->approval_status);
        $this->assertSame($admin->id, $invoice->approved_by);
        $this->assertNotNull($invoice->approved_at);
        $this->assertNotNull($shoot->photographer_paid_at);
        $this->assertSame($invoice->id, $shoot->photographer_paid_invoice_id);
    }

    public function test_reject_review_requires_a_reason_and_marks_invoice_returned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 14:00:00'));

        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create();

        $invoice = $this->createWeeklyInvoice($photographer, [
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_at' => '2026-04-09 09:15:00',
        ]);

        Sanctum::actingAs($admin);

        $missingReasonResponse = $this->postJson('/api/admin/invoices/' . $invoice->id . '/reject', []);
        $missingReasonResponse->assertUnprocessable();

        $invoice->refresh();
        $this->assertSame(Invoice::APPROVAL_STATUS_PENDING_APPROVAL, $invoice->approval_status);
        $this->assertNull($invoice->rejected_by);
        $this->assertNull($invoice->rejected_at);

        $response = $this->postJson('/api/admin/invoices/' . $invoice->id . '/reject', [
            'reason' => 'Please attach the missing parking receipt.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.approval_status', Invoice::APPROVAL_STATUS_REJECTED);
        $response->assertJsonPath('invoice.rejection_reason', 'Please attach the missing parking receipt.');

        $invoice->refresh();

        $this->assertSame(Invoice::APPROVAL_STATUS_REJECTED, $invoice->approval_status);
        $this->assertSame($admin->id, $invoice->rejected_by);
        $this->assertSame('Please attach the missing parking receipt.', $invoice->rejection_reason);
        $this->assertNotNull($invoice->rejected_at);
    }

    public function test_non_admin_users_cannot_access_review_queue_or_detail(): void
    {
        $photographer = User::factory()->photographer()->create();
        $invoice = $this->createWeeklyInvoice($photographer, [
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
        ]);

        Sanctum::actingAs($photographer);

        $queueResponse = $this->getJson('/api/admin/invoices/review-queue');
        $queueResponse->assertForbidden();

        $detailResponse = $this->getJson('/api/admin/invoices/' . $invoice->id . '/review-detail');
        $detailResponse->assertForbidden();
    }

    public function test_review_detail_returns_not_found_for_non_payout_invoices(): void
    {
        $admin = User::factory()->admin()->create();

        $invoice = Invoice::factory()->create([
            'photographer_id' => null,
            'sales_rep_id' => null,
            'role' => Invoice::ROLE_CLIENT,
            'status' => Invoice::STATUS_DRAFT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'issue_date' => '2026-03-09',
            'due_date' => '2026-03-09',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/invoices/' . $invoice->id . '/review-detail');

        $response->assertNotFound();
    }

    public function test_approve_review_marks_linked_sales_rep_shoots_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09 16:00:00'));

        $admin = User::factory()->admin()->create();
        $salesRep = User::factory()->create(['role' => 'salesRep']);

        $invoice = $this->createSalesRepInvoice($salesRep, [
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'modified_at' => '2026-04-08 18:00:00',
        ]);

        $shoot = $invoice->shoots()->firstOrFail();
        $this->assertNull($shoot->sales_rep_paid_at);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/invoices/' . $invoice->id . '/approve');

        $response->assertOk();
        $response->assertJsonPath('invoice.approval_status', Invoice::APPROVAL_STATUS_APPROVED);

        $shoot->refresh();
        $this->assertNotNull($shoot->sales_rep_paid_at);
        $this->assertSame($invoice->id, $shoot->sales_rep_paid_invoice_id);
    }

    private function createWeeklyInvoice(User $photographer, array $overrides = []): Invoice
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'scheduled_date' => $overrides['billing_period_start'] ?? '2026-03-02',
            'completed_at' => ($overrides['billing_period_end'] ?? '2026-03-08') . ' 14:00:00',
            'total_quote' => 240,
            'base_quote' => 220,
            'tax_amount' => 20,
        ]);

        $invoice = Invoice::factory()->create(array_merge([
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'status' => Invoice::STATUS_DRAFT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'issue_date' => '2026-03-09',
            'due_date' => '2026-03-09',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'total_amount' => 0,
            'amount_paid' => 0,
        ], $overrides));

        $invoice->shoots()->attach($shoot->id);

        $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Property shoot payout',
            'quantity' => 1,
            'unit_amount' => 160,
            'total_amount' => 160,
            'recorded_at' => now()->subDay(),
        ]);

        $invoice->items()->create([
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Mileage reimbursement',
            'quantity' => 1,
            'unit_amount' => 15,
            'total_amount' => 15,
            'recorded_at' => now()->subDay(),
        ]);

        $invoice->refreshTotals();

        return $invoice->fresh([
            'photographer',
            'items',
            'shoots',
            'modifiedBy',
            'approvedBy',
            'rejectedBy',
        ]);
    }

    private function createSalesRepInvoice(User $salesRep, array $overrides = []): Invoice
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => $overrides['billing_period_start'] ?? '2026-03-02',
            'completed_at' => ($overrides['billing_period_end'] ?? '2026-03-08') . ' 14:00:00',
            'total_quote' => 600,
            'base_quote' => 560,
            'tax_amount' => 40,
            'created_by' => $salesRep->id,
        ]);

        $invoice = Invoice::factory()->create(array_merge([
            'user_id' => $salesRep->id,
            'role' => Invoice::ROLE_SALES_REP,
            'sales_rep_id' => $salesRep->id,
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'status' => Invoice::STATUS_DRAFT,
            'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            'billing_period_start' => '2026-03-02',
            'billing_period_end' => '2026-03-08',
            'issue_date' => '2026-03-09',
            'due_date' => '2026-03-09',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'total_amount' => 0,
            'amount_paid' => 0,
        ], $overrides));

        $invoice->shoots()->attach($shoot->id);

        $invoice->items()->create([
            'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE,
            'description' => 'Commission payout',
            'quantity' => 1,
            'unit_amount' => 72,
            'total_amount' => 72,
            'recorded_at' => now()->subDay(),
        ]);

        $invoice->items()->create([
            'type' => InvoiceItem::TYPE_EXPENSE,
            'description' => 'Travel follow-up reimbursement',
            'quantity' => 1,
            'unit_amount' => 10,
            'total_amount' => 10,
            'recorded_at' => now()->subDay(),
        ]);

        $invoice->refreshTotals();

        return $invoice->fresh([
            'salesRep',
            'items',
            'shoots',
            'modifiedBy',
            'approvedBy',
            'rejectedBy',
        ]);
    }
}
