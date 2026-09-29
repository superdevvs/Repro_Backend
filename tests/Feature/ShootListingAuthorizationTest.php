<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootListingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_include_files_false_omits_file_rows_from_the_list(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->shoot([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'hero_image' => null,
            'raw_photo_count' => 12,
        ]);
        ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'filename' => 'secret-raw.CR3',
            'stored_filename' => 'secret-raw.CR3',
            'path' => 'shoots/secret-raw.CR3',
            'file_type' => 'image/x-canon-cr3',
            'mime_type' => 'image/x-canon-cr3',
            'file_size' => 2048,
            'uploaded_by' => $photographer->id,
            'workflow_stage' => 'todo',
        ]);
        ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'filename' => 'living-room.jpg',
            'stored_filename' => 'living-room.jpg',
            'path' => 'shoots/living-room.jpg',
            'grid_path' => 'shoots/living-room-grid.jpg',
            'web_path' => 'shoots/living-room-web.jpg',
            'thumbnail_path' => 'shoots/living-room-thumb.jpg',
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'edited',
            'file_size' => 4096,
            'uploaded_by' => $photographer->id,
            'workflow_stage' => ShootFile::STAGE_VERIFIED,
            'is_cover' => true,
            'is_hidden' => false,
        ]);

        $response = $this->actingAs($photographer)->getJson('/api/shoots?tab=delivered&include_files=false&no_cache=true');

        $response->assertOk();
        $this->assertSame([], $response->json('data.0.files'));
        $this->assertSame(12, $response->json('data.0.media_summary.rawUploaded'));
        $this->assertStringNotContainsString('secret-raw', $response->getContent());
        $this->assertNotEmpty($response->json('data.0.hero_image'));
        $this->assertNotEmpty($response->json('data.0.preview_images'));
        $this->assertStringContainsString('living-room', (string) $response->json('data.0.hero_image'));

        $withFiles = $this->actingAs($photographer)->getJson('/api/shoots?tab=delivered&include_files=true&no_cache=true');
        $withFiles->assertOk();
        $filenames = collect($withFiles->json('data.0.files'))->pluck('filename')->all();
        $this->assertContains('secret-raw.CR3', $filenames);
    }

    public function test_include_files_false_still_resolves_stored_hero_without_preview_files(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->shoot([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'hero_image' => 'shoots/cover.jpg',
            'raw_photo_count' => 3,
        ]);

        $response = $this->actingAs($photographer)->getJson('/api/shoots?tab=delivered&include_files=false&no_cache=true');

        $response->assertOk();
        $this->assertSame([], $response->json('data.0.files'));
        $this->assertNotEmpty($response->json('data.0.hero_image'));
        $this->assertSame(
            [$response->json('data.0.hero_image')],
            $response->json('data.0.preview_images')
        );
    }

    public function test_sales_queries_counts_and_filter_metadata_include_every_shoot(): void
    {
        $sales = User::factory()->create(['role' => 'salesRep']);
        $otherSales = User::factory()->create(['role' => 'salesRep']);
        $mine = $this->shoot(['rep_id' => $sales->id]);
        $other = $this->shoot(['rep_id' => $otherSales->id]);
        $unassigned = $this->shoot(['rep_id' => null]);

        foreach (['salesRep', 'sales_rep', 'rep', 'representative'] as $role) {
            $sales->role = $role;
            $payload = $this->listing($sales);
            $this->assertEqualsCanonicalizing(
                [$mine->id, $other->id, $unassigned->id],
                array_column($payload['data'], 'id')
            );
            $this->assertSame(3, $payload['meta']['count']);
            $this->assertEqualsCanonicalizing(
                [$mine->client_id, $other->client_id, $unassigned->client_id],
                array_column($payload['meta']['filters']['clients'], 'id')
            );
            $this->assertEqualsCanonicalizing(
                [$mine->photographer_id, $other->photographer_id, $unassigned->photographer_id],
                array_column($payload['meta']['filters']['photographers'], 'id')
            );
            $this->assertContains($other->service->name, $payload['meta']['filters']['services']);
            $this->assertSame(1, $this->listing($sales, ['client_id' => $other->client_id])['meta']['count']);
            $this->assertSame(1, $this->listing($sales, ['search' => $other->client->email])['meta']['count']);
        }
    }

    public function test_requested_filter_runs_before_pagination_and_has_a_separate_admin_cache_entry(): void
    {
        $requested = $this->shoot(['rep_id' => null, 'status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED]);
        $scheduled = $this->shoot();
        for ($index = 0; $index < 12; $index++) {
            $scheduled->replicate()->save();
        }
        foreach (['salesRep', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame(14, $this->listing($user, ['per_page' => 12])['meta']['count']);
            $payload = $this->listing($user, ['per_page' => 12, 'scheduled_status' => 'requested']);
            $this->assertSame([$requested->id], array_column($payload['data'], 'id'));
            $this->assertSame(1, $payload['meta']['count']);
            $this->assertSame(13, $this->listing($user, ['per_page' => 12, 'scheduled_status' => 'scheduled'])['meta']['count']);
        }
    }

    public function test_sales_visibility_survives_assignment_removal(): void
    {
        $sales = User::factory()->create(['role' => 'salesRep']);
        $shoot = $this->shoot(['rep_id' => $sales->id]);
        $this->assertSame(1, $this->listing($sales)['meta']['count']);
        DB::table('shoots')->where('id', $shoot->id)->update(['rep_id' => null]);

        $payload = $this->listing($sales);
        $this->assertSame([$shoot->id], array_column($payload['data'], 'id'));
        $this->assertSame(1, $payload['meta']['count']);
        $this->assertSame([$shoot->client_id], array_column($payload['meta']['filters']['clients'], 'id'));
    }

    public function test_guests_and_unknown_roles_are_denied_before_cache_lookup(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $user->role = 'unexpected_role';
        $request = Request::create('/api/shoots', 'GET');
        $transform = fn (Shoot $shoot) => ['id' => $shoot->id];
        Cache::put('shoots_index_guest_guest_scheduled_1_25', ['data' => ['private']], 300);
        $this->assertSame(401, app(ShootListingService::class)->index($request, null, $transform)->status());
        $this->assertSame(403, app(ShootListingService::class)->index($request, $user, $transform)->status());
    }

    public function test_privileged_queries_ignore_old_cache_namespace(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shoot();
        Cache::put('shoots_index_'.$admin->id.'_admin_scheduled_1_25', ['data' => [['id' => -1]]], 300);
        Cache::put('shoots_filter_meta_'.$admin->id.'_scheduled', ['clients' => [['id' => -1]]], 300);
        $payload = $this->listing($admin);
        $this->assertSame([$shoot->id], array_column($payload['data'], 'id'));
        $this->assertSame([$shoot->client_id], array_column($payload['meta']['filters']['clients'], 'id'));
    }

    public function test_discovery_returns_only_visible_delivered_property_projection(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $listing = $this->shoot([
            'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true, 'is_listing_hidden' => false,
            'listing_type' => 'for_sale', 'hero_image' => 'https://private.example/original.jpg',
            'property_details' => ['bedrooms' => 3, 'bathrooms' => 2, 'sqft' => 1200, 'price' => 600000,
                'internal_notes' => 'secret-property-note', 'agent_email' => 'private-agent@example.com'],
            'admin_issue_notes' => 'private workflow detail',
        ]);
        $this->shoot(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true, 'is_listing_hidden' => true]);
        $this->shoot(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_private_listing' => true]);
        $this->shoot(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => false]);
        $payload = $this->listing($viewer, [
            'private_listing' => '1', 'listing_scope' => 'all', 'tab' => 'scheduled',
            'include_files' => 'true', 'include_payments' => 'true', 'include_hidden' => 'true',
        ], true);

        $this->assertSame([$listing->id], array_column($payload['data'], 'id'));
        $this->assertSame(1, $payload['meta']['count']);
        $this->assertSame('delivered', $payload['meta']['tab']);
        $item = $payload['data'][0];
        $this->assertSame($listing->address, $item['address']);
        $this->assertEquals(3, $item['bedrooms']);
        $this->assertEquals(600000, $item['price']);
        $this->assertTrue($item['discovery_only']);
        $this->assertFalse($item['can_view_details']);
        $this->assertFalse($item['can_download_media']);
        foreach (['files', 'payments', 'client', 'photographer', 'rep', 'services', 'property_details',
            'hero_image', 'total_quote', 'admin_issue_notes', 'tour_links', 'status', 'workflow_status'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertSame(['clients' => [], 'photographers' => [], 'services' => []], $payload['meta']['filters']);
        $this->assertStringNotContainsString('private-agent', json_encode($payload));
        $this->assertStringNotContainsString('private.example', json_encode($payload));
    }

    public function test_discovery_search_and_filters_cannot_probe_contacts_or_assignments(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $listing = $this->shoot(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => true, 'is_listing_hidden' => false, 'address' => '17 Discovery Lane']);
        $params = ['private_listing' => 'true', 'listing_scope' => 'all'];
        $this->assertSame(1, $this->listing($viewer, $params + ['search' => 'Discovery Lane'], true)['meta']['count']);
        $this->assertSame(0, $this->listing($viewer, $params + ['search' => $listing->client->email], true)['meta']['count']);
        $this->assertSame(0, $this->listing($viewer, $params + ['search' => $listing->photographer->name], true)['meta']['count']);
        $this->assertSame(1, $this->listing($viewer, $params + ['client_id' => -1, 'photographer_id' => -1,
            'services' => 'nonexistent-service', 'missing' => 'raw', 'bracket' => 3], true)['meta']['count']);
    }

    public function test_false_or_missing_private_flag_never_grants_discovery(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $this->shoot(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'is_private_listing' => false]);
        foreach ([null, 'false', '0', 'invalid'] as $flag) {
            $params = ['tab' => 'delivered', 'listing_scope' => 'all'];
            if ($flag !== null) { $params['private_listing'] = $flag; }
            $this->assertSame(0, $this->listing($viewer, $params)['meta']['count']);
        }
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
            'is_private_listing' => false, 'is_listing_hidden' => false,
        ], $attributes));
    }

    private function listing(User $user, array $params = [], bool $discovery = false): array
    {
        $request = Request::create('/api/shoots', 'GET', $params);
        $request->setUserResolver(fn () => $user);
        $transform = function (Shoot $shoot) use ($discovery) {
            $this->assertFalse($discovery, 'Discovery must never invoke the operational presenter.');
            return ['id' => $shoot->id];
        };
        $response = app(ShootListingService::class)->index($request, $user, $transform);
        $this->assertSame(200, $response->status(), $response->getContent());
        return $response->getData(true);
    }
}
