<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
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
        config(['clamav.scan_on_upload' => true]);
    }

    public function test_replacement_scan_failure_keeps_old_bytes_and_identity(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        $service = $this->storageService('unavailable');
        try {
            $service->uploadToCompleted($shoot, UploadedFile::fake()->create('saved.mp4', 1, 'video/mp4'), $shoot->client_id);
            $this->fail('Unavailable scanner must not replace a live file.');
        } catch (\App\Exceptions\MediaReplacementUnavailable $error) {
            $this->assertStringContainsString('previous file is unchanged', $error->getMessage());
        }
        $this->assertSame('shoots/old.mp4', $file->fresh()->path);
        Storage::disk('local')->assertExists('shoots/old.mp4');
        $this->assertCount(1, $shoot->files()->get());
    }

    public function test_verified_replacement_keeps_identity_and_deletes_old_bytes_only_after_commit(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        $result = $this->storageService('clean')->uploadToCompleted($shoot, UploadedFile::fake()->create('saved.mp4', 1, 'video/mp4'), $shoot->client_id);
        $this->assertSame($file->id, $result->id);
        $this->assertSame(7, $result->fresh()->sort_order);
        $this->assertSame('clean', $result->fresh()->scan_status);
        Storage::disk('local')->assertExists($result->path);
        Storage::disk('local')->assertMissing('shoots/old.mp4');
    }

    public function test_failed_record_write_preserves_previous_file(): void
    {
        $shoot = Shoot::factory()->create();
        $file = $this->savedFile($shoot);
        ShootFile::saving(function (ShootFile $candidate) use ($file) {
            if ($candidate->id === $file->id && $candidate->isDirty('path')) {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        try {
            $this->storageService('clean')->uploadToCompleted($shoot, UploadedFile::fake()->create('saved.mp4', 1, 'video/mp4'), $shoot->client_id);
            $this->fail('The write must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated write failure', $error->getMessage());
        }
        $this->assertSame('shoots/old.mp4', $file->fresh()->path);
        $this->assertSame(['shoots/old.mp4'], Storage::disk('local')->allFiles());
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
        Storage::disk('local')->put('shoots/old.mp4', 'previous media');
        return ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'saved.mp4', 'stored_filename' => 'old.mp4', 'path' => 'shoots/old.mp4',
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'media_type' => 'video', 'file_type' => 'video/mp4',
            'file_size' => 14, 'uploaded_by' => $shoot->client_id, 'scan_status' => 'clean', 'sort_order' => 7,
        ]);
    }

    private function storageService(string $verdict): ShootMediaStorageService
    {
        return new class($verdict) extends ShootMediaStorageService {
            public function __construct(private readonly string $verdict) { parent::__construct(); }
            protected function scanUploadSynchronously(UploadedFile $file): string { return $this->verdict; }
            protected function extractImageMetadata(UploadedFile $file): array { return []; }
        };
    }
}
