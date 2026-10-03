<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaVersion;
use App\Models\{Shoot, ShootFile, ShootFileVersion, User};
use App\Services\Media\MediaStorage;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class DesktopEditingSecurityTest extends TestCase
{
    use RefreshDatabase;
    private User $manager;
    private Shoot $shoot;
    private ShootFile $file;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        config(['desktop_editing.installers' => array_fill_keys(['win32-x64', 'darwin-x64', 'darwin-arm64'], 'https://example.test/helper')]);
        Queue::fake(); Storage::fake('local'); Storage::fake('public');
        config(['desktop_editing.release_ready' => true, 'media.local_disk' => 'local', 'media.tiered_storage_enabled' => false,
            'media.dual_write' => false, 'media.r2_only' => false, 'media.read_from_r2' => false]);
        $this->manager = User::factory()->create(['role' => 'editing_manager']);
        $this->shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $image = UploadedFile::fake()->image('old.jpg', 32, 24);
        app(MediaStorage::class)->put('shoots/desktop/old.jpg', file_get_contents($image->getRealPath()));
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'kitchen.jpg', 'stored_filename' => 'old.jpg',
            'path' => 'shoots/desktop/old.jpg', 'file_size' => $image->getSize(), 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'workflow_stage' => 'verified', 'media_type' => 'edited', 'scan_status' => 'clean', 'uploaded_by' => $this->manager->id])->fresh();
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
    }

    private function pair(): array
    {
        $pair = $this->actingAs($this->manager)->postJson('/api/desktop-editing/pairings')->assertCreated()->json('data.id');
        $body = ['proof' => Str::random(64), 'name' => 'Test Mac', 'platform' => 'darwin-arm64'];
        $claim = $this->postJson("/api/edit-helper/pairings/{$pair}/claim", $body)->assertOk();
        $claim->assertJsonPath('data.credential', null);
        $this->postJson("/api/desktop-editing/pairings/{$pair}/approve", ['comparison_code' => $claim->json('data.comparison_code')])->assertOk();
        $token = $this->postJson("/api/edit-helper/pairings/{$pair}/claim", $body)->assertOk()->json('data.credential');
        $this->postJson("/api/edit-helper/pairings/{$pair}/claim", $body)->assertOk()->assertJsonPath('data.credential', $token);
        return [$pair, $body, $token];
    }

    public function test_pairing_requires_explicit_dashboard_approval_and_same_proof_and_expires(): void
    {
        [$pair, $body, $token] = $this->pair();
        $this->assertStringStartsWith('reproedit.', $token);
        $this->assertDatabaseCount('edit_helper_devices', 1);
        $this->postJson("/api/edit-helper/pairings/{$pair}/claim", array_replace($body, ['proof' => Str::random(64)]))->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson("/api/desktop-editing/pairings/{$pair}")->assertGone();
        $this->travel(6)->minutes();
        $this->postJson("/api/edit-helper/pairings/{$pair}/claim", $body)->assertGone();
    }

    public function test_device_is_limited_to_its_user_and_claimed_image_and_revocation_takes_effect(): void
    {
        [, , $token] = $this->pair();
        $session = $this->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/desktop-session", ['expected_version' => 1])->assertCreated();
        $id = $session->json('data.id');
        $this->assertSame('repro-edit://open/'.$id, $session->json('data.launch_url'));
        $this->withToken($token)->postJson("/api/edit-helper/sessions/{$id}/claim")->assertOk();
        $this->withToken($token)->getJson("/api/edit-helper/sessions/{$id}/file")->assertOk();
        DB::table('edit_helper_sessions')->where('id', $id)->update(['user_id' => User::factory()->create(['role' => 'admin'])->id]);
        $this->withToken($token)->getJson("/api/edit-helper/sessions/{$id}/file")->assertGone();
        DB::table('edit_helper_devices')->update(['revoked_at' => now()]);
        $this->withToken($token)->postJson('/api/edit-helper/heartbeat', ['photoshop_detected' => true, 'auto_upload' => false])->assertUnauthorized();
    }

    public function test_upload_is_versioned_idempotent_and_conflicting_save_does_not_replace_latest(): void
    {
        [, , $token] = $this->pair();
        $id = $this->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/desktop-session", ['expected_version' => 1])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/edit-helper/sessions/{$id}/claim")->assertOk();
        $body = ['file' => UploadedFile::fake()->image('export.jpg', 48, 32), 'request_id' => (string) Str::uuid()];
        $version = $this->withToken($token)->postJson("/api/edit-helper/sessions/{$id}/upload", $body)->assertAccepted()->json('data.id');
        $this->withToken($token)->postJson("/api/edit-helper/sessions/{$id}/upload", $body)->assertAccepted()->assertJsonPath('data.id', $version);
        Queue::assertPushed(ProcessMediaVersion::class, 1);
        $this->file->update(['content_version' => 2]);
        $priorPath = $this->file->path;
        app()->call([new ProcessMediaVersion($version), 'handle']);
        $this->withToken($token)->getJson("/api/edit-helper/sessions/{$id}/versions/{$version}")->assertOk()->assertJsonPath('data.status', 'conflict');
        $this->assertSame($priorPath, $this->file->fresh()->path);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
        $this->assertSame(1, (int) DB::table('edit_helper_sessions')->first()->expected_version);
    }

    public function test_unreleased_installers_and_wrong_roles_cannot_pair_or_launch(): void
    {
        config(['desktop_editing.release_ready' => false]);
        $this->actingAs($this->manager)->getJson('/api/desktop-editing')->assertOk()->assertJsonPath('data.available', false)->assertJsonCount(0, 'data.installers');
        $this->postJson('/api/desktop-editing/pairings')->assertConflict();
        $this->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/desktop-session", ['expected_version' => 1])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson('/api/desktop-editing')->assertForbidden();
    }
}
