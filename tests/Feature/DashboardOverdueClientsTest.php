<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardOverdueClientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
        Bus::fake();
        Http::preventStrayRequests();
    }

    private function shoot(User $client, array $attributes = []): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => 'delivered',
            'workflow_status' => 'delivered',
            'delivery_status' => 'delivered',
            'completed_at' => now()->subDays(31),
            'shoot_ready_notified_at' => null,
            'payment_status' => 'unpaid',
            'total_quote' => 300,
        ], $attributes)));
    }

    public function test_groups_only_delivered_unpaid_balances_after_thirty_days_using_real_payments(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $client = User::factory()->create(['role' => 'client']);
        $first = $this->shoot($client, ['completed_at' => now()->subDays(40), 'address' => 'Maryland property']);
        $second = $this->shoot($client);
        Payment::withoutEvents(fn () => Payment::factory()->create(['shoot_id' => $first->id, 'invoice_id' => null, 'amount' => 75]));
        $paid = $this->shoot($client);
        Payment::withoutEvents(fn () => Payment::factory()->create(['shoot_id' => $paid->id, 'invoice_id' => null, 'amount' => 300]));
        foreach ([
            ['completed_at' => now()->subDays(30)],
            ['completed_at' => now()->subDays(29)],
            ['completed_at' => null],
            ['workflow_status' => 'scheduled', 'status' => 'scheduled'],
            ['delivery_status' => 'pending'],
            ['status' => 'cancelled'],
            ['payment_status' => 'paid'],
            ['payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED],
            ['total_quote' => 0],
            ['external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true]]],
        ] as $excluded) {
            $this->shoot($client, $excluded);
        }

        $response = $this->actingAs($admin)->getJson('/api/dashboard/overdue-clients')->assertOk();
        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.balanceDue', 525)
            ->assertJsonPath('data.0.oldestDays', 40)
            ->assertJsonCount(2, 'data.0.shoots');
        $this->assertSame([$first->id, $second->id], array_column($response->json('data.0.shoots'), 'id'));
    }

    public function test_rep_sees_only_explicit_shoot_assignments_and_account_metadata_fallbacks(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $mine = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $numeric = User::factory()->create(['role' => 'client', 'metadata' => ['account_rep_id' => $rep->id]]);
        $other = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $otherRep->id]]);
        $own = $this->shoot($mine);
        $reassigned = $this->shoot($mine, ['rep_id' => $otherRep->id]);
        $this->shoot($numeric);
        $assigned = $this->shoot($other, ['rep_id' => $rep->id]);
        $this->shoot($other);
        $conflicting = User::factory()->create(['role' => 'client', 'metadata' => [
            'accountRepId' => (string) $otherRep->id, 'account_rep_id' => $rep->id,
        ]]);
        $this->shoot($conflicting);

        $response = $this->actingAs($rep)->getJson('/api/dashboard/overdue-clients')->assertOk()->assertJsonPath('meta.total', 3);
        $visible = collect($response->json('data'))->flatMap(fn ($client) => array_column($client['shoots'], 'id'))->all();
        $this->assertContains($own->id, $visible);
        $this->assertContains($assigned->id, $visible);
        $this->assertCount(3, $visible);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->getJson('/api/dashboard/overdue-clients')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.shoots.1.id', $reassigned->id);
    }

    public function test_unrelated_roles_cannot_read_client_balances(): void
    {
        foreach (['admin', 'editing_manager', 'photographer', 'editor', 'client'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/dashboard/overdue-clients')->assertForbidden();
        }
    }

    public function test_legacy_ready_notice_is_an_explicit_delivery_fallback_and_clients_are_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        for ($i = 0; $i < 16; $i++) {
            $this->shoot(User::factory()->create(['role' => 'client']), [
                'completed_at' => null,
                'shoot_ready_notified_at' => now()->subDays(31 + $i),
            ]);
        }
        $this->actingAs($admin)->getJson('/api/dashboard/overdue-clients')->assertOk()
            ->assertJsonPath('meta.total', 16)->assertJsonPath('meta.lastPage', 2)->assertJsonCount(15, 'data');
        $this->getJson('/api/dashboard/overdue-clients?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/dashboard/overdue-clients?page=3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 2);
        $this->getJson('/api/dashboard/overdue-clients?page=0')->assertUnprocessable();
    }
}
