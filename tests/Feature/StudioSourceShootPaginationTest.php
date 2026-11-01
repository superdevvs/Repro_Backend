<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioSourceShootPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/workspaces/sources/shoots';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'schedule.calendar_timezone' => 'America/New_York', 'studio.client_access_enabled' => true]);
        Carbon::setTestNow('2026-11-01 12:00:00');
        Bus::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actor(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role, 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function shoot(User $owner, array $attributes = []): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create(array_replace([
            'client_id' => $owner->id, 'photographer_id' => null, 'service_id' => null,
            'created_by' => (string) $owner->id, 'address' => 'Source property',
            'scheduled_at' => null, 'scheduled_date' => '2026-11-01', 'time' => '10:30',
            'timezone' => 'America/New_York', 'updated_at' => now(),
        ], $attributes)));
    }

    public function test_pages_browse_beyond_twenty_with_stable_tie_order_and_no_fake_total(): void
    {
        $actor = $this->actor();
        $ids = [];
        for ($i = 0; $i < 23; $i++) $ids[] = $this->shoot($actor)->id;
        Bus::fake(); // Count read-path dispatches, excluding fixture user broadcasts.
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson(self::URL.'?page='.$page.'&per_page=10')->assertOk()
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.per_page', 10)
                ->assertJsonPath('meta.has_more', $page < 3)
                ->assertJsonPath('meta.next_page', $page < 3 ? $page + 1 : null)
                ->assertJsonPath('meta.returned_count', $page < 3 ? 10 : 3)
                ->assertJsonPath('meta.calendar_context.today', '2026-11-01')
                ->assertJsonPath('meta.calendar_context.timezone', 'America/New_York');
            $this->assertArrayNotHasKey('total', $response->json('meta'));
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        }

        $this->assertSame(array_reverse($ids), $seen);
        $this->assertCount(23, array_unique($seen));
        $this->getJson(self::URL.'?page=4&per_page=10')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.has_more', false);
        // Request-monitor telemetry is expected; browsing must not start editing.
        Bus::assertNotDispatched(\App\Jobs\ProcessStudioWorkspace::class);
        $this->assertDatabaseCount('studio_workspaces', 0);
    }

    public function test_date_filter_uses_property_calendar_day_before_pagination(): void
    {
        $actor = $this->actor();
        $night = $this->shoot($actor, ['scheduled_at' => '2026-11-02 02:00:00', 'scheduled_date' => '2026-11-02']);
        $tokyo = $this->shoot($actor, ['scheduled_at' => '2026-10-31 15:30:00', 'scheduled_date' => null, 'timezone' => 'Asia/Tokyo']);
        $legacy = $this->shoot($actor);
        $previous = $this->shoot($actor, ['scheduled_at' => '2026-11-01 02:00:00']);

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $json = $this->getJson(self::URL.'?page='.$page.'&per_page=1&scheduled_local_date=2026-11-01')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.scheduled_local_date', '2026-11-01')
                ->assertJsonPath('data.0.scheduledDate', '2026-11-01')->assertJsonPath('meta.has_more', $page < 3)->json();
            $seen[] = $json['data'][0]['id'];
        }
        $this->assertEqualsCanonicalizing([$night->id, $tokyo->id, $legacy->id], $seen);
        $this->assertNotContains($previous->id, $seen);
    }

    public function test_search_and_date_filters_combine_and_projection_stays_property_only(): void
    {
        $actor = $this->actor();
        $match = $this->shoot($actor, ['mls_id' => 'MLS-NEEDED', 'notes' => str_repeat('Private notes ', 3000)]);
        $this->shoot($actor, ['mls_id' => 'MLS-NEEDED', 'scheduled_date' => '2026-11-02']);
        $this->shoot($actor, ['mls_id' => 'different']);

        $response = $this->getJson(self::URL.'?page=1&per_page=20&q=MLS-NEEDED&scheduled_local_date=2026-11-01')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.timezone', 'America/New_York');
        $this->assertEqualsCanonicalizing(['id', 'propertyIdentifier', 'address', 'location', 'label', 'thumbnailUrl', 'updatedAt', 'scheduledDate', 'timezone'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('Private notes', $response->getContent());
    }

    public function test_clients_only_page_their_authorized_shoots(): void
    {
        $client = $this->actor('client');
        $own = [$this->shoot($client)->id, $this->shoot($client)->id];
        $other = User::factory()->create(['role' => 'client']);
        for ($i = 0; $i < 4; $i++) $this->shoot($other);

        $first = $this->getJson(self::URL.'?page=1&per_page=1')->assertOk()->assertJsonPath('meta.has_more', true)->json('data.0.id');
        $last = $this->getJson(self::URL.'?page=2&per_page=1')->assertOk()->assertJsonPath('meta.has_more', false)->json('data.0.id');
        $this->assertEqualsCanonicalizing($own, [$first, $last]);
    }

    public function test_editor_assignment_removal_takes_effect_on_the_next_page_request(): void
    {
        $editor = $this->actor('editor');
        $client = User::factory()->create(['role' => 'client']);
        $oldest = $this->shoot($client, ['editor_id' => $editor->id]);
        $revoked = $this->shoot($client, ['editor_id' => $editor->id]);
        $newest = $this->shoot($client, ['editor_id' => $editor->id]);
        $this->shoot($client);
        $this->getJson(self::URL.'?page=1&per_page=1')->assertOk()->assertJsonPath('data.0.id', $newest->id);
        Shoot::withoutEvents(fn () => $revoked->update(['editor_id' => null]));
        $this->getJson(self::URL.'?page=2&per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id)->assertJsonPath('meta.has_more', false);
    }

    public function test_legacy_calls_keep_the_twenty_result_shape_and_legacy_blank_search(): void
    {
        $actor = $this->actor();
        for ($i = 0; $i < 23; $i++) $this->shoot($actor);
        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 20);
        $this->assertArrayNotHasKey('has_more', $response->json('meta'));
        $this->assertArrayNotHasKey('scheduledDate', $response->json('data.0'));
        $this->getJson('/api/studio/shoots/search')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/shoots/search?page=1&per_page=10')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.has_more', false);
    }

    public function test_invalid_paging_and_calendar_inputs_are_rejected(): void
    {
        $this->actor();
        foreach (['page=0', 'page=5001', 'page=word', 'per_page=0', 'per_page=51', 'scheduled_local_date=2026-02-30', 'scheduled_local_date=11-01-2026'] as $query) {
            $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_source_browsing_does_not_enable_new_roles(): void
    {
        foreach (['photographer', 'salesRep'] as $role) {
            $this->actor($role);
            $this->getJson(self::URL.'?page=1&per_page=20')->assertForbidden();
        }
    }
}
