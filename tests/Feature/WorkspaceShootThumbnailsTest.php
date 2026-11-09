<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceShootThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/workspaces/sources/shoots';

    protected function setUp(): void
    {
        parent::setUp();
        config(['studio.client_access_enabled' => true]);
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('media_local');
    }

    public function test_stored_cover_key_is_replaced_with_a_loadable_authenticated_thumbnail(): void
    {
        $actor = $this->actor();
        $shoot = $this->shoot($actor, ['hero_image' => 'shoots/example/web-v2/cover.jpg']);
        $this->photo($shoot, $actor);
        $cover = $this->photo($shoot, $actor, ['is_cover' => true, 'sort_order' => 20]);

        $url = $this->getJson(self::URL.'?page=1')->assertOk()->json('data.0.thumbnailUrl');
        $this->assertSame($this->previewURL($cover), $url);
        $preview = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private');
        $dimensions = getimagesizefromstring($preview->getContent());
        $this->assertSame([480, 360], [$dimensions[0], $dimensions[1]]);
        $this->assertSame('shoots/example/web-v2/cover.jpg', $shoot->fresh()->hero_image);
        $this->assertDatabaseCount('studio_workspaces', 0);
        Bus::assertNotDispatched(ProcessStudioWorkspace::class);
    }

    public function test_missing_cover_uses_a_completed_photo_and_preserves_the_source(): void
    {
        $actor = $this->actor();
        $shoot = $this->shoot($actor);
        $this->photo($shoot, $actor, ['filename' => 'capture.NEF', 'workflow_stage' => 'todo', 'sort_order' => 1]);
        $photo = $this->photo($shoot, $actor, ['sort_order' => 2]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.thumbnailUrl', $this->previewURL($photo));
        $this->assertNull($shoot->fresh()->hero_image);
        $this->assertFalse($photo->fresh()->is_cover);
    }

    public function test_hidden_quarantined_nonphotos_and_extras_do_not_become_fallback_covers(): void
    {
        $actor = $this->actor();
        $shoot = $this->shoot($actor);
        foreach ([
            ['is_hidden' => true],
            ['scan_status' => ShootFile::SCAN_STATUS_INFECTED],
            ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED],
            ['scan_status' => ShootFile::SCAN_STATUS_FAILED],
            ['is_extra' => true],
            ['filename' => 'floorplan.pdf', 'file_type' => 'application/pdf', 'mime_type' => 'application/pdf', 'media_type' => 'floorplan'],
            ['filename' => 'tour.mp4', 'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'media_type' => 'video'],
        ] as $attributes) {
            $this->photo($shoot, $actor, ['is_cover' => true, ...$attributes]);
        }
        $photo = $this->photo($shoot, $actor);
        $this->getJson(self::URL.'?page=1')->assertOk()->assertJsonPath('data.0.thumbnailUrl', $this->previewURL($photo));
    }

    public function test_raw_only_shoot_uses_the_existing_jpeg_preview_contract(): void
    {
        $actor = $this->actor();
        $shoot = $this->shoot($actor);
        $raw = $this->photo($shoot, $actor, [
            'filename' => 'camera.NEF', 'file_type' => 'application/octet-stream',
            'mime_type' => 'application/octet-stream', 'media_type' => 'raw', 'workflow_stage' => 'todo',
        ]);
        $url = $this->getJson(self::URL.'?page=1')->assertOk()->json('data.0.thumbnailUrl');
        $this->assertSame($this->previewURL($raw), $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_empty_or_nonphoto_only_shoot_does_not_expose_an_unmapped_stored_cover(): void
    {
        $actor = $this->actor();
        $shoot = $this->shoot($actor, ['hero_image' => 'https://example.test/unrelated-private-image.jpg']);
        $this->photo($shoot, $actor, ['filename' => 'document.pdf', 'file_type' => 'application/pdf', 'mime_type' => 'application/pdf']);
        $this->getJson(self::URL.'?page=1')->assertOk()->assertJsonPath('data.0.thumbnailUrl', null);
        Http::assertNothingSent();
    }

    public function test_editor_cover_selection_continues_past_another_editors_files_and_revocation_is_immediate(): void
    {
        $editor = $this->actor('editor');
        $peer = User::factory()->create(['role' => 'editor']);
        $shoot = $this->shoot($peer, ['editor_id' => null]);
        $ownService = Service::factory()->create();
        $peerService = Service::factory()->create();
        $shoot->services()->attach($ownService->id, ['editor_id' => $editor->id]);
        $shoot->services()->attach($peerService->id, ['editor_id' => $peer->id]);
        $ownLine = $shoot->serviceItems()->where('service_id', $ownService->id)->value('id');
        $peerLine = $shoot->serviceItems()->where('service_id', $peerService->id)->value('id');
        for ($index = 0; $index < 10; $index++) {
            $this->photo($shoot, $peer, ['shoot_service_id' => $peerLine, 'is_cover' => true]);
        }
        $own = $this->photo($shoot, $editor, ['shoot_service_id' => $ownLine]);
        $url = $this->getJson(self::URL.'?page=1')->assertOk()->json('data.0.thumbnailUrl');
        $this->assertSame($this->previewURL($own), $url);
        $this->get($url)->assertOk();
        $shoot->services()->updateExistingPivot($ownService->id, ['editor_id' => null]);
        $this->getJson(self::URL.'?page=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url)->assertForbidden();
    }

    public function test_client_cover_respects_ownership_release_and_payment_and_is_rechecked_on_image_load(): void
    {
        $client = $this->actor('client');
        $own = $this->shoot($client, ['payment_status' => 'paid']);
        $ownPhoto = $this->photo($own, $client);
        $locked = $this->shoot($client, ['payment_status' => 'unpaid']);
        $this->photo($locked, $client);
        $other = User::factory()->create(['role' => 'client']);
        $this->photo($this->shoot($other), $other);
        $rows = collect($this->getJson(self::URL.'?page=1')->assertOk()->json('data'))->keyBy('id');
        $this->assertCount(2, $rows);
        $this->assertSame($this->previewURL($ownPhoto), $rows[$own->id]['thumbnailUrl']);
        $this->assertNull($rows[$locked->id]['thumbnailUrl']);
        $this->get($rows[$own->id]['thumbnailUrl'])->assertOk();
        $ownPhoto->update(['is_hidden' => true]);
        $this->getJson($rows[$own->id]['thumbnailUrl'])->assertForbidden();
        $this->getJson(self::URL.'?q='.$own->id)->assertOk()->assertJsonPath('data.0.thumbnailUrl', null);
    }

    public function test_pagination_keeps_the_same_rows_and_does_not_start_image_processing(): void
    {
        $actor = $this->actor();
        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $shoot = $this->shoot($actor);
            $photo = $this->photo($shoot, $actor);
            $ids[$shoot->id] = $this->previewURL($photo);
        }
        $rows = $this->getJson(self::URL.'?page=2&per_page=1')->assertOk()
            ->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.next_page', 3)->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(array_keys($ids)[1], $rows[0]['id']);
        $this->assertSame(array_values($ids)[1], $rows[0]['thumbnailUrl']);
        $this->assertSame([], Storage::disk('local')->allFiles('studio/previews'));
        $this->assertDatabaseCount('studio_workspaces', 0);
        Bus::assertNotDispatched(ProcessStudioWorkspace::class);
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
            'created_by' => (string) $owner->id, 'address' => 'Source property', 'hero_image' => null,
            'status' => 'delivered', 'workflow_status' => 'delivered', 'payment_status' => 'paid',
            'bypass_paywall' => false, 'total_quote' => 100, 'updated_at' => now(),
        ], $attributes)));
    }

    private function photo(Shoot $shoot, User $uploader, array $attributes = []): ShootFile
    {
        $name = \Illuminate\Support\Str::uuid().'.jpg';
        $path = 'shoots/'.$shoot->id.'/'.$name;
        Storage::disk('public')->put($path, UploadedFile::fake()->image($name, 640, 480)->getContent());

        return ShootFile::create(array_replace([
            'shoot_id' => $shoot->id, 'filename' => $name, 'stored_filename' => $name,
            'path' => $path, 'storage_path' => $path, 'thumbnail_path' => $path, 'web_path' => $path,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 1024,
            'uploaded_by' => $uploader->id, 'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'is_hidden' => false, 'is_cover' => false, 'is_extra' => false, 'sort_order' => 1,
        ], $attributes));
    }

    private function previewURL(ShootFile $file): string
    {
        return url("/api/studio/workspaces/sources/files/{$file->id}/preview").'?size=thumbnail';
    }
}
