<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may choose the realtor whose branding fronts a shoot's public tour.
 *
 * Staff and sales reps pick from every client. A client may pick too, but only
 * themselves or an account linked to them; the option list and the update rule
 * are the same rule, so a valid pick always saves and a crafted id never does.
 */
class ShootRealtorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $main, User $linked): AccountLink
    {
        return AccountLink::create([
            'main_account_id' => $main->id,
            'linked_account_id' => $linked->id,
            'shared_details' => ['shoots' => true, 'documents' => false],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $main->id,
        ]);
    }

    private function realtorIdOf(Shoot $shoot): ?int
    {
        $tourLinks = $shoot->fresh()->tour_links ?? [];
        $id = $tourLinks['realtor_client_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    #[Test]
    public function a_client_sees_only_themselves_and_their_linked_circle_as_options(): void
    {
        $teamLead = User::factory()->create(['role' => 'client', 'name' => 'Team Lead', 'company_name' => 'Lead Realty']);
        $agent = User::factory()->create(['role' => 'client', 'name' => 'Linked Agent']);
        $broker = User::factory()->create(['role' => 'client', 'name' => 'Broker Above']);
        User::factory()->create(['role' => 'client', 'name' => 'Unrelated Client']);
        $this->link($teamLead, $agent);   // outgoing: an agent under the lead
        $this->link($broker, $teamLead);  // incoming: the brokerage above them
        $shoot = Shoot::factory()->create(['client_id' => $teamLead->id]);

        Sanctum::actingAs($teamLead);

        $response = $this->getJson('/api/shoots/'.$shoot->id.'/realtor-options')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Broker Above', 'Linked Agent', 'Team Lead'], $names);
        $this->assertSame('Lead Realty', collect($response->json('data'))->firstWhere('name', 'Team Lead')['company']);
    }

    #[Test]
    public function staff_and_reps_see_every_client(): void
    {
        User::factory()->count(3)->create(['role' => 'client']);
        $shoot = Shoot::factory()->create();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->forceFill(['rep_id' => $rep->id])->save();

        foreach ([User::factory()->create(['role' => 'editing_manager']), User::factory()->admin()->create(), $rep] as $actor) {
            Sanctum::actingAs($actor);
            $count = User::query()->where('role', 'client')->count();
            $this->getJson('/api/shoots/'.$shoot->id.'/realtor-options')
                ->assertOk()
                ->assertJsonCount($count, 'data');
        }
    }

    #[Test]
    public function a_client_can_assign_themselves_or_a_linked_account_and_clear_it_again(): void
    {
        $teamLead = User::factory()->create(['role' => 'client']);
        $agent = User::factory()->create(['role' => 'client']);
        $this->link($teamLead, $agent);
        $shoot = Shoot::factory()->create(['client_id' => $teamLead->id]);
        Sanctum::actingAs($teamLead);

        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => $agent->id]])->assertOk();
        $this->assertSame($agent->id, $this->realtorIdOf($shoot));

        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => $teamLead->id]])->assertOk();
        $this->assertSame($teamLead->id, $this->realtorIdOf($shoot));

        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => null]])->assertOk();
        $this->assertNull($this->realtorIdOf($shoot));
    }

    #[Test]
    public function a_client_cannot_assign_an_unrelated_client_or_touch_another_clients_shoot(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $stranger = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => $stranger->id]])
            ->assertStatus(403);
        $this->assertNull($this->realtorIdOf($shoot));

        Sanctum::actingAs($stranger);
        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => $stranger->id]])
            ->assertStatus(403);
        $this->getJson('/api/shoots/'.$shoot->id.'/realtor-options')->assertStatus(403);
    }

    #[Test]
    public function an_editing_manager_and_the_assigned_rep_can_assign_any_client(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->forceFill(['rep_id' => $rep->id])->save();

        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => $client->id]])->assertOk();
        $this->assertSame($client->id, $this->realtorIdOf($shoot));

        Sanctum::actingAs($rep);
        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['realtor_client_id' => null]])->assertOk();
        $this->assertNull($this->realtorIdOf($shoot));
    }

    #[Test]
    public function roles_that_cannot_choose_a_realtor_are_refused_the_options(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);

        Sanctum::actingAs($photographer);
        $this->getJson('/api/shoots/'.$shoot->id.'/realtor-options')->assertStatus(403);
    }
}
