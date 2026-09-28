<?php

namespace Tests\Feature;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\ShootDownloadAssetClassifier;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootUnitPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ClientDownloadChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $this->client = User::factory()->create(['role' => 'client']);
        $this->shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'payment_status' => 'paid',
            'bypass_paywall' => false,
            'total_quote' => 100,
        ]);
        Sanctum::actingAs($this->client);
    }

    public function test_photo_archives_contain_only_original_or_mls_photo_bytes_including_legacy_images(): void
    {
        $legacy = $this->file('legacy.jpg', ['media_type' => '']);
        $photo = $this->file('photo.jpg');
        $drone = $this->file('aerial.jpg', ['media_type' => 'drone']);
        $bundle = $this->file('bundle.jpg', ['shoot_service_id' => $this->line('Photos & Floor Plans')->id]);
        $this->file('plan.jpg', ['media_type' => 'floorplan']);
        $this->file('plan.pdf', ['file_type' => 'application/pdf']);
        $this->file('provider.jpg', ['metadata' => ['source' => 'iguide']]);
        $this->file('service-plan.jpg', ['shoot_service_id' => $this->line('Floor-Plan')->id]);
        $this->file('video.mp4', ['media_type' => 'video', 'file_type' => 'video/mp4']);
        $this->file('infected.jpg', ['scan_status' => ShootFile::SCAN_STATUS_INFECTED]);
        $this->file('pending.jpg', ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->file('hidden.jpg', ['is_hidden' => true]);
        $this->file('extra.jpg', ['is_extra' => true, 'metadata' => ['is_extra' => true, 'required_for_editing' => false]]);
        $this->file('raw.cr3', ['file_type' => 'image/x-canon-cr3']);
        $archive = app(ShootMediaArchiveService::class);
        $type = $archive->buildArchiveTypeToken('edited', false, [], 'photos');
        $this->assertEqualsCanonicalizing([$legacy->id, $photo->id, $drone->id, $bundle->id], $archive->getFilesForType($this->shoot, $type)->pluck('id')->all());
        $this->assertNotSame($archive->getArchivePath($this->shoot, 'edited', 'original'), $archive->getArchivePath($this->shoot, $type, 'original'));
        foreach (['original', 'small'] as $size) {
            $archive->generateArchive($this->shoot, $type, $size);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open(Storage::disk('local')->path($archive->getArchivePath($this->shoot, $type, $size))));
            $contents = [];
            for ($index = 0; $index < $zip->numFiles; $index++) $contents[] = $zip->getFromIndex($index);
            $zip->close();
            $prefix = $size === 'small' ? 'mls:' : 'master:';
            $this->assertEqualsCanonicalizing(array_map(fn ($name) => $prefix.$name, ['legacy.jpg', 'photo.jpg', 'aerial.jpg', 'bundle.jpg']), $contents);
        }
    }

    public function test_photo_archive_request_preserves_semantic_filter_in_job_and_signed_poll_url(): void
    {
        $this->file('photo.jpg');
        $response = $this->getJson('/api/shoots/'.$this->shoot->id.'/media/download-zip?type=edited&size=small&asset_type=photos')->assertAccepted();
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => str_contains($job->type, 'asset=photos'));
        $this->assertStringContainsString('asset_type=photos', $response->json('status_url'));
        $archive = app(ShootMediaArchiveService::class);
        $signed = $archive->buildSignedStatusUrl($this->shoot, $archive->buildArchiveTypeToken('edited', false, [], 'photos'), 'small');
        $this->assertStringContainsString('asset_type=photos', $signed);
        $this->getJson($signed)->assertAccepted();
        $this->getJson('/api/shoots/'.$this->shoot->id.'/media/download-zip?type=edited&asset_type=unknown')->assertUnprocessable();
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/media/download-zip?type=edited&asset_type=photos')->assertForbidden()->assertJsonPath('code', 'payment_required');
    }

    public function test_video_and_pdf_downloads_never_substitute_a_jpeg_preview_for_a_missing_master(): void
    {
        foreach (['video.mp4' => 'video/mp4', 'plan.pdf' => 'application/pdf'] as $name => $mime) {
            $file = $this->file($name, ['file_type' => $mime]);
            $response = $this->get($this->url($file), ['Accept' => 'application/zip, application/json'])->assertOk();
            $this->assertSame('master:'.$name, file_get_contents($response->baseResponse->getFile()->getPathname()));
            $this->assertStringContainsString($name, $response->headers->get('Content-Disposition'));
            Storage::disk('public')->delete($file->path);
            $this->get($this->url($file), ['Accept' => 'application/zip, application/json'])->assertNotFound();
            $this->getJson($this->url($file))->assertNotFound();
        }
    }

    public function test_paid_client_downloads_the_requested_floorplan_page_as_jpg_and_keeps_the_original_pdf(): void
    {
        [$file, $pages] = $this->floorplan();
        $response = $this->get($this->url($file).'?format=jpg&page=2')->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('plan-page-2.jpg', $response->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents(Storage::disk('public')->path($pages[1])), file_get_contents($response->baseResponse->getFile()->getPathname()));
        $original = $this->get($this->url($file), ['Accept' => 'application/zip'])->assertOk();
        $this->assertSame('master:plan.pdf', file_get_contents($original->baseResponse->getFile()->getPathname()));
        foreach ([0, 26, 'bad'] as $page) $this->getJson($this->url($file).'?format=jpg&page='.$page)->assertUnprocessable();
        $this->getJson($this->url($file).'?format=jpg&page=3')->assertNotFound();
        $photo = $this->file('photo.jpg');
        $this->getJson($this->url($photo).'?format=jpg')->assertUnprocessable();
        $file->update(['metadata' => ['preview_images' => ['shoots/999/floorplans/previews/page.jpg']]]);
        $this->getJson($this->url($file).'?format=jpg')->assertNotFound();
    }

    public function test_floorplan_jpgs_keep_owner_payment_service_stage_hidden_and_scan_guards(): void
    {
        [$file] = $this->floorplan();
        $url = $this->url($file).'?format=jpg&page=1';
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'payment_required');
        $line = $this->line('Floor Plan');
        $line->update(['force_unlock_delivery' => true]);
        $file->update(['shoot_service_id' => $line->id]);
        $this->get($url)->assertOk();
        foreach ([['workflow_stage' => ShootFile::STAGE_TODO], ['is_hidden' => true], ['scan_status' => ShootFile::SCAN_STATUS_INFECTED], ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]] as $blocked) {
            $file->update(array_merge(['workflow_stage' => ShootFile::STAGE_COMPLETED, 'is_hidden' => false, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN], $blocked));
            $this->getJson($url)->assertForbidden();
        }
        $file->update(['scan_status' => ShootFile::SCAN_STATUS_CLEAN]);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->client);
        $other = Shoot::factory()->create(['client_id' => $this->client->id]);
        $this->getJson('/api/shoots/'.$other->id.'/media/'.$file->id.'/download?format=jpg')->assertNotFound();
    }

    public function test_home_report_is_not_a_floorplan_and_provider_and_service_floorplan_classification_agree(): void
    {
        $classifier = app(ShootDownloadAssetClassifier::class);
        $report = $this->file('home-report.pdf', ['file_type' => 'application/pdf', 'metadata' => ['cubicasa_asset_key' => 'pdf_home_report_english']]);
        $this->assertSame('other', $classifier->type($report));
        $this->getJson($this->url($report).'?format=jpg')->assertUnprocessable();
        $this->assertSame('floorplans', $classifier->type($this->file('provider.jpg', ['metadata' => ['provider_asset_key' => 'jpg_metric']])));
        $this->assertSame('floorplans', $classifier->type($this->file('line.jpg', ['shoot_service_id' => $this->line('Premium Floor Plan')->id])));
        $this->assertSame('photos', $classifier->type($this->file('bundle.jpg', ['shoot_service_id' => $this->line('Photos & Floor Plans')->id])));
    }

    public function test_authorized_payloads_keep_floorplan_page_numbers_and_do_not_publish_locked_previews(): void
    {
        [$file, $pages] = $this->floorplan();
        $file->update(['metadata' => ['preview_images' => [null, $pages[1]]]]);
        $listed = $this->getJson('/api/shoots/'.$this->shoot->id.'/files?type=edited')->assertOk();
        $this->assertStringContainsString('plan-page-2.jpg', $listed->getContent());
        $this->assertStringContainsString('"preview_images":[null,', $listed->getContent());
        $shoot = $this->getJson('/api/shoots/'.$this->shoot->id)->assertOk();
        $payloadFile = collect($shoot->json('data.files'))->firstWhere('id', $file->id);
        $this->assertSame('floorplans', $payloadFile['download_asset_type']);
        $this->assertNull($payloadFile['preview_images'][0]);
        $this->assertStringContainsString('plan-page-2.jpg', $payloadFile['preview_images'][1]);
        $this->shoot->update(['payment_status' => 'unpaid']);
        $locked = $this->getJson('/api/shoots/'.$this->shoot->id)->assertOk();
        $lockedFile = collect($locked->json('data.files'))->firstWhere('id', $file->id);
        $this->assertArrayNotHasKey('preview_images', $lockedFile);
        $this->assertArrayNotHasKey('previewImages', $lockedFile);
    }

    public function test_storage_path_only_video_resolves_its_existing_master_instead_of_a_stale_path(): void
    {
        $file = $this->file('video.mp4', ['file_type' => 'video/mp4', 'path' => 'missing/video.mp4']);
        $this->getJson($this->url($file))->assertOk()->assertJsonPath('url', fn ($url) => str_contains($url, '/completed/video.mp4'));
    }

    public function test_client_unit_provider_links_include_only_released_lines_and_safe_viewer_fields(): void
    {
        $unit = $this->shoot->units()->create(['client_key' => 'unit', 'label' => 'Unit 1', 'kind' => 'unit']);
        $ready = $this->line('iGuide');
        $pending = $this->line('iGuide');
        foreach ([$ready, $pending] as $line) $line->update(['shoot_unit_id' => $unit->id]);
        $unit->update(['provider_data' => ['lines' => [
            $ready->id => ['iguide_tour_url' => 'https://tour.test/ready', 'iguide_data' => ['unbranded_url' => 'https://tour.test/mls', 'pdf_metric_url' => 'https://private.test/plan.pdf', 'password' => 'secret'], 'cubicasa_tour_url' => 'https://user:secret@tour.test/unsafe'],
            $pending->id => ['iguide_tour_url' => 'https://pending.test/tour'],
        ]]]);
        $summaries = array_map(fn ($line) => ['shoot_service_id' => $line->id, 'shoot_unit_id' => $unit->id, 'is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => $line === $ready ? 'ready' : 'not_started', 'is_unlocked_for_delivery' => true], [$ready, $pending]);
        $data = app(ShootUnitPresenter::class)->forUser($this->shoot, $summaries, $this->client)[0]['provider_data'];
        $this->assertSame('https://tour.test/ready', $data['lines'][$ready->id]['iguide_tour_url']);
        $this->assertSame('https://tour.test/mls', $data['lines'][$ready->id]['iguide_data']['unbranded_url']);
        $this->assertArrayNotHasKey($pending->id, $data['lines']);
        $json = json_encode($data);
        foreach (['private.test', 'secret', 'unsafe', 'pending.test', 'pdf_metric_url'] as $hidden) $this->assertStringNotContainsString($hidden, $json);
    }

    private function line(string $name): ShootService
    {
        return $this->shoot->serviceItems()->create(['service_id' => Service::factory()->create(['name' => $name])->id, 'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => ShootService::DELIVERY_READY]);
    }

    private function file(string $name, array $attributes = []): ShootFile
    {
        $path = 'shoots/'.$this->shoot->id.'/completed/'.$name;
        $web = 'shoots/'.$this->shoot->id.'/web/'.$name.'.jpg';
        Storage::disk('public')->put($path, 'master:'.$name);
        Storage::disk('public')->put($web, 'mls:'.$name);
        return ShootFile::create(array_merge(['shoot_id' => $this->shoot->id, 'filename' => $name, 'stored_filename' => $name, 'path' => $path, 'storage_path' => $path, 'web_path' => $web, 'thumbnail_path' => $web, 'file_type' => 'image/jpeg', 'file_size' => 32, 'media_type' => 'edited', 'uploaded_by' => $this->client->id, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN], $attributes));
    }

    private function floorplan(): array
    {
        $pages = ['shoots/'.$this->shoot->id.'/floorplans/previews/plan-page-1.jpg', 'shoots/'.$this->shoot->id.'/floorplans/previews/plan-page-2.jpg'];
        foreach ($pages as $index => $path) {
            $image = imagecreatetruecolor(4 + $index, 3);
            ob_start();
            imagejpeg($image);
            Storage::disk('public')->put($path, ob_get_clean());
            imagedestroy($image);
        }
        return [$this->file('plan.pdf', ['file_type' => 'application/pdf', 'media_type' => 'floorplan', 'metadata' => ['preview_images' => $pages]]), $pages];
    }

    private function url(ShootFile $file): string
    {
        return '/api/shoots/'.$this->shoot->id.'/media/'.$file->id.'/download';
    }
}
