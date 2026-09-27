<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesClientInvoiceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sales_client_ledger_excludes_linked_payouts_without_hiding_own_weekly_commissions(): void
    {
        [$rep, $client, $shoot] = $this->createSalesAccount();
        $receivable = $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_CLIENT, 106);
        $paidClientInvoice = $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_CLIENT, 291.50, true);
        $photographerPayout = $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_PHOTOGRAPHER, 123.75);
        $commission = $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_SALES_REP, 41.25);
        $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_PHOTOGRAPHER, 100, true);
        $paidCommission = $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_SALES_REP, 25, true);
        $this->createInvoice($rep, $client, $shoot, 'sales_rep', 60, true);
        $this->createInvoice($rep, $client, $shoot, 'salesrep', 15, true);

        [$otherRep, $otherClient, $otherShoot] = $this->createSalesAccount();
        $this->createInvoice($otherRep, $otherClient, $otherShoot, Invoice::ROLE_CLIENT, 999, true);
        $this->createInvoice($otherRep, $otherClient, $otherShoot, Invoice::ROLE_SALES_REP, 99);

        Sanctum::actingAs($rep);

        $index = $this->getJson('/api/invoices?per_page=100')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing(
            [$receivable->id, $paidClientInvoice->id],
            array_column($index->json('data'), 'id'),
        );

        $this->assertSalesSummary($client, 291.50, 106);

        $weekly = $this->getJson('/api/salesrep/invoices')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing(
            [$commission->id, $paidCommission->id],
            array_column($weekly->json('data'), 'id'),
        );
        $this->getJson('/api/salesrep/invoices/'.$commission->id)
            ->assertOk()->assertJsonPath('id', $commission->id);

        // Admin clearance must retain both payout types after the Sales-only restriction.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $adminIds = array_column($this->getJson('/api/invoices?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($photographerPayout->id, $adminIds);
        $this->assertContains($commission->id, $adminIds);
    }

    public function test_legacy_null_role_client_invoices_remain_in_sales_list_and_summary(): void
    {
        // The legacy migration makes role nullable outside SQLite. Reproduce that
        // supported schema here because its SQLite branch skips ALTER COLUMN.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('role')->nullable()->change();
        });

        [$rep, $client, $shoot] = $this->createSalesAccount();
        $receivable = $this->createInvoice($rep, $client, $shoot, null, 20);
        $paid = $this->createInvoice($rep, $client, $shoot, null, 80, true);
        $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_PHOTOGRAPHER, 123.75);
        $this->createInvoice($rep, $client, $shoot, Invoice::ROLE_SALES_REP, 41.25, true);

        [$otherRep, $otherClient, $otherShoot] = $this->createSalesAccount();
        $this->createInvoice($otherRep, $otherClient, $otherShoot, null, 999, true);

        Sanctum::actingAs($rep);

        $index = $this->getJson('/api/invoices')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$receivable->id, $paid->id], array_column($index->json('data'), 'id'));
        $this->assertSalesSummary($client, 80, 20);
    }

    private function createSalesAccount(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00'));
        $rep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => ['repDetails' => ['commissionPercentage' => 10]],
        ]);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $rep->id,
            'created_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'scheduled_date' => '2026-09-16',
            'sales_rep_pay_enabled' => true,
        ]);

        return [$rep, $client, $shoot];
    }

    private function createInvoice(User $rep, User $client, Shoot $shoot, ?string $role, float $total, bool $paid = false): Invoice
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $role === Invoice::ROLE_CLIENT || $role === null ? $client->id : $rep->id,
            'role' => $role,
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'sales_rep_id' => $rep->id,
            'photographer_id' => $shoot->photographer_id,
            'issue_date' => '2026-09-16',
            'due_date' => '2026-09-30',
            'billing_period_start' => '2026-09-14',
            'billing_period_end' => '2026-09-20',
            'total' => $total,
            'total_amount' => $total,
            'amount_paid' => $paid ? $total : 0,
            'is_paid' => $paid,
            'status' => $paid ? Invoice::STATUS_PAID : Invoice::STATUS_SENT,
            'paid_at' => $paid ? Carbon::parse('2026-09-20 12:00:00') : null,
        ]);
        $invoice->shoots()->attach($shoot->id);

        return $invoice;
    }

    private function assertSalesSummary(User $client, float $revenue, float $balance): void
    {
        $response = $this->getJson('/api/reports/sales/summary?start_date=2026-09-01&end_date=2026-09-27')->assertOk();
        $this->assertEquals($revenue, $response->json('summary.paid_revenue'));
        $this->assertEquals(round($revenue * 0.1, 2), $response->json('summary.commission_earned'));
        $this->assertEquals($revenue, $response->json('summary.average_client_value'));
        $this->assertEquals(1, $response->json('summary.new_clients'));
        $this->assertEquals($revenue, array_sum(array_column($response->json('trend'), 'paid_revenue')));
        foreach (['top_clients', 'new_clients'] as $key) {
            $this->assertCount(1, $response->json($key));
            $this->assertSame($client->id, $response->json($key.'.0.client_id'));
            $this->assertEquals($revenue, $response->json($key.'.0.paid_revenue'));
            $this->assertEquals($balance, $response->json($key.'.0.outstanding_balance'));
        }
    }
}
