<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootHistoryAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_history_export_and_aggregates_require_shoot_assignment(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'created_by_id' => $rep->id,
            'metadata' => ['accountRepId' => $rep->id, 'account_rep_id' => $rep->id],
        ]);
        $service = Service::factory()->create(['name' => 'Assigned service']);
        $assigned = Shoot::factory()->create([
            'client_id' => $client->id, 'rep_id' => $rep->id,
            'address' => 'Assigned property', 'base_quote' => 100, 'tax_amount' => 0, 'total_quote' => 100,
        ]);
        $foreign = Shoot::factory()->create([
            'client_id' => $client->id, 'rep_id' => $otherRep->id,
            'address' => 'Unassigned private property', 'base_quote' => 900, 'tax_amount' => 0, 'total_quote' => 900,
        ]);
        foreach ([$assigned, $foreign] as $shoot) {
            $shoot->services()->attach($service->id, ['price' => $shoot->base_quote, 'quantity' => 1]);
        }

        Sanctum::actingAs($rep);
        $this->getJson('/api/shoots/history')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonPath('data.0.client.totalShoots', 1)
            ->assertJsonMissing(['street' => 'Unassigned private property']);
        $this->getJson('/api/shoots/history?search=Unassigned')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/shoots/history?group_by=services')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shootCount', 1);

        $csv = $this->get('/api/shoots/history/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Assigned property', $csv);
        $this->assertStringNotContainsString('Unassigned private property', $csv);

        $assigned->update(['rep_id' => $otherRep->id]);
        $this->getJson('/api/shoots/history')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_explicit_finance_access_remains_and_unknown_roles_are_denied(): void
    {
        Shoot::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance']));
        $this->getJson('/api/shoots/history')->assertOk()->assertJsonPath('meta.total', 2);

        Sanctum::actingAs(User::factory()->create(['role' => 'unknown']));
        $this->getJson('/api/shoots/history')->assertForbidden();
        $this->get('/api/shoots/history/export')->assertForbidden();
    }
}
