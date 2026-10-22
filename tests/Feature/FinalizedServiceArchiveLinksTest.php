<?php

namespace Tests\Feature;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Messaging\ShootDeliveryRevision;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\SystemEmails\DeliveryShareLinksBuilder;
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

    public function test_existing_whole_summary_zip_excludes_pending_and_reopened_service_owners(): void
    {
        [$shoot, $serviceId, $pendingId] = $this->fixture();
        $released = $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.jpg');
        $legacy = $this->file($shoot, null, ShootFile::STAGE_VERIFIED, 'legacy.jpg');
        $archives = app(ShootMediaArchiveService::class);
        $signed = $archives->buildSignedStatusUrl($shoot, 'edited|verified', 'original');
        $this->assertStringNotContainsString('shoot_service_id=', $signed);
        $archives->generateArchive($shoot, 'edited|verified', 'original');
        $this->assertTrue($archives->hasFreshArchive($shoot, 'edited|verified', 'original'));
        $this->getJson($signed)->assertOk()->assertJsonPath('type', 'redirect');

        $pending = $this->file($shoot, $pendingId, ShootFile::STAGE_VERIFIED, 'pending-service.jpg');
        $replacement = $this->file($shoot, $serviceId, ShootFile::STAGE_COMPLETED, 'pending-replacement.jpg');
        $this->assertEqualsCanonicalizing([$released->id, $legacy->id], $archives->getFilesForType($shoot, 'edited|verified')->pluck('id')->all());
        $this->assertTrue($archives->hasFreshArchive($shoot, 'edited|verified', 'original'));

        // Both workflow and delivery must remain finalized, even for a previously issued URL.
        $shoot->serviceItems()->whereKey($serviceId)->update(['delivery_status' => 'in_progress']);
        $this->assertFalse($archives->hasFreshArchive($shoot, 'edited|verified', 'original'));
        $this->getJson($signed)->assertAccepted();
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->shootServiceId === null && $job->type === 'edited|verified');
        $this->assertSame([$legacy->id], $archives->getFilesForType($shoot, 'edited|verified')->pluck('id')->all());
        $shoot->serviceItems()->whereKey($serviceId)->update(['delivery_status' => 'delivered', 'workflow_status' => 'in_progress']);
        $this->assertSame([$legacy->id], $archives->getFilesForType($shoot, 'edited|verified')->pluck('id')->all());

        $archives->generateArchive($shoot, 'edited|verified', 'original', true);
        $zip = new ZipArchive;
        $path = Storage::disk('local')->path($archives->getArchivePath($shoot, 'edited|verified', 'original'));
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('legacy.jpg', $zip->getFromIndex(0));
        $zip->close();
        $this->getJson($signed)->assertOk()->assertJsonPath('type', 'redirect');

        // Staff's plain edited ZIP retains pending/reopened work for manual use.
        $this->assertEqualsCanonicalizing([$released->id, $legacy->id, $pending->id, $replacement->id],
            $archives->getFilesForType($shoot, 'edited')->pluck('id')->all());
    }

    public function test_whole_summary_links_and_old_url_stop_offering_only_pending_or_revoked_media(): void
    {
        [$shoot, $serviceId, $pendingId] = $this->fixture();
        $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.jpg');
        $this->file($shoot, $pendingId, ShootFile::STAGE_VERIFIED, 'pending.jpg');
        $builder = app(DeliveryShareLinksBuilder::class);
        $this->assertNotNull($builder->forShootSummary($shoot)['full_zip_link']);
        $signed = app(ShootMediaArchiveService::class)->buildSignedStatusUrl($shoot, 'edited|verified', 'original');
        Queue::fake();
        $shoot->serviceItems()->whereKey($serviceId)->update(['workflow_status' => 'in_progress']);

        $share = $builder->forShootSummary($shoot);
        $this->assertNull($share['full_zip_link']);
        $this->assertNull($share['small_zip_link']);
        $this->getJson($signed)->assertNotFound();
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
    }

    public function test_summary_direct_video_candidates_require_their_own_service_to_remain_finalized(): void
    {
        [$shoot, $serviceId, $pendingId] = $this->fixture();
        Service::whereIn('id', $shoot->serviceItems()->pluck('service_id'))->update(['upload_intake_type' => 'video']);
        $pending = $this->file($shoot, $pendingId, ShootFile::STAGE_VERIFIED, 'pending.mp4');
        $pending->update(['media_type' => 'video', 'path' => 'https://example.test/pending.mp4']);
        $released = $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.mp4');
        $released->update(['media_type' => 'video', 'path' => 'https://example.test/released.mp4']);
        $shoot->load('files', 'serviceItems.service');
        $builder = app(DeliveryShareLinksBuilder::class);
        $this->assertSame('https://example.test/released.mp4', $builder->forShootSummary($shoot)['video_download_link']);
        $shoot->update(['tour_links' => ['video_mls' => 'https://example.test/saved.mp4',
            'video_branded' => 'https://example.test/branded.mp4', 'video_generic' => 'https://example.test/generic.mp4',
            'video_link' => 'https://example.test/link.mp4']]);
        $this->assertSame('https://example.test/saved.mp4', $builder->forShootSummary($shoot)['video_download_link']);

        // Neither preloaded relation may retain a reopened line's direct or saved URLs.
        $shoot->serviceItems()->whereKey($serviceId)->update(['delivery_status' => 'in_progress']);
        $share = $builder->forShootSummary($shoot);
        $this->assertNull($share['video_download_link']);
        $this->assertSame([], array_filter($share['links'], fn ($link) => in_array($link['key'],
            ['video', 'additional_video_mls', 'additional_video_branded', 'additional_video_generic', 'additional_video_link'], true)));
    }

    public function test_whole_summary_revision_ignores_verified_media_until_its_owner_is_finalized(): void
    {
        [$shoot, $serviceId, $pendingId] = $this->fixture();
        $this->file($shoot, $serviceId, ShootFile::STAGE_VERIFIED, 'released.jpg');
        $pending = $this->file($shoot, $pendingId, ShootFile::STAGE_VERIFIED, 'pending.jpg');
        $revisions = app(ShootDeliveryRevision::class);
        $initial = $revisions->forShoot($shoot);
        $pending->update(['content_version' => 2]);
        $this->assertSame($initial, $revisions->forShoot($shoot));
        $shoot->serviceItems()->whereKey($pendingId)->update(['delivery_status' => 'delivered']);
        $this->assertSame($initial, $revisions->forShoot($shoot));
        $shoot->serviceItems()->whereKey($pendingId)->update(['workflow_status' => 'delivered']);
        $this->assertNotSame($initial, $revisions->forShoot($shoot));
        $shoot->serviceItems()->whereKey($pendingId)->update(['workflow_status' => 'in_progress']);
        $this->assertSame($initial, $revisions->forShoot($shoot));
        $pending->update(['content_version' => 3]);
        $this->assertSame($initial, $revisions->forShoot($shoot));

        // Existing unassigned delivery media keeps its legacy revision behavior.
        $this->file($shoot, null, ShootFile::STAGE_VERIFIED, 'legacy.jpg');
        $this->assertNotSame($initial, $revisions->forShoot($shoot));
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

    private function file(Shoot $shoot, ?int $serviceId, string $stage, string $name): ShootFile
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
