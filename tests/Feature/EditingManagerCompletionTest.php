<?php

namespace Tests\Feature;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Jobs\ProcessMediaVersion;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\ShootMediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditingManagerCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['clamav.scan_on_upload' => true, 'media.local_disk' => 'local', 'media.tiered_storage_enabled' => false,
            'media.dual_write' => false, 'media.r2_only' => false, 'media.read_from_r2' => false]);
    }

    public function test_replacement_scan_failure_keeps_old_bytes_and_identity(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        $version = $this->stageReplacement($shoot);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andThrow(new ClamAvUnavailable('Scanner unavailable')));
        try {
            app()->call([new ProcessMediaVersion($version->id), 'handle']);
            $this->fail('Unavailable scanner must not replace a live file.');
        } catch (ClamAvUnavailable $error) {
            $this->assertSame('Scanner unavailable', $error->getMessage());
        }
        $this->assertSame('shoots/old.mp4', $file->fresh()->path);
        $this->assertSame($this->videoBytes(), Storage::disk('local')->get('shoots/old.mp4'));
        $this->assertSame(1, $file->fresh()->content_version);
        $this->assertDatabaseMissing('shoot_file_versions', ['published_file_id' => $file->id]);
        $this->assertSame($this->videoBytes('replacement'), app(MediaStorage::class)->get($version->snapshot['path']));
        $this->assertCount(1, $shoot->files()->get());
    }

    public function test_verified_replacement_keeps_identity_and_archives_old_bytes_after_publication(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        $version = $this->stageReplacement($shoot);
        $this->assertSame('shoots/old.mp4', $file->fresh()->path);
        $this->assertSame(1, $file->fresh()->content_version);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::clean()));
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        $current = $file->fresh();
        $this->assertSame($file->id, $version->fresh()->published_file_id);
        $this->assertSame(7, $current->sort_order);
        $this->assertSame('clean', $current->scan_status);
        $this->assertSame('verified', $current->workflow_stage);
        $this->assertSame(2, $current->content_version);
        $this->assertSame($current->path, $current->storage_path);
        $this->assertSame($this->videoBytes('replacement'), app(MediaStorage::class)->get($current->path));
        $this->assertSame($this->videoBytes(), Storage::disk('local')->get('shoots/old.mp4'));
        $this->assertDatabaseHas('shoot_file_versions', ['published_file_id' => $file->id, 'version' => 1, 'status' => 'archived']);
        $this->assertCount(1, $shoot->files()->get());
    }

    public function test_failed_publication_write_rolls_back_identity_and_preserves_previous_and_staged_bytes(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        $version = $this->stageReplacement($shoot);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::clean()));
        ShootFile::saving(function (ShootFile $candidate) use ($file) {
            if ($candidate->id === $file->id && $candidate->isDirty('path')) {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        try {
            app()->call([new ProcessMediaVersion($version->id), 'handle']);
            $this->fail('The write must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated write failure', $error->getMessage());
        }
        $this->assertSame('shoots/old.mp4', $file->fresh()->path);
        $this->assertSame(1, $file->fresh()->content_version);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame($this->videoBytes(), Storage::disk('local')->get('shoots/old.mp4'));
        $this->assertSame('ready', $version->fresh()->status);
        $this->assertNull($version->fresh()->published_file_id);
        $this->assertDatabaseMissing('shoot_file_versions', ['published_file_id' => $file->id]);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertSame($this->videoBytes('replacement'), app(MediaStorage::class)->get($version->snapshot['path']));
        $this->assertEqualsCanonicalizing(['shoots/old.mp4', $version->snapshot['path']], Storage::disk('local')->allFiles());
    }

    public function test_editing_manager_catalogue_preview_and_send_guard_agree(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $shoot = Shoot::factory()->create();
        $this->actingAs($manager, 'sanctum')->getJson('/api/messaging/notifications/catalogue?shoot_id='.$shoot->id)->assertOk();
        app(ManualNotificationService::class);
        MessageTemplate::updateOrCreate(['slug' => 'shoot-updated', 'channel' => 'EMAIL'], [
            'slug' => 'shoot-updated', 'channel' => 'EMAIL', 'name' => 'Updated', 'scope' => 'SYSTEM',
            'is_system' => true, 'is_active' => true, 'subject' => 'Updated',
            'body_html' => '<p>{{dashboard_link}} {{required_detail}}</p>',
            'variables_json' => ['dashboard_link', 'required_detail'],
        ]);
        $this->mock(MessagingService::class)->shouldNotReceive('sendEmail');
        $catalogue = $this->actingAs($manager, 'sanctum')->getJson('/api/messaging/notifications/catalogue?shoot_id='.$shoot->id)->assertOk();
        $types = array_column($catalogue->json('notifications'), 'type');
        foreach (['shoot_updated', 'shoot_delivered', 'shoot_request_approved', 'photographer_assigned', 'property_contact_reminder', 'refund_submitted'] as $type) {
            $this->assertContains($type, $types);
        }
        $payload = ['shoot_id' => $shoot->id, 'type' => 'shoot_updated', 'recipient_type' => 'client', 'channel' => 'email'];
        $this->postJson('/api/messaging/notifications/manual-preview', $payload)->assertOk()
            ->assertJsonPath('can_send', false)->assertJsonPath('missing_required', ['required_detail']);
        $this->postJson('/api/messaging/notifications/manual-send', $payload)->assertUnprocessable();
    }

    public function test_preview_and_send_reject_every_unsupported_catalogue_selection(): void
    {
        $sender = User::factory()->create(['role' => 'editing_manager']);
        $shoot = Shoot::factory()->create();
        $this->mock(MessagingService::class)->shouldNotReceive('sendEmail', 'sendSms');
        $service = app(ManualNotificationService::class);
        foreach (ManualNotificationService::CATALOGUE as $type => $meta) {
            foreach (['client', 'photographer', 'rep'] as $recipient) {
                foreach (['email', 'sms'] as $channel) {
                    if (in_array($recipient, $meta['recipients'], true) && in_array($channel, $meta['channels'], true)) {
                        continue;
                    }
                    foreach (['preview', 'send'] as $action) {
                        try {
                            if ($action === 'preview') {
                                $service->preview($shoot, $type, $recipient, $channel);
                            } else {
                                $service->send($shoot, $type, $recipient, $channel, $sender);
                            }
                            $this->fail("Unsupported {$type}/{$recipient}/{$channel} reached {$action}.");
                        } catch (\Illuminate\Validation\ValidationException $error) {
                            $this->assertArrayHasKey('notification', $error->errors());
                        }
                    }
                }
            }
        }
    }

    public function test_editing_manager_can_rename_reclassify_and_delete_before_and_after_delivery(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        foreach (['editing', 'delivered'] as $status) {
            $shoot = Shoot::factory()->create(['status' => $status, 'workflow_status' => $status]);
            $file = $this->savedFile($shoot);
            $file->update(['filename' => 'floorplan.jpg', 'media_type' => 'floorplan', 'file_type' => 'image/jpeg']);
            $this->actingAs($manager, 'sanctum');
            $this->patchJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/rename', ['filename' => 'living_web.jpg'])->assertOk();
            $this->assertSame('living_web.jpg', $file->fresh()->filename);
            $this->patchJson('/api/shoots/'.$shoot->id.'/files/reclassify', ['file_ids' => [$file->id], 'media_type' => 'photos'])->assertOk();
            $this->assertSame('edited', $file->fresh()->media_type);
            $this->assertSame(ShootFile::STAGE_VERIFIED, $file->fresh()->workflow_stage);
            $this->deleteJson('/api/shoots/'.$shoot->id.'/media/'.$file->id)->assertSuccessful();
            $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        }
    }

    private function savedFile(Shoot $shoot): ShootFile
    {
        Storage::disk('local')->put('shoots/old.mp4', $this->videoBytes());
        return ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'saved.mp4', 'stored_filename' => 'old.mp4', 'path' => 'shoots/old.mp4',
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'media_type' => 'video', 'file_type' => 'video/mp4',
            'file_size' => strlen($this->videoBytes()), 'uploaded_by' => $shoot->client_id, 'scan_status' => 'clean', 'sort_order' => 7,
        ]);
    }

    private function stageReplacement(Shoot $shoot): ShootFileVersion
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $saved = app(ShootMediaStorageService::class)->uploadToCompleted($shoot,
            UploadedFile::fake()->createWithContent('saved.mp4', $this->videoBytes('replacement')), $manager->id);
        $this->assertNotNull($saved->pendingMediaVersionId);
        return ShootFileVersion::findOrFail($saved->pendingMediaVersionId);
    }

    private function videoBytes(string $label = ''): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/media/replacement-video.mp4').pack('N', 8 + strlen($label)).'free'.$label;
    }
}
