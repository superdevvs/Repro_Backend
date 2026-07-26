<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for Studio Shoot source selection.
 *
 * Validates: Requirements 4.2, 4.3
 */
class StudioSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_URL = '/api/studio/shoots/search';

    public function test_editor_search_matches_property_identifier_address_and_id_only_within_self_scope(): void
    {
        $editor = $this->actor('editor', 41);
        $peer = $this->actor('editor', 41);
        $outsider = $this->actor('editor', 42);

        $own = $this->shoot($editor, [
            'address' => '812 Harbor View Avenue',
            'property_slug' => 'harbor-house-812',
            'mls_id' => 'MLS-812-HARBOR',
        ]);
        $this->shoot($peer, ['address' => '812 Harbor View Peer']);
        $this->shoot($outsider, ['address' => '812 Harbor View Outside']);

        Sanctum::actingAs($editor);

        foreach (['harbor-house', 'MLS-812', 'Harbor View Avenue', (string) $own->id] as $query) {
            $response = $this->getJson(self::SEARCH_URL . '?q=' . urlencode($query))->assertOk();
            $this->assertSame([$own->id], collect($response->json('data'))->pluck('id')->all());
            $response->assertJsonPath('meta.query', $query)->assertJsonPath('meta.total', 1);
        }
    }

    public function test_privileged_search_is_team_scoped_and_empty_query_returns_no_records(): void
    {
        $admin = $this->actor('admin', 50);
        $teammate = $this->actor('editor', 50);
        $outsider = $this->actor('editor', 51);
        $teamShoot = $this->shoot($teammate, ['address' => 'Team Scope Beacon']);
        $this->shoot($outsider, ['address' => 'Outside Scope Beacon']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::SEARCH_URL . '?q=Scope%20Beacon')->assertOk();
        $this->assertSame([$teamShoot->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertStringNotContainsString('Outside Scope Beacon', $response->getContent());

        $this->getJson(self::SEARCH_URL . '?q=')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_media_returns_only_clean_visible_sources_supported_by_each_workflow(): void
    {
        $editor = $this->actor('editor', 60);
        $shoot = $this->shoot($editor, ['address' => 'Workflow Media House']);
        $image = $this->file($shoot, $editor, 'source.jpg', 'image/jpeg');
        $raw = $this->file($shoot, $editor, 'source.nef', 'application/octet-stream');
        $video = $this->file($shoot, $editor, 'source.mp4', 'video/mp4', ['media_type' => 'video']);
        $this->file($shoot, $editor, 'floorplan.pdf', 'application/pdf');
        $this->file($shoot, $editor, 'hidden.jpg', 'image/jpeg', ['is_hidden' => true]);
        $this->file($shoot, $editor, 'infected.jpg', 'image/jpeg', [
            'scan_status' => ShootFile::SCAN_STATUS_INFECTED,
        ]);

        Sanctum::actingAs($editor);

        $photo = $this->getJson("/api/studio/shoots/{$shoot->id}/media?workflow=photo-enhancement")
            ->assertOk()
            ->assertJsonPath('meta.workflow', 'photo-enhancement')
            ->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing(
            [$image->id, $raw->id],
            collect($photo->json('data'))->pluck('id')->all()
        );

        $listing = $this->getJson("/api/studio/shoots/{$shoot->id}/media?workflow=listing-video")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->assertSame([$image->id], collect($listing->json('data'))->pluck('id')->all());

        $cleanup = $this->getJson("/api/studio/shoots/{$shoot->id}/media?workflow=video-cleanup")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.mediaType', 'video');
        $this->assertSame([$video->id], collect($cleanup->json('data'))->pluck('id')->all());

        foreach ($photo->json('data') as $media) {
            $this->assertSame($shoot->id, $media['shootId']);
            $this->assertNotSame('', $media['filename']);
            $this->assertStringContainsString(
                "/api/shoots/{$shoot->id}/files/{$media['id']}/preview",
                $media['previewUrl']
            );
        }
    }

    public function test_missing_and_out_of_scope_shoot_media_return_the_same_not_found_response(): void
    {
        $editor = $this->actor('editor', 70);
        $peer = $this->actor('editor', 70);
        $peerShoot = $this->shoot($peer, ['address' => 'Restricted Source House']);
        Sanctum::actingAs($editor);

        $unauthorized = $this->getJson(
            "/api/studio/shoots/{$peerShoot->id}/media?workflow=photo-enhancement"
        )->assertNotFound();
        $missing = $this->getJson(
            '/api/studio/shoots/999999/media?workflow=photo-enhancement'
        )->assertNotFound();

        $this->assertSame($missing->json(), $unauthorized->json());
        $this->assertStringNotContainsString('Restricted Source House', $unauthorized->getContent());
    }

    public function test_source_endpoints_reject_unauthenticated_and_disallowed_roles(): void
    {
        $this->getJson(self::SEARCH_URL . '?q=house')->assertUnauthorized();

        $client = $this->actor('client', 80);
        Sanctum::actingAs($client);

        $this->getJson(self::SEARCH_URL . '?q=house')->assertForbidden();
    }

    public function test_media_requires_a_supported_workflow_without_exposing_out_of_scope_shoots(): void
    {
        $editor = $this->actor('editor', 90);
        $shoot = $this->shoot($editor, ['address' => 'Validation House']);
        Sanctum::actingAs($editor);

        $this->getJson("/api/studio/shoots/{$shoot->id}/media?workflow=unknown")
            ->assertUnprocessable();
    }

    public function test_upload_partitions_mixed_files_and_persists_accepted_media(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $editor = $this->actor('editor', 101);
        Sanctum::actingAs($editor);

        $response = $this->post('/api/studio/uploads', [
            'workflow' => 'photo-enhancement',
            'files' => [
                \Illuminate\Http\UploadedFile::fake()->create('front.jpg', 100, 'image/jpeg'),
                \Illuminate\Http\UploadedFile::fake()->create('floorplan.pdf', 10, 'application/pdf'),
                \Illuminate\Http\UploadedFile::fake()->create('renamed.jpg', 10, 'text/plain'),
            ],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.acceptedCount', 1)
            ->assertJsonPath('meta.rejectedCount', 2)
            ->assertJsonPath('data.accepted.0.filename', 'front.jpg')
            ->assertJsonPath('data.accepted.0.mediaType', 'image')
            ->assertJsonPath('data.rejected.0.filename', 'floorplan.pdf')
            ->assertJsonPath('data.rejected.1.filename', 'renamed.jpg');

        $acceptedPath = $response->json('data.accepted.0.storagePath');
        $this->assertStringStartsWith("studio/uploads/101/{$editor->id}/", $acceptedPath);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($acceptedPath);

        $firstConstraints = collect($response->json('data.rejected.0.violations'))->pluck('constraint');
        $secondConstraints = collect($response->json('data.rejected.1.violations'))->pluck('constraint');
        $this->assertContains('extension', $firstConstraints);
        $this->assertContains('mime', $firstConstraints);
        $this->assertContains('mime', $secondConstraints);
    }

    public function test_upload_enforces_workflow_media_and_size_constraints(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $editor = $this->actor('editor', 102);
        Sanctum::actingAs($editor);

        $this->post('/api/studio/uploads', [
            'workflow' => 'photo-enhancement',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('source.nef', 10, 'application/octet-stream')],
        ])->assertCreated()
            ->assertJsonPath('data.accepted.0.mediaType', 'raw');

        $this->post('/api/studio/uploads', [
            'workflow' => 'listing-video',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('source.nef', 10, 'application/octet-stream')],
        ])->assertUnprocessable()
            ->assertJsonPath('data.rejected.0.filename', 'source.nef')
            ->assertJsonFragment(['constraint' => 'extension']);

        $this->post('/api/studio/uploads', [
            'workflow' => 'video-cleanup',
            'files' => [
                \Illuminate\Http\UploadedFile::fake()->create('walkthrough.mp4', 10, 'video/mp4'),
                \Illuminate\Http\UploadedFile::fake()->create('still.jpg', 10, 'image/jpeg'),
            ],
        ])->assertCreated()
            ->assertJsonPath('meta.acceptedCount', 1)
            ->assertJsonPath('meta.rejectedCount', 1)
            ->assertJsonPath('data.accepted.0.mediaType', 'video')
            ->assertJsonPath('data.rejected.0.filename', 'still.jpg');

        config(['studio_uploads.workflows.photo-enhancement.max_bytes' => 1024]);
        $this->post('/api/studio/uploads', [
            'workflow' => 'photo-enhancement',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('oversize.jpg', 2, 'image/jpeg')],
        ])->assertUnprocessable()
            ->assertJsonFragment(['constraint' => 'size', 'maxBytes' => 1024]);
    }

    public function test_upload_requires_authentication_and_an_allowed_studio_role(): void
    {
        $payload = [
            'workflow' => 'photo-enhancement',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg')],
        ];

        $this->post('/api/studio/uploads', $payload)->assertUnauthorized();

        Sanctum::actingAs($this->actor('client', 103));
        $this->post('/api/studio/uploads', $payload)->assertForbidden();
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function shoot(User $owner, array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => '100 Studio Source Street',
        ], $overrides));
    }

    private function file(
        Shoot $shoot,
        User $uploader,
        string $filename,
        string $mimeType,
        array $overrides = []
    ): ShootFile {
        return ShootFile::query()->create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => "shoots/{$shoot->id}/{$filename}",
            'storage_path' => "shoots/{$shoot->id}/{$filename}",
            'file_type' => $mimeType,
            'mime_type' => $mimeType,
            'file_size' => 1024,
            'uploaded_by' => $uploader->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'is_hidden' => false,
        ], $overrides));
    }
}
