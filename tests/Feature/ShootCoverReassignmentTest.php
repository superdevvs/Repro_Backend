<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting the cover image must not leave the shoot advertising it.
 *
 * Meeting 26 Jul 2026 [00:25:18] and the A1.docx "delivered shoot with no media"
 * case: removing the cover left `is_cover` unset on every remaining file and
 * `shoots.hero_image` still holding the deleted file's URL, so listings rendered
 * a broken thumbnail.
 */
class ShootCoverReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private ShootMediaMutationSupportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->service = app(ShootMediaMutationSupportService::class);
    }

    private User $uploader;

    private function makeShoot(): Shoot
    {
        $client = User::factory()->create(['role' => 'client']);
        $this->uploader = User::factory()->create(['role' => 'photographer']);

        return Shoot::factory()->create([
            'client_id' => $client->id,
            'hero_image' => 'https://cdn.test/storage/shoots/cover.jpg',
        ]);
    }

    /** No ShootFile factory exists in this project, so rows are built directly. */
    private function makeFile(Shoot $shoot, array $attributes = []): ShootFile
    {
        static $counter = 0;
        $counter++;

        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'uploaded_by' => $this->uploader->id,
            'filename' => "shot-{$counter}.jpg",
            'stored_filename' => "shot-{$counter}.jpg",
            'path' => "shoots/{$shoot->id}/shot-{$counter}.jpg",
            'storage_path' => "shoots/{$shoot->id}/shot-{$counter}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'image',
            'file_size' => 1024,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'is_cover' => false,
        ], $attributes));
    }

    public function test_deleting_the_cover_promotes_another_deliverable_image(): void
    {
        if (! Schema::hasColumn('shoot_files', 'is_cover')) {
            $this->markTestSkipped('is_cover column not present in this schema.');
        }

        $shoot = $this->makeShoot();
        $cover = $this->makeFile($shoot, ['is_cover' => true, 'sort_order' => 1]);
        $next = $this->makeFile($shoot, ['sort_order' => 2]);
        $later = $this->makeFile($shoot, ['sort_order' => 3]);

        $this->service->deleteFile($shoot, $cover);

        $this->assertTrue((bool) $next->fresh()->is_cover, 'The next image in order should become the cover.');
        $this->assertFalse((bool) $later->fresh()->is_cover, 'Only one file should hold the cover.');
    }

    public function test_it_prefers_a_delivered_image_over_an_unfinished_one(): void
    {
        if (! Schema::hasColumn('shoot_files', 'is_cover')) {
            $this->markTestSkipped('is_cover column not present in this schema.');
        }

        $shoot = $this->makeShoot();
        $cover = $this->makeFile($shoot, ['is_cover' => true, 'sort_order' => 1]);
        // Earlier in sort order but not delivered — a client may not see it.
        $pending = $this->makeFile($shoot, ['sort_order' => 2, 'workflow_stage' => ShootFile::STAGE_TODO]);
        $delivered = $this->makeFile($shoot, ['sort_order' => 5, 'workflow_stage' => ShootFile::STAGE_VERIFIED]);

        $this->service->deleteFile($shoot, $cover);

        $this->assertTrue((bool) $delivered->fresh()->is_cover, 'A verified image should win over an unfinished one.');
        $this->assertFalse((bool) $pending->fresh()->is_cover);
    }

    public function test_it_clears_the_cached_hero_image_url(): void
    {
        $shoot = $this->makeShoot();
        $cover = $this->makeFile($shoot, ['is_cover' => true]);
        $this->makeFile($shoot);

        $this->service->deleteFile($shoot, $cover);

        // The presenter only recomputes hero_image when it is empty, so a stale
        // value here is what produced the broken thumbnail.
        $this->assertNull($shoot->fresh()->hero_image);
    }

    public function test_deleting_the_only_image_leaves_no_cover_and_no_hero(): void
    {
        $shoot = $this->makeShoot();
        $cover = $this->makeFile($shoot, ['is_cover' => true]);

        $this->service->deleteFile($shoot, $cover);

        $this->assertSame(0, $shoot->files()->count());
        $this->assertNull($shoot->fresh()->hero_image);
    }

    public function test_deleting_a_non_cover_file_leaves_the_cover_alone(): void
    {
        if (! Schema::hasColumn('shoot_files', 'is_cover')) {
            $this->markTestSkipped('is_cover column not present in this schema.');
        }

        $shoot = $this->makeShoot();
        $cover = $this->makeFile($shoot, ['is_cover' => true]);
        $other = $this->makeFile($shoot);

        $this->service->deleteFile($shoot, $other);

        $this->assertTrue((bool) $cover->fresh()->is_cover);
        // Untouched cover means the cached URL is still valid.
        $this->assertSame('https://cdn.test/storage/shoots/cover.jpg', $shoot->fresh()->hero_image);
    }
}
