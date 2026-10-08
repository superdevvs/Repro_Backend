<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootSalesRepFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_rep_filter_covers_all_operational_tabs_and_explicit_assignment_overrides_account_rep(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['account_rep_id' => (string) $rep->id]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['scheduled' => 'scheduled', 'completed' => 'uploaded', 'delivered' => 'delivered'] as $tab => $status) {
            $fallback = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => null, 'status' => $status, 'workflow_status' => $status]);
            $explicit = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $other->id, 'status' => $status, 'workflow_status' => $status]);
            $this->getJson("/api/shoots?tab={$tab}&sales_rep_id={$rep->id}")->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $fallback->id);
            // The cache must distinguish different rep filters.
            $this->getJson("/api/shoots?tab={$tab}&sales_rep_id={$other->id}")->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $explicit->id);
        }
        $this->getJson("/api/shoots/history?sales_rep_id={$rep->id}")->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/shoots/filters')->assertOk()->assertJsonCount(2, 'data.salesReps');
    }

    public function test_rep_filter_does_not_expand_client_access_or_leak_other_clients_rep_options(): void
    {
        $ownRep = User::factory()->create(['role' => 'salesRep']);
        $foreignRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $ownRep->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        Shoot::factory()->create(['rep_id' => $foreignRep->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        Sanctum::actingAs($client);
        $this->getJson("/api/shoots?tab=scheduled&sales_rep_id={$foreignRep->id}")->assertOk()->assertJsonPath('meta.count', 0);
        $this->getJson('/api/shoots/filters')->assertOk()->assertJsonCount(1, 'data.salesReps')->assertJsonPath('data.salesReps.0.id', $ownRep->id);
    }
}
