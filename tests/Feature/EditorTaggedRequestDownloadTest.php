<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootIssueParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorTaggedRequestDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_editor_can_download_and_resolve_tagged_edited_photos_without_other_media_access(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.download_offload' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false]);
        $editor = User::factory()->create(['role' => 'editor']);
        $owner = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $owner->id]);
        $files = collect(['tagged', 'unrelated', 'specific'])->map(function ($name) use ($shoot, $owner) {
            $path = "shoots/{$shoot->id}/edited/{$name}.jpg";
            Storage::disk('local')->put($path, 'photo-'.$name);
            return ShootFile::create(['shoot_id' => $shoot->id, 'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg',
                'path' => $path, 'storage_path' => $path, 'mime_type' => 'image/jpeg', 'file_type' => 'image/jpeg',
                'file_size' => 12, 'uploaded_by' => $owner->id, 'workflow_stage' => 'verified', 'scan_status' => 'clean']);
        });
        $parser = app(ShootIssueParsingService::class);
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Green grass', [$files[0]->id], 'team', 'open', 'editor'));
        $parser->appendIssueRequest($shoot, $parser->buildRequestEntry($shoot->client, 'Specific editor', [$files[2]->id], 'specific', 'open', 'editor', $owner->id));
        Sanctum::actingAs($editor);
        $this->assertFalse(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canScheduleShoot($shoot, $editor));
        $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_open_request', true);
        $response = $this->get("/api/shoots/{$shoot->id}/media/{$files[0]->id}/download")->assertOk();
        $this->assertSame('photo-tagged', $response->streamedContent());
        foreach ([$files[1], $files[2]] as $file) {
            $this->get("/api/shoots/{$shoot->id}/media/{$file->id}/download")->assertForbidden();
        }
        $this->patchJson("/api/shoots/{$shoot->id}/issues/specific", ['status' => 'resolved'])->assertNotFound();
        $this->patchJson("/api/shoots/{$shoot->id}/issues/team", ['status' => 'resolved'])->assertOk();
        $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->assertJsonPath('data.0.has_open_request', false);
        $this->patchJson("/api/shoots/{$shoot->id}/issues/team", ['status' => 'dismissed'])->assertOk();
        $this->get("/api/shoots/{$shoot->id}/media/{$files[0]->id}/download")->assertForbidden();
    }
}
