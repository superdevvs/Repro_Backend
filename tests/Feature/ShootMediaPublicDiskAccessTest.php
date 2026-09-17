<?php

namespace Tests\Feature;

use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootMediaPublicDiskAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake([ScanShootFileJob::class]);
        config()->set('media.dual_write', false);
        config()->set('media.read_from_r2', false);
        config()->set('media.r2_only', false);
    }

    public function test_guest_cannot_read_shoot_photo_bytes_from_the_public_storage_alias(): void
    {
        $shoot = Shoot::factory()->create(['payment_status' => 'unpaid', 'bypass_paywall' => false]);
        $path = "shoots/{$shoot->id}/completed/secret.jpg";
        Storage::disk('public')->put($path, 'leaked-public-bytes');
        Storage::disk('local')->put($path, 'private-disk-bytes');

        $this->createShootFile($shoot, $path);

        $response = $this->get("/storage/{$path}");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsString('leaked-public-bytes', $response->getContent());
        $this->assertStringNotContainsString('private-disk-bytes', $response->getContent());
    }

    public function test_new_shoot_uploads_are_not_written_to_the_public_disk(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['payment_status' => 'paid']);

        $file = app(ShootMediaStorageService::class)->uploadToTodo(
            $shoot,
            UploadedFile::fake()->image('photo.jpg', 400, 300),
            $owner->id
        );

        Storage::disk('public')->assertMissing($file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_authorized_photographer_and_admin_can_fetch_private_disk_media(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $path = "shoots/{$shoot->id}/completed/front.jpg";
        Storage::disk('local')->put($path, 'owner-secret-bytes');
        $file = $this->createShootFile($shoot, $path);

        Sanctum::actingAs($photographer);
        $photographerPreview = $this->get("/api/shoots/{$shoot->id}/files/{$file->id}/preview");
        $photographerPreview->assertOk();
        $this->assertSame('owner-secret-bytes', $this->previewBytes($photographerPreview));

        Sanctum::actingAs($admin);
        $adminPreview = $this->get("/api/shoots/{$shoot->id}/files/{$file->id}/preview");
        $adminPreview->assertOk();
        $this->assertSame('owner-secret-bytes', $this->previewBytes($adminPreview));

        Sanctum::actingAs($client);
        $clientPreview = $this->get("/api/shoots/{$shoot->id}/files/{$file->id}/preview");
        $clientPreview->assertOk();
        $this->assertSame('owner-secret-bytes', $this->previewBytes($clientPreview));
    }

    public function test_revoked_and_expired_share_links_cannot_fetch_zip_bytes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create([
            'editor_id' => $editor->id,
            'address' => '123 Main St',
            'city' => 'Towson',
            'state' => 'MD',
            'zip' => '21204',
            'scheduled_date' => '2026-05-14',
        ]);
        $path = "shoots/{$shoot->id}/todo/raw.jpg";
        Storage::disk('local')->put($path, 'raw-share-bytes');
        $this->createShootFile($shoot, $path, [
            'filename' => 'raw.jpg',
            'stored_filename' => 'raw.jpg',
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        Sanctum::actingAs($editor);
        $created = app(ShootShareLinkService::class)->createShootShareLink($shoot, $editor);
        $link = ShootShareLink::query()->findOrFail($created['share_link_id']);

        Storage::disk('public')->assertMissing($link->dropbox_path);
        Storage::disk('local')->assertExists($link->dropbox_path);

        $alias = $this->get("/storage/{$link->dropbox_path}");
        $this->assertContains($alias->status(), [403, 404]);
        $this->assertStringNotContainsString('PK', $alias->getContent());

        $valid = $this->get("/api/public/share-links/{$link->public_token}/download");
        $valid->assertOk();
        $this->assertStringContainsString('zip', strtolower((string) $valid->headers->get('content-type')));

        $link->revoke($editor->id);
        $this->getJson("/api/public/share-links/{$link->public_token}/download")
            ->assertStatus(410);

        $link->update([
            'is_revoked' => false,
            'revoked_at' => null,
            'revoked_by' => null,
            'expires_at' => now()->subMinute(),
        ]);
        $this->getJson("/api/public/share-links/{$link->public_token}/download")
            ->assertStatus(410);
    }

    public function test_unpaid_client_media_urls_are_not_public_storage_aliases_and_preview_uses_watermark_policy(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);
        $originalPath = "shoots/{$shoot->id}/completed/preview.jpg";
        $watermarkPath = "shoots/{$shoot->id}/watermarked/preview_web.jpg";
        Storage::disk('local')->put($originalPath, 'clean-original-bytes');
        Storage::disk('local')->put($watermarkPath, 'watermarked-preview-bytes');
        $file = $this->createShootFile($shoot, $originalPath, [
            'filename' => 'preview.jpg',
            'stored_filename' => 'preview.jpg',
            'web_path' => $originalPath,
            'watermarked_web_path' => $watermarkPath,
            'watermarked_thumbnail_path' => $watermarkPath,
            'watermarked_placeholder_path' => $watermarkPath,
        ]);

        Sanctum::actingAs($client);
        $payload = $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($payload['uses_watermark']);
        $this->assertNotEmpty($payload['url']);
        $this->assertStringContainsString('/api/', (string) $payload['url']);
        $this->assertStringNotContainsString('/storage/shoots/', (string) $payload['url']);
        $this->assertStringNotContainsString('/storage/shoots/', (string) ($payload['original_url'] ?? ''));
        $this->assertStringNotContainsString('/storage/shoots/', (string) ($payload['watermarked_web_path'] ?? ''));

        $preview = $this->get("/api/shoots/{$shoot->id}/files/{$file->id}/preview");
        $preview->assertOk();
        $this->assertSame('watermarked-preview-bytes', $this->previewBytes($preview));
        $this->assertStringNotContainsString('clean-original-bytes', $this->previewBytes($preview));
    }

    public function test_signed_file_route_serves_private_bytes_and_rejects_unsigned_requests(): void
    {
        $path = 'shoots/88/completed/front.jpg';
        Storage::disk('local')->put($path, 'signed-private-bytes');

        $unsigned = $this->get('/api/public/shoot-media/file/'.$path);
        $this->assertContains($unsigned->status(), [401, 403]);
        $this->assertStringNotContainsString('signed-private-bytes', $unsigned->getContent());

        $signed = app(\App\Services\Media\MediaStorage::class)->publicUrl($path);
        $this->assertStringContainsString('/api/public/shoot-media/file/shoots/88/completed/front.jpg', $signed);
        $this->assertStringNotContainsString('/storage/shoots/', $signed);

        $response = $this->get($signed);
        $response->assertOk();
        $this->assertSame('signed-private-bytes', $response->streamedContent());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createShootFile(Shoot $shoot, string $path, array $overrides = []): ShootFile
    {
        $filename = $overrides['filename'] ?? basename($path);

        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $overrides['stored_filename'] ?? $filename,
            'path' => $path,
            'file_type' => 'image/jpeg',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => User::factory()->create(['role' => 'admin'])->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'sort_order' => 0,
        ], $overrides));
    }

    private function previewBytes($response): string
    {
        $base = $response->baseResponse;
        if (method_exists($base, 'getFile') && $base->getFile()) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        if (method_exists($response, 'streamedContent')) {
            return (string) $response->streamedContent();
        }

        return (string) $response->getContent();
    }
}
