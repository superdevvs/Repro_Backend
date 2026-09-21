<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PayoutReportService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesRepClientRepResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_client_rep_uses_created_by_sales_rep_when_no_prior_shoot_rep(): void
    {
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
        ]);

        $this->assertSame(
            $salesRep->id,
            app(ShootMutationSupportService::class)->getClientRep($client->id)
        );
    }

    public function test_get_client_rep_uses_account_rep_metadata_before_created_by(): void
    {
        $createdBy = User::factory()->create(['role' => 'salesRep']);
        $accountRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $createdBy->id,
            'metadata' => ['account_rep_id' => $accountRep->id],
        ]);

        $this->assertSame(
            $accountRep->id,
            app(ShootMutationSupportService::class)->getClientRep($client->id)
        );
    }

    public function test_get_client_rep_does_not_assign_an_admin_created_by(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $admin->id,
        ]);

        $this->assertNull(app(ShootMutationSupportService::class)->getClientRep($client->id));
    }

    public function test_sales_rep_commission_generation_resolves_client_rep_when_shoot_rep_id_is_missing(): void
    {
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 15,
                ],
            ],
        ]);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
        ]);
        $service = Service::factory()->create([
            'name' => 'Base photo package',
            'price' => 1000,
            'exclude_from_sales_commission' => false,
        ]);

        $start = Carbon::parse('2026-09-13')->startOfDay();
        $end = Carbon::parse('2026-09-19')->endOfDay();

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => null,
            'sales_rep_pay_enabled' => true,
            'scheduled_date' => '2026-09-14',
            'total_quote' => 1000,
            'base_quote' => 1000,
        ]);
        $shoot->services()->attach($service->id, ['price' => 1000, 'quantity' => 1]);

        $invoices = app(InvoiceService::class)->generateSalesRepInvoicesForPeriod($start, $end);

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first()->fresh(['items', 'shoots']);
        $this->assertSame(Invoice::ROLE_SALES_REP, $invoice->role);
        $this->assertSame($salesRep->id, (int) $invoice->sales_rep_id);
        $this->assertTrue($invoice->shoots->contains('id', $shoot->id));
        $this->assertSame($salesRep->id, (int) $shoot->fresh()->rep_id);
        $this->assertEquals(150.0, (float) $invoice->total_amount);
    }

    public function test_payout_summaries_resolve_client_rep_when_shoot_rep_id_is_missing(): void
    {
        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 15,
                ],
            ],
        ]);
        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
        ]);
        $service = Service::factory()->create([
            'name' => 'Base photo package',
            'price' => 1000,
            'exclude_from_sales_commission' => false,
        ]);

        $start = Carbon::parse('2026-09-13')->startOfDay();
        $end = Carbon::parse('2026-09-19')->endOfDay();

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => null,
            'sales_rep_pay_enabled' => true,
            'scheduled_date' => '2026-09-14',
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'total_quote' => 1000,
            'base_quote' => 1000,
        ]);
        $shoot->services()->attach($service->id, ['price' => 1000, 'quantity' => 1]);

        $summary = app(PayoutReportService::class)
            ->buildSalesRepSummaries($start, $end)
            ->firstWhere('id', $salesRep->id);

        $this->assertNotNull($summary);
        $this->assertSame(1000.0, (float) $summary['gross_total']);
        $this->assertSame(150.0, (float) $summary['commission_total']);
        $this->assertSame(1, $summary['shoot_count']);
        $this->assertNull($shoot->fresh()->rep_id);
    }
}
