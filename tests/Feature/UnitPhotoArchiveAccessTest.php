<?php

namespace Tests\Feature;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\{Payment, PaymentRefund, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, ShootUnit, User};
use App\Services\Shoots\{DeliveryPlanService, ShootMediaArchiveService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class UnitPhotoArchiveAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;
    private Shoot $shoot;
    private ShootUnit $unit;
    private ShootService $photos;
    private ShootService $floorplans;
    private ShootFile $photo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('public'); Storage::fake('local');
        config(['media.tiered_storage_enabled' => false, 'media.read_from_r2' => false, 'media.r2_only' => false]);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->admin = User::factory()->create(['role' => 'superadmin']);
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id,
            'status' => 'review', 'workflow_status' => 'review', 'delivery_status' => 'partially_delivered',
            'payment_status' => 'paid', 'total_quote' => 200, 'bypass_paywall' => false]);
        $this->unit = $this->unit('Apt 2');
        $this->photos = $this->line($this->unit, 'photo');
        $this->floorplans = $this->line($this->unit, 'floorplan');
        $this->photo = $this->file($this->photos, 'released.jpg');
        $this->release($this->photos, $this->photo);
        Sanctum::actingAs($this->client);
    }

    public function test_paid_unit_photo_zips_work_before_floorplans_and_contain_only_released_unit_photos(): void
    {
        $this->file($this->photos, 'pending-replacement.jpg');
        $this->file($this->photos, 'hidden.jpg', ['is_hidden' => true]);
        $this->file($this->photos, 'quarantined.jpg', ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);
        $this->file($this->floorplans, 'pending-plan.jpg', ['media_type' => 'floorplan']);
        $otherLine = $this->line($this->unit('Apt 3'), 'photo');
        $this->release($otherLine, $this->file($otherLine, 'other-unit.jpg'));
        $receiptCount = DB::table('shoot_delivery_receipts')->count();
        $archives = app(ShootMediaArchiveService::class);
        $type = $archives->buildArchiveTypeToken('edited', false, [], 'photos', true);

        foreach (['original', 'small'] as $size) {
            $this->getJson($this->url(['size' => $size]))->assertAccepted();
            Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->shootUnitId === $this->unit->id
                && $job->shootServiceId === null && $job->type === $type && $job->size === $size);
            $archives->generateArchive($this->shoot->fresh(), $type, $size, true, null, $this->unit->id);
            $this->getJson($this->url(['size' => $size]))->assertOk()->assertJsonPath('type', 'redirect');
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($archives->getArchivePath($this->shoot, $type, $size, null, $this->unit->id))));
            $this->assertSame(1, $zip->numFiles);
            $this->assertSame(($size === 'small' ? 'mls:' : 'original:').'released.jpg', $zip->getFromIndex(0));
            $zip->close();
        }
        $this->getJson($this->url(['shoot_service_id' => $this->photos->id]))->assertAccepted();
        $this->assertSame('not_started', $this->floorplans->fresh()->delivery_status);
        $this->assertSame('review', $this->shoot->fresh()->workflow_status);
        $this->assertDatabaseCount('shoot_delivery_receipts', $receiptCount);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_unit_photo_payment_is_enforced_and_a_paid_photo_line_does_not_unlock_the_whole_shoot(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->getJson($this->url())->assertForbidden()->assertJsonPath('code', 'payment_required');
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
        $payment = $this->payForPhotos();
        $this->getJson($this->url())->assertAccepted();
        $this->getJson('/api/shoots/'.$this->shoot->id.'/media/download-zip?type=edited&asset_type=photos')
            ->assertForbidden()->assertJsonPath('code', 'payment_required');

        $archives = app(ShootMediaArchiveService::class);
        $type = $archives->buildArchiveTypeToken('edited', false, [], 'photos', true);
        $archives->generateArchive($this->shoot->fresh(), $type, 'original', true, null, $this->unit->id);
        $this->getJson($this->url())->assertOk();
        PaymentRefund::create(['payment_id' => $payment->id, 'shoot_id' => $this->shoot->id,
            'amount' => 100, 'provider' => 'manual', 'status' => 'succeeded']);
        $this->getJson($this->url())->assertForbidden()->assertJsonPath('code', 'payment_required');
    }

    public function test_photo_filter_excludes_an_unpaid_released_floorplan_but_an_archive_including_it_stays_locked(): void
    {
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->payForPhotos();
        $plan = $this->file($this->floorplans, 'released-plan.jpg', ['media_type' => 'floorplan']);
        $this->release($this->floorplans, $plan);
        $this->getJson($this->url())->assertAccepted();
        $this->getJson($this->url(['asset_type' => null]))->assertForbidden()->assertJsonPath('code', 'payment_required');
        $this->getJson($this->url(['asset_type' => 'floorplans']))->assertForbidden()->assertJsonPath('code', 'payment_required');
        $this->getJson($this->url(['asset_type' => null, 'media_types' => ['edited']]))->assertAccepted();
    }

    public function test_pending_unit_returns_no_files_instead_of_a_false_payment_error_and_never_queues_an_archive(): void
    {
        $pendingUnit = $this->unit('Apt 4');
        $pendingLine = $this->line($pendingUnit, 'photo');
        $this->file($pendingLine, 'not-approved.jpg');
        $this->getJson($this->url(['shoot_unit_id' => $pendingUnit->id]))->assertNotFound()
            ->assertJsonPath('error', 'No downloadable files available');
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
    }

    public function test_unit_archive_keeps_owner_raw_cross_shoot_and_service_unit_guards(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson($this->url())->assertForbidden();
        Sanctum::actingAs($this->client);
        $this->getJson($this->url(['type' => 'raw']))->assertForbidden();
        $otherShoot = Shoot::factory()->create(['client_id' => $this->client->id]);
        $foreignUnit = $otherShoot->units()->create(['client_key' => 'foreign', 'label' => 'Other property', 'kind' => 'unit']);
        $this->getJson($this->url(['shoot_unit_id' => $foreignUnit->id]))->assertUnprocessable();
        $otherLine = $this->line($this->unit('Apt 3'), 'photo');
        $this->getJson($this->url(['shoot_service_id' => $otherLine->id]))->assertUnprocessable();
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
    }

    public function test_cached_unit_zip_cannot_restore_hidden_quarantined_or_revoked_photos(): void
    {
        $archives = app(ShootMediaArchiveService::class);
        $type = $archives->buildArchiveTypeToken('edited', false, [], 'photos', true);
        $archives->generateArchive($this->shoot->fresh(), $type, 'original', false, null, $this->unit->id);
        foreach ([['is_hidden' => true], ['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED],
            ['scan_status' => ShootFile::SCAN_STATUS_INFECTED], ['workflow_stage' => ShootFile::STAGE_COMPLETED]] as $blocked) {
            $this->photo->update($blocked + ['is_hidden' => false, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_VERIFIED]);
            $this->getJson($this->url())->assertNotFound();
        }
        $this->photo->update(['workflow_stage' => ShootFile::STAGE_VERIFIED]);
        DB::table('shoot_delivery_receipts')->where('shoot_service_id', $this->photos->id)->update(['revoked_at' => now()]);
        app(DeliveryPlanService::class)->forgetReleasedFiles();
        $this->getJson($this->url())->assertNotFound();
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
    }

    private function unit(string $label): ShootUnit
    {
        return $this->shoot->units()->create(['client_key' => $label, 'label' => $label, 'kind' => 'unit']);
    }

    private function line(ShootUnit $unit, string $kind): ShootService
    {
        $factory = Service::factory();
        $service = ($kind === 'photo' ? $factory->unbracketedPhoto() : $factory->noIntake())
            ->create(['name' => $kind === 'photo' ? 'HDR Photos' : 'Floorplans']);
        $line = $this->shoot->serviceItems()->create(['shoot_unit_id' => $unit->id, 'service_id' => $service->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'review', 'delivery_status' => 'not_started']);
        $line->forceFill(['delivery_requirements' => [['key' => $kind, 'kind' => $kind, 'label' => $kind]]])->save();
        return $line;
    }

    private function file(ShootService $line, string $name, array $attributes = []): ShootFile
    {
        $path = 'shoots/'.$this->shoot->id.'/completed/'.$name;
        $web = 'shoots/'.$this->shoot->id.'/web/'.$name;
        Storage::disk('public')->put($path, 'original:'.$name);
        Storage::disk('public')->put($web, 'mls:'.$name);
        return ShootFile::create($attributes + ['shoot_id' => $this->shoot->id, 'shoot_service_id' => $line->id,
            'filename' => $name, 'stored_filename' => $name, 'path' => $path, 'storage_path' => $path, 'web_path' => $web,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 32, 'uploaded_by' => $this->client->id,
            'media_type' => 'edited', 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN]);
    }

    private function release(ShootService $line, ShootFile $file): void
    {
        DB::transaction(fn () => app(DeliveryPlanService::class)->commitSelection($this->shoot->fresh(), $this->admin,
            ['mode' => 'media', 'component_ids' => [$line->id.':'.$line->delivery_requirements[0]['key']]]));
        $file->update(['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now(), 'verified_by' => $this->admin->id]);
        $line->update(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()]);
    }

    private function payForPhotos(): Payment
    {
        $payment = Payment::factory()->create(['shoot_id' => $this->shoot->id, 'invoice_id' => null,
            'amount' => 100, 'status' => Payment::STATUS_COMPLETED, 'payment_method' => 'cash']);
        PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $this->photos->id, 'amount' => 100]);
        return $payment;
    }

    private function url(array $query = []): string
    {
        return '/api/shoots/'.$this->shoot->id.'/media/download-zip?'.http_build_query($query +
            ['type' => 'edited', 'asset_type' => 'photos', 'shoot_unit_id' => $this->unit->id, 'size' => 'original']);
    }
}
