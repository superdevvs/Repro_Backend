<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExclusiveListingPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.read_from_r2' => false, 'media.r2_only' => false, 'media.download_offload' => false]);
    }

    private function listing(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'payment_status' => 'paid', 'total_quote' => 100, 'bypass_paywall' => false,
            'is_private_listing' => true, 'is_listing_hidden' => false,
            'hero_image' => 'https://private.invalid/original.jpg',
        ], $attributes));
    }

    private function photo(Shoot $shoot, string $name, array $attributes = []): ShootFile
    {
        $file = ShootFile::create(array_merge([
            'shoot_id' => $shoot->id, 'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg',
            'path' => "shoots/{$shoot->id}/completed/{$name}.jpg", 'file_type' => 'image/jpeg',
            'file_size' => 100, 'uploaded_by' => $shoot->client_id, 'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'grid_path' => "shoots/{$shoot->id}/grid/{$name}.jpg",
            'thumbnail_path' => "shoots/{$shoot->id}/thumbnails/{$name}.jpg",
        ], $attributes));
        Storage::disk('local')->put($file->path, 'private-original');
        Storage::disk('local')->put($file->grid_path, 'grid-'.$name);
        Storage::disk('local')->put($file->thumbnail_path, 'thumbnail-'.$name);
        return $file;
    }

    private function discover(User $viewer): array
    {
        $request = Request::create('/api/shoots', 'GET', ['private_listing' => '1', 'listing_scope' => 'all']);
        $request->setUserResolver(fn () => $viewer);
        $response = app(ShootListingService::class)->index($request, $viewer, function () {
            $this->fail('Discovery must not use the operational presenter.');
        });
        $this->assertSame(200, $response->status());
        return $response->getData(true)['data'];
    }

    public function test_discovery_serves_only_a_published_preview_and_keeps_private_fields_omitted(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $shoot = $this->listing();
        // Imported albums can begin with several floorplans; they are not covers.
        for ($i = 0; $i < 7; $i++) $this->photo($shoot, 'plan-'.$i, ['media_type' => 'floorplan']);
        $this->photo($shoot, 'hidden', ['is_cover' => true, 'is_hidden' => true]);
        $this->photo($shoot, 'quarantined', ['is_cover' => true, 'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->photo($shoot, 'raw', ['is_cover' => true, 'workflow_stage' => ShootFile::STAGE_TODO]);
        $cover = $this->photo($shoot, 'cover', ['is_cover' => true]);
        $this->photo($shoot, 'other');

        $item = $this->discover($viewer)[0];
        $url = $item['preview_image_url'];
        $this->assertStringContainsString('/tour-image/'.$shoot->id.'/'.$cover->id.'/grid/', $url);
        $first = $this->get($url)->assertOk()->assertStreamedContent('grid-cover');
        foreach (['files', 'client', 'payments', 'total_quote', 'payment_status', 'hero_image', 'units_exists'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertFalse($item['can_view_details']);
        $this->assertFalse($item['can_download_media']);
        $this->assertStringNotContainsString('private.invalid', json_encode($item));
        $this->get(str_replace('/grid/', '/original/', $url))->assertNotFound();
        ShootFile::whereKey($cover->id)->toBase()->update(['is_hidden' => true]);
        $this->get($url, ['If-None-Match' => $first->headers->get('ETag')])->assertNotFound();
    }

    public function test_missing_grid_uses_thumbnail_and_unpublished_media_never_falls_back_to_original(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $shoot = $this->listing();
        $file = $this->photo($shoot, 'front');
        Storage::disk('local')->delete($file->grid_path);
        $this->get($this->discover($viewer)[0]['preview_image_url'])
            ->assertOk()->assertStreamedContent('thumbnail-front');
        Storage::disk('local')->delete($file->thumbnail_path);
        $this->assertNull($this->discover($viewer)[0]['preview_image_url']);

        $locked = $this->listing(['payment_status' => 'unpaid']);
        $this->photo($locked, 'locked');
        $unitShoot = $this->listing();
        $unitShoot->units()->create(['client_key' => 'unit-test', 'label' => '1', 'kind' => 'unit']);
        $this->photo($unitShoot, 'unit');
        $hidden = $this->listing(['is_listing_hidden' => true]);
        $this->photo($hidden, 'hidden-listing');
        $draft = $this->listing(['workflow_status' => Shoot::STATUS_SCHEDULED]);
        $this->photo($draft, 'draft');
        $items = collect($this->discover($viewer))->keyBy('id');
        $this->assertNull($items[$locked->id]['preview_image_url']);
        $this->assertNull($items[$unitShoot->id]['preview_image_url']);
        $this->assertFalse($items->has($hidden->id));
        $this->assertFalse($items->has($draft->id));
    }

    public function test_preview_reads_are_batched_across_listings(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        for ($i = 0; $i < 4; $i++) {
            $shoot = $this->listing();
            $this->photo($shoot, 'front');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $items = $this->discover($viewer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(4, array_filter(array_column($items, 'preview_image_url')));
        $fileReads = array_filter($queries, fn ($entry) => str_contains($entry['query'], 'from "shoot_files"'));
        $this->assertCount(1, $fileReads, 'Preview files must be read in one bounded batch.');
        $this->assertStringContainsString('laravel_row', array_values($fileReads)[0]['query']);
    }
}
