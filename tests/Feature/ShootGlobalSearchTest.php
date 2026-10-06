<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): array
    {
        Bus::fake();
        Http::preventStrayRequests();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Marina Example',
            'email' => 'marina.example@example.com',
            'company_name' => 'Example Realty',
        ]);
        $deletedClient = User::factory()->create([
            'role' => 'client',
            'name' => 'Soft Deleted Match',
            'email' => 'soft-deleted@example.com',
        ]);
        $deletedClient->delete();

        $delivered = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'address' => '3254 Gleneagles Dr Silver Spring',
            'city' => 'Silver Spring',
            'state' => 'MD',
            'zip' => 'XXXXX',
            'scheduled_date' => '2026-10-03',
        ]));
        $scheduled = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => 'Other Street',
            'city' => 'Bethesda',
            'state' => 'MD',
            'zip' => 'YYYYY',
            'scheduled_date' => '2026-10-10',
        ]));
        $orphanDelivered = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $deletedClient->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'address' => 'Quiet Orchard Court',
            'city' => 'Nowhere',
            'state' => 'MD',
            'zip' => 'ZZZZZ',
            'scheduled_date' => '2026-09-01',
        ]));

        return compact('admin', 'client', 'delivered', 'scheduled', 'orphanDelivered');
    }

    public function test_scheduled_tab_search_misses_delivered_but_tab_all_and_history_find_it(): void
    {
        ['admin' => $admin, 'delivered' => $delivered, 'scheduled' => $scheduled] = $this->seedCatalog();
        Sanctum::actingAs($admin);

        $this->getJson('/api/shoots?tab=scheduled&search=3254%20Gleneagles')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);

        $all = $this->getJson('/api/shoots?tab=all&search=3254%20Gleneagles&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->json('data');
        $this->assertSame($delivered->id, (int) $all[0]['id']);
        $this->assertSame(Shoot::STATUS_DELIVERED, $all[0]['status'] ?? $all[0]['workflow_status'] ?? null);

        $omittedTab = $this->getJson('/api/shoots?search=3254%20Gleneagles&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->json('data');
        $this->assertSame($delivered->id, (int) $omittedTab[0]['id']);

        $history = $this->getJson('/api/shoots/history?search=3254%20Gleneagles&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data');
        $this->assertSame($delivered->id, (int) $history[0]['id']);

        $this->getJson('/api/shoots?tab=all&search=Other')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $scheduled->id);
    }

    public function test_empty_search_on_tab_all_does_not_error_and_numeric_id_matches(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client',  'name' => 'Id Search Client', 'email' => 'id.search@example.com', 'phonenumber' => 'call-me', 'phone' => 'call-me']);
        // Digit-free address/zip so a short shoot id cannot false-positive via LIKE.
        $target = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'address' => 'Maple Lane',
            'city' => 'Town',
            'state' => 'MD',
            'zip' => 'ABCDE',
        ]));
        Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => 'Oak Avenue',
            'city' => 'Town',
            'state' => 'MD',
            'zip' => 'FGHIJ',
        ]));
        Sanctum::actingAs($admin);

        $this->getJson('/api/shoots?tab=all&search=&per_page=20')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['count']]);

        $this->getJson('/api/shoots?tab=all&search='.$target->id)
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $target->id);

        $this->getJson('/api/shoots?tab=all&search=0'.$target->id)
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $target->id);

        if (strlen((string) $target->id) >= 3) {
            $this->getJson('/api/shoots?tab=all&search='.substr((string) $target->id, -3))
                ->assertOk()
                ->assertJsonPath('data.0.id', $target->id);
        }
    }

    public function test_soft_deleted_client_name_is_not_matched_and_unknown_role_is_forbidden(): void
    {
        ['admin' => $admin] = $this->seedCatalog();
        Sanctum::actingAs($admin);

        $this->getJson('/api/shoots?tab=all&search=Soft%20Deleted%20Match')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);

        Sanctum::actingAs(User::factory()->create(['role' => 'unknown']));
        $this->getJson('/api/shoots?tab=all&search=3254%20Gleneagles')->assertForbidden();
    }

    public function test_sales_rep_search_uses_same_shared_filter_across_statuses(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Rep Visible Client',
            'created_by_id' => $rep->id,
            'metadata' => ['accountRepId' => $rep->id, 'account_rep_id' => $rep->id],
        ]);
        $delivered = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'address' => '42 Command Bar Lane',
        ]));

        Sanctum::actingAs($rep);
        $this->getJson('/api/shoots?tab=scheduled&search=Command%20Bar')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);
        $this->getJson('/api/shoots?tab=all&search=Command%20Bar')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $delivered->id);
        $this->getJson('/api/shoots/history?search=Command%20Bar')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $delivered->id);
    }
}
