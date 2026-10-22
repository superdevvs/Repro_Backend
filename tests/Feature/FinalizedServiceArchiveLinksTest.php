<?php

namespace Tests\Feature;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class FinalizedServiceArchiveLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        $this->app->instance(ShootMediaStorageService::class, $dropbox);
    }

    public function test_finalized_service_zip_excludes_other_services_and_pending_replacements(): void
    {
        [$shoot, $serviceId, $otherId] = $this->fixture();
        $released = $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.jpg');
        $this->file($shoot, $serviceId, ShootFile::STAGE_COMPLETED, 'pending-replacement.jpg');
        $this->file($shoot, $otherId, ShootFile::STAGE_VERIFIED, 'other-service.jpg');
        $archives = app(ShootMediaArchiveService::class);
        $type = 'edited|verified';

        $this->assertSame([$released->id], $archives->getFilesForType($shoot, $type, $serviceId)->pluck('id')->all());
        $this->assertNotSame($archives->getArchivePath($shoot, $type, 'original', $serviceId),
            $archives->getArchivePath($shoot, 'edited', 'original', $serviceId));
        $archives->generateArchive($shoot, $type, 'original', false, $serviceId);
        $zip = new ZipArchive;
        $path = Storage::disk('local')->path($archives->getArchivePath($shoot, $type, 'original', $serviceId));
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('released.jpg', $zip->getFromIndex(0));
        $zip->close();

        // An old link remains restricted when a later replacement enters review.
        $this->file($shoot, $serviceId, ShootFile::STAGE_COMPLETED, 'later-pending.jpg');
        $this->assertSame([$released->id], $archives->getFilesForType($shoot, $type, $serviceId)->pluck('id')->all());
    }

    public function test_signed_link_keeps_service_and_verified_scope_through_public_queue(): void
    {
        [$shoot, $serviceId, $otherId] = $this->fixture();
        $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.jpg');
        $archives = app(ShootMediaArchiveService::class);
        $canonical = $archives->buildCanonicalPublicDownloadUrl($shoot, 'edited|verified', 'original', null, $serviceId);
        parse_str((string) parse_url($canonical, PHP_URL_QUERY), $query);
        $signed = $query['url'];
        $this->getJson($signed)->assertAccepted();
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->shootServiceId === $serviceId && $job->type === 'edited|verified');

        $this->getJson(str_replace('shoot_service_id='.$serviceId, 'shoot_service_id='.$otherId, $signed))->assertForbidden();
        $this->getJson(str_replace('verified_only=1', 'verified_only=0', $signed))->assertForbidden();
        $shoot->serviceItems()->whereKey($serviceId)->update(['delivery_status' => 'in_progress']);
        $this->getJson($signed)->assertForbidden()->assertJsonPath('code', 'content_not_finalized');
    }

    public function test_correctly_signed_cross_shoot_scope_is_rejected(): void
    {
        [$shoot] = $this->fixture();
        [$other, $otherServiceId] = $this->fixture();
        $this->assertNotSame($shoot->id, $other->id);
        $signed = app(ShootMediaArchiveService::class)->buildSignedStatusUrl($shoot, 'edited|verified', 'original', null, $otherServiceId);
        $this->getJson($signed)->assertUnprocessable();
    }

    private function fixture(): array
    {
        $shoot = Shoot::factory()->create(['total_quote' => 0, 'payment_status' => 'paid']);
        $first = Service::factory()->create(['photographer_required' => false]);
        $second = Service::factory()->create(['photographer_required' => false]);
        $shoot->services()->attach($first->id, ['workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $shoot->services()->attach($second->id, ['workflow_status' => 'in_progress', 'delivery_status' => 'not_started']);

        return [$shoot, (int) $shoot->serviceItems()->where('service_id', $first->id)->first()->id,
            (int) $shoot->serviceItems()->where('service_id', $second->id)->first()->id];
    }

    private function file(Shoot $shoot, int $serviceId, string $stage, string $name): ShootFile
    {
        $path = "shoots/{$shoot->id}/completed/{$name}";
        Storage::disk('public')->put($path, $name);

        return ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $serviceId, 'filename' => $name,
            'stored_filename' => $name, 'path' => $path, 'storage_path' => $path,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => strlen($name),
            'media_type' => 'edited', 'workflow_stage' => $stage, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'uploaded_by' => $shoot->client_id,
        ]);
    }
}
