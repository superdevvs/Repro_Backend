<?php

namespace Tests\Feature;

use App\Models\AryeoConnection;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AryeoRequestsInboxTest extends TestCase
{
    use RefreshDatabase;

    private AryeoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
        $this->connection = AryeoConnection::create(['name' => 'Test Mac', 'company_key' => 'test',
            'enabled' => true, 'token_hash' => hash('sha256', 'secret'), 'client_ids' => [],
            'last_seen_at' => now(), 'processing_enabled' => true,
            'capabilities' => ['processing_mode' => 'dashboard_jobs', 'shoot_executor' => false]]);
    }

    private function order(?Shoot $shoot = null, array $extra = []): AryeoRequest
    {
        return AryeoRequest::create(array_replace([
            'connection_id' => $this->connection->id, 'source_id' => fake()->uuid(), 'request_id' => fake()->uuid(),
            'listing_id' => fake()->uuid(), 'shoot_id' => $shoot?->id, 'match_status' => $shoot ? 'matched' : 'unmatched',
            'discovery' => ['address' => '2426 Westminster Dr', 'requester_name' => 'Elise Castro', 'requester_email' => 'elise@example.test'],
        ], $extra));
    }

    public function test_staff_inbox_includes_new_unmatched_and_scheduled_requests_without_exposing_credentials_or_media(): void
    {
        $shoot = Shoot::factory()->create(['workflow_status' => 'scheduled', 'status' => 'scheduled']);
        $matched = $this->order($shoot, ['inventory' => ['assets' => [['url' => 'private-original-url']]],
            'match_verification' => ['sensitive' => 'match-proof']]);
        $unmatched = $this->order();
        foreach (['admin', 'superadmin', 'editing_manager'] as $role) {
            $result = $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/dashboard/aryeo-requests');
            $result->assertOk()->assertJsonPath('meta.pendingCount', 2)->assertJsonPath('connections.0.online', true)
                ->assertJsonPath('connections.0.executorReady', false);
            $this->assertEqualsCanonicalizing([$matched->id, $unmatched->id], array_column($result->json('data'), 'id'));
            $result->assertDontSee('token_hash')->assertDontSee('private-original-url')->assertDontSee('match-proof');
        }
        $this->assertDatabaseCount('aryeo_jobs', 0);
        $this->assertNull($unmatched->fresh()->shoot_id);
    }

    public function test_reps_only_receive_their_effectively_assigned_shoots_and_no_unmatched_or_connection_data(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $other = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $own = $this->order(Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => null]));
        $direct = $this->order(Shoot::factory()->create(['rep_id' => $rep->id]));
        $this->order(Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $other->id]));
        $this->order(Shoot::factory()->create(['rep_id' => $other->id]));
        $this->order();
        $result = $this->actingAs($rep)->getJson('/api/dashboard/aryeo-requests')->assertOk()
            ->assertJsonPath('meta.pendingCount', 2)->assertJsonPath('connections', []);
        $this->assertEqualsCanonicalizing([$own->id, $direct->id], array_column($result->json('data'), 'id'));
        $own->shoot_id && Shoot::findOrFail($own->shoot_id)->update(['rep_id' => $other->id]);
        $this->getJson('/api/dashboard/aryeo-requests')->assertOk()->assertJsonPath('meta.pendingCount', 1)
            ->assertJsonPath('data.0.id', $direct->id);
        $this->postJson('/api/shoots/'.$direct->shoot_id.'/aryeo/requests/'.$direct->id.'/process')->assertForbidden();
    }

    public function test_primary_and_secondary_staff_roles_and_denied_roles_are_enforced(): void
    {
        foreach (['client', 'editor', 'photographer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/dashboard/aryeo-requests')->assertForbidden();
        }
        $this->order();
        $this->actingAs(User::factory()->create(['role' => 'editor', 'secondary_roles' => ['editing_manager']]))
            ->getJson('/api/dashboard/aryeo-requests')->assertOk()->assertJsonPath('meta.pendingCount', 1);
    }

    public function test_verified_delivered_history_does_not_inflate_pending_and_unverified_claims_stay_pending(): void
    {
        $order = $this->order();
        $status = ['origin' => 'aryeo_provider', 'delivery_status' => 'delivered', 'request_id' => $order->request_id,
            'listing_id' => $order->listing_id, 'requester_email' => 'elise@example.test', 'checked_at' => now()->subHour()->toIso8601String()];
        $order->update(['inventory' => ['provider_status' => $status]]);
        $pending = $this->order(null, ['inventory' => ['provider_status' => [...$status, 'request_id' => 'wrong-request']]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/dashboard/aryeo-requests')
            ->assertOk()->assertJsonPath('meta.pendingCount', 1)->assertJsonPath('data.0.id', $pending->id);
        $this->getJson('/api/dashboard/aryeo-requests?history=1')->assertOk()->assertJsonPath('meta.pendingCount', 1)
            ->assertJsonPath('data.0.id', $order->id)->assertJsonPath('data.0.status', 'delivered')
            ->assertJsonPath('data.0.providerCheckedAt', $status['checked_at']);
    }

    public function test_disabled_connections_are_excluded_and_pagination_keeps_total_pending_count(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->order();
        }
        $disabled = AryeoConnection::create(['name' => 'Disabled', 'company_key' => 'disabled', 'enabled' => false,
            'token_hash' => 'disabled', 'client_ids' => []]);
        $this->order(null, ['connection_id' => $disabled->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/dashboard/aryeo-requests?page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pendingCount', 21)->assertJsonPath('meta.lastPage', 2);
        $this->getJson('/api/dashboard/aryeo-requests?page=0')->assertUnprocessable();
    }
}
