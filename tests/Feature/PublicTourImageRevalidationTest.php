<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\PublicTourImage;
use App\Services\Shoots\ShootPublicAssetsService;
use App\Services\LinkPreview\ImageSourceLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicTourImageRevalidationTest extends TestCase
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

    private function photo(): array
    {
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'payment_status' => 'paid', 'total_quote' => 100, 'bypass_paywall' => false,
        ]);
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'front.jpg', 'stored_filename' => 'front.jpg',
            'path' => "shoots/{$shoot->id}/completed/front.jpg", 'file_type' => 'image/jpeg',
            'file_size' => 100, 'uploaded_by' => $shoot->client_id,
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'web_path' => "shoots/{$shoot->id}/web/front.jpg",
            'grid_path' => "shoots/{$shoot->id}/grid/front.jpg",
            'thumbnail_path' => "shoots/{$shoot->id}/thumbnails/front.jpg",
        ]);
        foreach (['path' => 'original-bytes', 'web_path' => 'web-bytes', 'grid_path' => 'grid-bytes', 'thumbnail_path' => 'thumb-bytes'] as $column => $body) {
            Storage::disk('local')->put($file->$column, $body);
        }
        return [$shoot, $file, app(PublicTourImage::class)->url($file, 'web')];
    }

    public function test_published_derivatives_have_stable_urls_and_revalidate_without_a_body(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $this->travel(2)->minutes();
        $this->assertSame($url, app(PublicTourImage::class)->url($file->fresh(), 'web'));
        $first = $this->get($url)->assertOk();
        $this->assertStringContainsString('no-cache', $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
        $this->assertSame('web-bytes', $first->streamedContent());
        $this->get($url, ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304)->assertContent('');

        $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
        $this->assertSame([$url], $payload['photos']);
        $renditions = $payload['photo_renditions'][0];
        $this->assertSame($url, $renditions['url']);
        $this->assertSame($url, $renditions['web_url']);
        $this->get($renditions['thumbnail_url'])->assertOk()->assertStreamedContent('thumb-bytes');
        $this->get($renditions['grid_url'])->assertOk()->assertStreamedContent('grid-bytes');
    }

    public function test_conditional_requests_recheck_hide_payment_publication_scan_and_unit_gates(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        // Direct updates intentionally preserve updated_at: a revision check alone
        // is insufficient to enforce immediate revocation of an already cached URL.
        foreach ([['is_hidden' => true], ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED], ['workflow_stage' => ShootFile::STAGE_TODO]] as $change) {
            ShootFile::whereKey($file->id)->toBase()->update($change);
            $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
            ShootFile::whereKey($file->id)->toBase()->update(['is_hidden' => false, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_VERIFIED]);
        }
        Shoot::whereKey($shoot->id)->toBase()->update(['payment_status' => 'unpaid']);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
        Shoot::whereKey($shoot->id)->toBase()->update(['payment_status' => 'paid', 'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED]);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
        Shoot::whereKey($shoot->id)->toBase()->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $shoot->units()->create(['client_key' => 'unit-test', 'label' => '1', 'kind' => 'unit']);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
    }

    public function test_replacement_and_cross_shoot_requests_cannot_reuse_a_cached_revision(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        $file->update(['content_version' => 2]);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
        $replacement = app(PublicTourImage::class)->url($file->fresh(), 'web');
        $this->assertNotSame($url, $replacement);
        $this->get($replacement, ['If-None-Match' => $etag])->assertOk();
        $other = Shoot::factory()->create(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED, 'total_quote' => 0]);
        $this->get(str_replace('/tour-image/'.$shoot->id.'/', '/tour-image/'.$other->id.'/', $replacement))->assertNotFound();
        $this->get(str_replace('/web/', '/original/', $replacement))->assertNotFound();
        $file->update(['web_path' => $file->path]);
        $this->assertNull(app(PublicTourImage::class)->url($file->fresh(), 'web'));
        $file->update(['path' => 'https://reprodashboard.com/storage/'.$file->path]);
        $this->assertNull(app(PublicTourImage::class)->url($file->fresh(), 'web'));
    }

    public function test_workflow_only_revocation_overrides_a_stale_delivered_status(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        foreach ([Shoot::STATUS_EDITING, Shoot::STATUS_CANCELLED, Shoot::STATUS_ON_HOLD] as $workflow) {
            Shoot::whereKey($shoot->id)->toBase()->update(['workflow_status' => $workflow]);
            $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
            $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
            $this->assertStringNotContainsString('/public/tour-image/', $payload['photos'][0]);
        }
        // Historical delivered records without a workflow column value still
        // use their legacy status; explicit newer workflow values take priority.
        Shoot::whereKey($shoot->id)->toBase()->update(['workflow_status' => '']);
        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_generic_signed_media_keeps_no_store_and_new_route_never_exposes_an_import_draft(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $signed = app(MediaStorage::class)->signedAppUrl($file->path);
        $this->assertStringContainsString('no-store', $this->get($signed)->assertOk()->headers->get('Cache-Control'));
        Shoot::whereKey($shoot->id)->toBase()->update(['status' => Shoot::STATUS_IMPORT_DRAFT]);
        $this->get($url)->assertNotFound();
    }

    public function test_social_preview_reads_revalidated_image_urls_locally_and_respects_revocation(): void
    {
        [$shoot, $file, $url] = $this->photo();
        $this->assertSame('web-bytes', app(ImageSourceLoader::class)->load($url));
        $this->assertNull(app(ImageSourceLoader::class)->load('https://attacker.invalid'.parse_url($url, PHP_URL_PATH)));
        $this->assertNull(app(ImageSourceLoader::class)->load('http://localhost:9999'.parse_url($url, PHP_URL_PATH)));
        ShootFile::whereKey($file->id)->toBase()->update(['is_hidden' => true]);
        $this->assertNull(app(ImageSourceLoader::class)->load($url));
    }

    public function test_tour_json_revalidation_rebuilds_the_current_visible_payload(): void
    {
        [$shoot, $file] = $this->photo();
        $shoot->update(['total_quote' => 0]);
        $endpoint = '/api/public/shoots/'.$shoot->id.'/branded';
        $first = $this->getJson($endpoint)->assertOk()->assertJsonCount(1, 'photos');
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->travel(2)->minutes();
        $this->getJson($endpoint, ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        ShootFile::whereKey($file->id)->toBase()->update(['is_hidden' => true]);
        $this->getJson($endpoint, ['If-None-Match' => $etag])->assertOk()->assertJsonPath('photos', [])->assertJsonPath('photo_renditions', []);
    }

    public function test_offloaded_response_and_conditional_response_keep_revalidation_headers(): void
    {
        [$shoot, $file, $url] = $this->photo();
        config(['media.download_offload' => true]);
        $first = $this->get($url)->assertOk();
        $this->assertNotNull($first->headers->get('X-Accel-Redirect'));
        $this->assertStringContainsString('no-cache', $first->headers->get('Cache-Control'));
        $next = $this->get($url, ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
        $this->assertNull($next->headers->get('X-Accel-Redirect'));
        $next->assertContent('');
    }
}
