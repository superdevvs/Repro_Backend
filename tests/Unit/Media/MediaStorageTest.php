<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    private function makeStorage(): MediaStorage
    {
        Storage::fake('public');
        Storage::fake('media');
        config()->set('media.local_disk', 'public');
        config()->set('media.remote_disk', 'media');

        return new MediaStorage();
    }

    public function test_normalize_key_strips_storage_prefix_and_leading_slashes(): void
    {
        $media = $this->makeStorage();

        $this->assertSame('shoots/1/todo/a.jpg', $media->normalizeKey('shoots/1/todo/a.jpg'));
        $this->assertSame('shoots/1/todo/a.jpg', $media->normalizeKey('/shoots/1/todo/a.jpg'));
        $this->assertSame('shoots/1/floorplans/a.jpg', $media->normalizeKey('storage/shoots/1/floorplans/a.jpg'));
        $this->assertNull($media->normalizeKey(null));
        $this->assertNull($media->normalizeKey('storage/'));
    }

    public function test_put_writes_local_only_when_flags_off(): void
    {
        $media = $this->makeStorage();
        config()->set('media.dual_write', false);
        config()->set('media.r2_only', false);

        $media->put('shoots/1/todo/a.jpg', 'data');

        Storage::disk('public')->assertExists('shoots/1/todo/a.jpg');
        Storage::disk('media')->assertMissing('shoots/1/todo/a.jpg');
    }

    public function test_put_mirrors_to_r2_when_dual_write_on(): void
    {
        $media = $this->makeStorage();
        config()->set('media.dual_write', true);
        config()->set('media.r2_only', false);

        $media->put('shoots/1/todo/a.jpg', 'data');

        Storage::disk('public')->assertExists('shoots/1/todo/a.jpg');
        Storage::disk('media')->assertExists('shoots/1/todo/a.jpg');
    }

    public function test_put_writes_r2_only_when_r2_only_on(): void
    {
        $media = $this->makeStorage();
        config()->set('media.dual_write', false);
        config()->set('media.r2_only', true);

        $media->put('shoots/1/todo/a.jpg', 'data');

        Storage::disk('public')->assertMissing('shoots/1/todo/a.jpg');
        Storage::disk('media')->assertExists('shoots/1/todo/a.jpg');
    }

    public function test_copy_local_to_r2_uses_identical_key(): void
    {
        $media = $this->makeStorage();
        Storage::disk('public')->put('shoots/2/web/b.jpg', 'web-bytes');

        $this->assertTrue($media->copyLocalToR2('shoots/2/web/b.jpg'));

        $this->assertSame('web-bytes', Storage::disk('media')->get('shoots/2/web/b.jpg'));
    }

    public function test_exists_prefers_r2_when_reads_flipped(): void
    {
        $media = $this->makeStorage();
        config()->set('media.read_from_r2', true);

        Storage::disk('media')->put('shoots/3/todo/c.jpg', 'x');

        $this->assertTrue($media->exists('shoots/3/todo/c.jpg'));
        $this->assertTrue($media->exists('storage/shoots/3/todo/c.jpg'));
    }

    public function test_get_falls_back_to_local_while_coexisting(): void
    {
        $media = $this->makeStorage();
        config()->set('media.read_from_r2', true);
        config()->set('media.r2_only', false);

        Storage::disk('public')->put('shoots/4/todo/d.jpg', 'local-only');

        $this->assertSame('local-only', $media->get('shoots/4/todo/d.jpg'));
    }
}
