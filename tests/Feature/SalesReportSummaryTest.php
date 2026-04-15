<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sales_rep_can_fetch_scoped_sales_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 10:00:00'));

        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 12.5,
                ],
            ],
        ]);

        $otherRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 20,
                ],
            ],
        ]);

        $newCreatedClient = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
            'created_at' => Carbon::parse('2026-04-03 09:00:00'),
        ]);

        $metadataClient = User::factory()->create([
            'role' => 'client',
            'metadata' => [
                'accountRepId' => $salesRep->id,
            ],
            'created_at' => Carbon::parse('2026-03-25 09:00:00'),
        ]);

        $shootScopedClient = User::factory()->create([
            'role' => 'client',
            'created_at' => Carbon::parse('2026-01-10 09:00:00'),
        ]);

        $existingClient = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
            'created_at' => Carbon::parse('2026-02-01 09:00:00'),
        ]);

        $otherRepClient = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $otherRep->id,
            'created_at' => Carbon::parse('2026-04-07 09:00:00'),
        ]);

        $createdClientShoot = Shoot::factory()->create([
            'client_id' => $newCreatedClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-11',
        ]);

        Shoot::factory()->create([
            'client_id' => $newCreatedClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-13',
        ]);

        $metadataClientShoot = Shoot::factory()->create([
            'client_id' => $metadataClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-09',
        ]);

        $shootScopedFirstShoot = Shoot::factory()->create([
            'client_id' => $shootScopedClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-03-28',
        ]);

        Shoot::factory()->create([
            'client_id' => $shootScopedClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-14',
        ]);

        $existingClientShoot = Shoot::factory()->create([
            'client_id' => $existingClient->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-05',
        ]);

        $otherRepShoot = Shoot::factory()->create([
            'client_id' => $otherRepClient->id,
            'rep_id' => $otherRep->id,
            'scheduled_date' => '2026-04-09',
        ]);

        Invoice::factory()->create([
            'client_id' => $newCreatedClient->id,
            'shoot_id' => $createdClientShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-10'),
            'due_date' => Carbon::parse('2026-04-17'),
            'total' => 500,
            'amount_paid' => 500,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-04-12 11:00:00'),
        ]);

        Invoice::factory()->create([
            'client_id' => $newCreatedClient->id,
            'shoot_id' => $createdClientShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-13'),
            'due_date' => Carbon::parse('2026-04-20'),
            'total' => 200,
            'amount_paid' => 50,
            'status' => Invoice::STATUS_SENT,
            'paid_at' => null,
        ]);

        Invoice::factory()->create([
            'client_id' => $metadataClient->id,
            'shoot_id' => $metadataClientShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-03-24'),
            'due_date' => Carbon::parse('2026-03-31'),
            'total' => 400,
            'amount_paid' => 400,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-03-27 14:00:00'),
        ]);

        Invoice::factory()->create([
            'client_id' => $metadataClient->id,
            'shoot_id' => $metadataClientShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-10'),
            'due_date' => Carbon::parse('2026-04-22'),
            'total' => 300,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
            'paid_at' => null,
        ]);

        Invoice::factory()->create([
            'client_id' => $shootScopedClient->id,
            'shoot_id' => $shootScopedFirstShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-10'),
            'due_date' => Carbon::parse('2026-04-18'),
            'total' => 250,
            'amount_paid' => 250,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-04-14 15:30:00'),
        ]);

        Invoice::factory()->create([
            'client_id' => $existingClient->id,
            'shoot_id' => $existingClientShoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-02'),
            'due_date' => Carbon::parse('2026-04-12'),
            'total' => 600,
            'amount_paid' => 600,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-04-05 10:00:00'),
        ]);

        Invoice::factory()->create([
            'client_id' => $otherRepClient->id,
            'shoot_id' => $otherRepShoot->id,
            'sales_rep_id' => $otherRep->id,
            'issue_date' => Carbon::parse('2026-04-08'),
            'due_date' => Carbon::parse('2026-04-16'),
            'total' => 999,
            'amount_paid' => 999,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-04-10 09:00:00'),
        ]);

        Sanctum::actingAs($salesRep);

        $response = $this->getJson('/api/reports/sales/summary?start_date=2026-03-17&end_date=2026-04-15');

        $response->assertOk();
        $response->assertJsonPath('period.start_date', '2026-03-17');
        $response->assertJsonPath('period.end_date', '2026-04-15');
        $this->assertEquals(30, $response->json('period.days_window'));
        $this->assertEquals(3, $response->json('summary.new_clients'));
        $this->assertEquals(1750, $response->json('summary.paid_revenue'));
        $this->assertEquals(12.5, $response->json('summary.commission_rate'));
        $this->assertEquals(218.75, $response->json('summary.commission_earned'));
        $this->assertEquals(437.5, $response->json('summary.average_client_value'));

        $topClients = $response->json('top_clients');
        $this->assertCount(4, $topClients);
        $this->assertSame($existingClient->id, $topClients[0]['client_id']);
        $this->assertSame($newCreatedClient->id, $topClients[1]['client_id']);
        $this->assertEquals(150.0, $topClients[1]['outstanding_balance']);
        $this->assertSame('2026-04-13', $topClients[1]['last_shoot_date']);

        $newClients = $response->json('new_clients');
        $this->assertCount(3, $newClients);
        $this->assertSame($newCreatedClient->id, $newClients[0]['client_id']);
        $this->assertSame($shootScopedClient->id, $newClients[1]['client_id']);
        $this->assertSame('2026-03-28', $newClients[1]['created_at']);
        $this->assertSame($metadataClient->id, $newClients[2]['client_id']);
        $this->assertEquals(300.0, $newClients[2]['outstanding_balance']);

        $scopedClientIds = collect($topClients)
            ->pluck('client_id')
            ->merge(collect($newClients)->pluck('client_id'))
            ->unique()
            ->all();

        $this->assertNotContains($otherRepClient->id, $scopedClientIds);
        $this->assertNotEmpty($response->json('trend'));
    }

    public function test_non_sales_rep_cannot_fetch_sales_summary(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/reports/sales/summary')
            ->assertForbidden();
    }

    public function test_missing_commission_rate_returns_null_commission_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 10:00:00'));

        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [],
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
            'created_at' => Carbon::parse('2026-04-05 09:00:00'),
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2026-04-06',
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2026-04-05'),
            'due_date' => Carbon::parse('2026-04-12'),
            'total' => 320,
            'amount_paid' => 320,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-04-07 12:00:00'),
        ]);

        Sanctum::actingAs($salesRep);

        $response = $this->getJson('/api/reports/sales/summary?start_date=2026-03-17&end_date=2026-04-15');

        $response->assertOk();
        $this->assertNull($response->json('summary.commission_rate'));
        $this->assertNull($response->json('summary.commission_earned'));
        $this->assertEquals(320, $response->json('summary.paid_revenue'));
    }

    public function test_empty_window_returns_zeroed_summary_and_empty_lists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 10:00:00'));

        $salesRep = User::factory()->create([
            'role' => 'salesRep',
            'metadata' => [
                'repDetails' => [
                    'commissionPercentage' => 10,
                ],
            ],
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'created_by_id' => $salesRep->id,
            'created_at' => Carbon::parse('2025-12-10 09:00:00'),
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'scheduled_date' => '2025-12-12',
        ]);

        Invoice::factory()->create([
            'client_id' => $client->id,
            'shoot_id' => $shoot->id,
            'sales_rep_id' => $salesRep->id,
            'issue_date' => Carbon::parse('2025-12-12'),
            'due_date' => Carbon::parse('2025-12-19'),
            'total' => 450,
            'amount_paid' => 450,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => Carbon::parse('2025-12-15 10:00:00'),
        ]);

        Sanctum::actingAs($salesRep);

        $response = $this->getJson('/api/reports/sales/summary?start_date=2026-04-01&end_date=2026-04-15');

        $response->assertOk();
        $this->assertEquals(0, $response->json('summary.new_clients'));
        $this->assertEquals(0, $response->json('summary.paid_revenue'));
        $this->assertEquals(10.0, $response->json('summary.commission_rate'));
        $this->assertEquals(0.0, $response->json('summary.commission_earned'));
        $this->assertEquals(0.0, $response->json('summary.average_client_value'));
        $this->assertSame([], $response->json('trend'));
        $this->assertSame([], $response->json('top_clients'));
        $this->assertSame([], $response->json('new_clients'));
    }
}
