<?php

namespace Tests\Feature;

use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EditorDownloadArchiveAccessTest extends TestCase
{
    public function test_signed_editor_download_zip_is_streamed_with_content_length(): void
    {
        Storage::fake('local');
        config()->set('media.read_from_r2', false);
        config()->set('media.r2_only', false);
        config()->set('media.local_disk', 'local');
        config()->set('media.dual_write', false);

        $key = 'editor-downloads/145/test-archive.zip';
        $payload = 'PK'.str_repeat('RAWZIP', 1024);
        Storage::disk('local')->put($key, $payload);

        $url = (new MediaStorage())->signedAppUrl($key);
        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('Content-Length', (string) strlen($payload));
        $this->assertSame($payload, $response->streamedContent());
    }

    public function test_resolve_public_storage_url_uses_signed_editor_download_path(): void
    {
        config()->set('media.read_from_r2', false);
        config()->set('media.r2_only', false);

        $url = app(ShootFileAccessService::class)
            ->resolvePublicStorageUrl('editor-downloads/145/abc123.zip');

        $this->assertNotNull($url);
        $this->assertStringContainsString('/api/public/shoot-media/file/editor-downloads/145/abc123.zip', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('/storage/editor-downloads/', $url);
    }

    public function test_unsigned_or_non_allowed_prefix_is_rejected(): void
    {
        Storage::fake('local');
        config()->set('media.local_disk', 'local');
        Storage::disk('local')->put('avatars/secret.png', 'nope');

        $unsigned = URL::route('api.public.shoot-media.file', ['path' => 'editor-downloads/145/x.zip'], absolute: false);
        $this->get($unsigned)->assertForbidden();

        $signedAvatar = (new MediaStorage())->signedAppUrl('avatars/secret.png');
        $this->get($signedAvatar)->assertNotFound();
    }
}
