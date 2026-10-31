<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioWorkspaceLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_native_library_pages_and_filters_without_changing_legacy_shape(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $other = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $photo = $this->workspace($admin, 'listing-ready', [['id' => 'p1', 'shootId' => $shoot->id]]);
        $otherPhoto = $this->workspace($admin, 'twilight', [['id' => 'p2', 'shootId' => $other->id]]);
        $video = $this->workspace($admin, 'property-reel', [['id' => 'p1', 'shootId' => $shoot->id]]);
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(3, 'data')->assertJsonMissingPath('meta');
        $this->getJson('/api/studio/workspaces?mode=photo&shootId='.$shoot->id.'&page=1&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $photo->id)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.has_more', false);
        $this->getJson('/api/studio/workspaces?mode=video&page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $video->id);
        $first = $this->getJson('/api/studio/workspaces?mode=photo&page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.has_more', true)->json('data.0.id');
        $second = $this->getJson('/api/studio/workspaces?mode=photo&page=2&per_page=1')
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.has_more', false)->json('data.0.id');
        $this->assertNotSame($first, $second);
        $this->assertEqualsCanonicalizing([$photo->id, $otherPhoto->id], [$first, $second]);
    }

    public function test_shoot_filter_finds_booked_and_multishoot_edits_but_preserves_team_scope(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $other = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $anotherShoot = Shoot::factory()->create();
        $booked = $this->workspace($other, 'full-shoot', []);
        $booked->update(['shoot_id' => $shoot->id]);
        $mixed = $this->workspace($manager, 'property-reel', [['id' => 'a', 'shootId' => $anotherShoot->id], ['id' => 'b', 'shootId' => (string) $shoot->id]]);
        $this->workspace($other, 'listing-ready', [['id' => 'hidden', 'shootId' => $shoot->id]]);
        Sanctum::actingAs($manager);
        $result = $this->getJson('/api/studio/workspaces?shootId='.$shoot->id.'&page=1')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$booked->id, $mixed->id], array_column($result->json('data'), 'id'));
    }

    public function test_editor_reassignment_and_client_ownership_are_enforced_before_shoot_filter(): void
    {
        config(['studio.client_access_enabled' => true]);
        $editor = User::factory()->create(['role' => 'editor']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id, 'client_id' => $client->id, 'payment_status' => 'paid']);
        $owned = $this->workspace($editor, 'twilight', [['id' => 'a', 'shootId' => $shoot->id]]);
        $this->workspace($client, 'twilight', [['id' => 'b', 'shootId' => $shoot->id]]);
        Sanctum::actingAs($editor);
        $this->getJson('/api/studio/workspaces?shootId='.$shoot->id.'&page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $owned->id);
        $shoot->update(['editor_id' => null]);
        $this->getJson('/api/studio/workspaces?shootId='.$shoot->id.'&page=1')->assertForbidden();
        Sanctum::actingAs($client);
        $this->getJson('/api/studio/workspaces?shootId='.$shoot->id.'&page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $shoot->update(['client_id' => User::factory()->create(['role' => 'client'])->id]);
        $this->getJson('/api/studio/workspaces?shootId='.$shoot->id.'&page=1')->assertForbidden();
    }

    public function test_invalid_filter_and_page_values_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['page=0', 'per_page=101', 'per_page=0', 'mode=unknown', 'shootId=-1'] as $query) {
            $this->getJson('/api/studio/workspaces?'.$query)->assertUnprocessable();
        }
    }

    public function test_unfiltered_library_show_and_download_recheck_editor_assignment(): void
    {
        Storage::fake('public');
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $file = $this->sourceFile($shoot, $editor);
        $workspace = $this->completedWorkspace($editor, $file);
        $workspace->update(['request_id' => 'original-edit-request']);
        Sanctum::actingAs($editor);
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertOk();
        $this->get('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertOk();
        $shoot->update(['editor_id' => null]);
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/workspaces?page=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertForbidden();
        $this->getJson('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertForbidden();
        $this->postJson('/api/studio/workspaces', ['requestId' => 'original-edit-request'])->assertForbidden();
    }

    public function test_client_library_show_and_download_recheck_current_payment_and_release_state(): void
    {
        Storage::fake('public');
        config(['studio.client_access_enabled' => true]);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'payment_status' => 'paid', 'total_quote' => 100, 'bypass_paywall' => false]);
        $file = $this->sourceFile($shoot, $client);
        $workspace = $this->completedWorkspace($client, $file);
        Sanctum::actingAs($client);
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertOk();
        foreach ([['payment_status' => 'unpaid'], ['payment_status' => 'paid']] as $state) {
            $shoot->update($state);
            if ($state['payment_status'] === 'paid') {
                $file->update(['workflow_stage' => ShootFile::STAGE_TODO]);
            }
            $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/studio/workspaces?page=1')->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertForbidden();
            $this->getJson('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertForbidden();
        }
        $file->update(['workflow_stage' => ShootFile::STAGE_COMPLETED]);
        $this->getJson('/api/studio/workspaces?page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertOk();
    }

    public function test_reading_owned_upload_outputs_does_not_require_original_file_to_still_exist(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'editor']);
        $workspace = $this->workspace($owner, 'listing-ready', [['id' => 'p', 'mediaRef' => "studio/uploads/{$owner->id}/{$owner->id}/removed.jpg"]]);
        $this->setOutput($workspace);
        Sanctum::actingAs($owner);
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertOk();
        $this->get('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertOk();
    }

    public function test_large_library_authorizes_bounded_batches_and_retains_exact_page_totals(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'team_id' => $owner->id, 'created_by' => $owner->id,
                'name' => 'Edit '.$i, 'preset_id' => 'listing-ready', 'media' => '[]', 'config' => '{}',
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('studio_workspaces')->insert($rows);
        Sanctum::actingAs($owner);
        DB::enableQueryLog();
        try {
            $this->getJson('/api/studio/workspaces?page=103&per_page=2')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.total', 205)->assertJsonPath('meta.last_page', 103)->assertJsonPath('meta.has_more', false);
            $snapshots = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'select "id", "shoot_id", "media", "access_media"'));
            $this->assertCount(3, $snapshots);
            $this->assertTrue($snapshots->every(fn ($entry) => str_contains($entry['query'], 'limit 100')));
        } finally {
            DB::disableQueryLog();
        }
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(100, 'data')->assertJsonMissingPath('meta');
    }

    public function test_replacing_sources_keeps_prior_result_permissions_and_rejects_client_provenance_override(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'editor']);
        $firstShoot = Shoot::factory()->create(['editor_id' => $owner->id]);
        $nextShoot = Shoot::factory()->create(['editor_id' => $owner->id]);
        $first = $this->sourceFile($firstShoot, $owner);
        $next = $this->sourceFile($nextShoot, $owner);
        $workspace = $this->completedWorkspace($owner, $first);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/studio/workspaces/'.$workspace->id, [
            'media' => [['id' => 'p', 'fileId' => $next->id, 'shootId' => $nextShoot->id]], 'access_media' => [], 'config' => ['access_media' => []],
        ])->assertOk()->assertJsonPath('data.media.0.fileId', $next->id)->assertJsonCount(1, 'data.outputs')->assertJsonMissingPath('data.access_media');
        $retained = $workspace->fresh()->access_media;
        $this->assertSame($first->id, $retained[0]['fileId']);
        $this->assertArrayNotHasKey('access_media', $workspace->fresh()->toArray());
        $this->getJson('/api/studio/workspaces?shootId='.$firstShoot->id.'&page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $firstShoot->update(['editor_id' => null]);
        $this->getJson('/api/studio/workspaces?page=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertForbidden();
        $this->getJson('/api/studio/workspaces/'.$workspace->id.'/outputs/result/download')->assertForbidden();
        $this->assertSame($next->id, $workspace->fresh()->media[0]['fileId']);
        $this->assertCount(1, $workspace->fresh()->outputs);
    }

    private function sourceFile(Shoot $shoot, User $owner): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'source.jpg', 'stored_filename' => 'source.jpg', 'path' => 'source.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $owner->id,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'media_type' => 'edited',
        ]);
    }

    private function completedWorkspace(User $owner, ShootFile $file): StudioWorkspace
    {
        $workspace = $this->workspace($owner, 'listing-ready', [['id' => 'p', 'fileId' => $file->id, 'shootId' => $file->shoot_id]]);
        $this->setOutput($workspace);

        return $workspace;
    }

    private function setOutput(StudioWorkspace $workspace): void
    {
        $path = 'studio/outputs/'.$workspace->id.'/result.jpg';
        Storage::disk('public')->put($path, 'result fixture');
        $workspace->update(['status' => 'completed', 'outputs' => [['id' => 'result', 'status' => 'completed', 'path' => $path, 'url' => Storage::disk('public')->url($path), 'kind' => 'image', 'mediaId' => 'p']]]);
    }

    private function workspace(User $owner, string $preset, array $media): StudioWorkspace
    {
        return StudioWorkspace::create([
            'team_id' => $owner->id, 'created_by' => $owner->id, 'name' => 'Saved '.$preset,
            'preset_id' => $preset, 'media' => $media, 'config' => [], 'status' => 'draft',
        ]);
    }
}
