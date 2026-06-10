<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the upload quarantine + scan wiring required by task 13.3
 * (Req 14.1, 14.2, 14.5, 14.6).
 *
 *  - Pre-scan validation rejects oversize / disallowed-type uploads with 422
 *    BEFORE any ShootFile row is created.
 *  - On a valid upload, the ShootFile row is created with scan_status
 *    'quarantined' (the migration default).
 *  - ScanShootFileJob is dispatched after the row is created.
 *  - ProcessImageJob and UploadShootMediaToDropboxJob are NOT dispatched
 *    directly from the upload path (downstream gating arrives in 13.6 / 13.5).
 *
 * Hits BOTH user-upload entry points called out by the task:
 *  - FileUploadController::uploadFromPC (route POST /shoots/{id}/upload-from-pc)
 *  - UploadShootFilesAction::execute   (route POST /shoots/{id}/upload, mounted
 *                                        on ShootMediaController::uploadFiles)
 */
class UploadQuarantineWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin upload validation config so the test is independent of env overrides.
        Config::set('uploads.max_bytes', 1048576 * 1024); // 1 GiB
        Config::set('uploads.allowed_types', ['jpg', 'jpeg', 'png', 'mp4', 'zip']);
        Config::set('services.dropbox.enabled', false);
        Config::set('services.dropbox.access_token', null);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'upload-quarantine-admin@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Quarantine Wiring Service',
            'price' => 100,
        ]);
    }

    private function createShoot(): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'upload-quarantine-client-' . uniqid() . '@test.com',
        ]);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'upload-quarantine-photog-' . uniqid() . '@test.com',
        ]);

        return Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $this->service->id,
            'address' => '500 Quarantine Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 100,
            'tax_amount' => 6,
            'total_quote' => 106,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ]);
    }

    #[Test]
    public function shoot_media_upload_rejects_a_disallowed_type_with_422_before_creating_a_row(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->create('malware.exe', 16)],
            'upload_type' => 'raw',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ShootFile::where('shoot_id', $shoot->id)->count());
        Queue::assertNotPushed(ScanShootFileJob::class);
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }

    #[Test]
    public function shoot_media_upload_rejects_oversize_files_with_422_before_creating_a_row(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        // Cap at 4 MiB for this case so the fake size is realistic.
        Config::set('uploads.max_bytes', 4 * 1024 * 1024);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            // 16 MiB jpg, well over the 4 MiB cap above.
            'files' => [UploadedFile::fake()->create('huge.jpg', 16 * 1024)],
            'upload_type' => 'raw',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ShootFile::where('shoot_id', $shoot->id)->count());
        Queue::assertNotPushed(ScanShootFileJob::class);
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }

    #[Test]
    public function shoot_media_upload_creates_a_quarantined_row_and_dispatches_only_the_scan_job(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->image('clean-shot.jpg', 800, 600)],
            'upload_type' => 'raw',
        ]);

        $response->assertOk();

        $file = ShootFile::where('shoot_id', $shoot->id)->latest('id')->first();
        $this->assertNotNull($file, 'Expected a ShootFile row to be created on a valid upload.');
        $this->assertSame(
            ShootFile::SCAN_STATUS_QUARANTINED,
            $file->scan_status,
            'New uploads must default to scan_status=quarantined.'
        );

        // ScanShootFileJob is dispatched with the new file's id; downstream
        // processing jobs are NOT dispatched directly from the upload path.
        Queue::assertPushed(ScanShootFileJob::class, function (ScanShootFileJob $job) use ($file) {
            return $job->shootFileId === $file->id;
        });
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }

    #[Test]
    public function upload_from_pc_rejects_a_disallowed_type_with_422_before_creating_a_row(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload-from-pc', [
            'files' => [UploadedFile::fake()->create('payload.exe', 16)],
            'upload_type' => 'raw',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ShootFile::where('shoot_id', $shoot->id)->count());
        Queue::assertNotPushed(ScanShootFileJob::class);
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }

    #[Test]
    public function upload_from_pc_creates_a_quarantined_row_and_dispatches_only_the_scan_job(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload-from-pc', [
            'files' => [UploadedFile::fake()->image('pc-shot.jpg', 1024, 768)],
            'upload_type' => 'raw',
        ]);

        $response->assertOk();

        $file = ShootFile::where('shoot_id', $shoot->id)->latest('id')->first();
        $this->assertNotNull($file, 'Expected a ShootFile row to be created on a valid upload.');
        $this->assertSame(
            ShootFile::SCAN_STATUS_QUARANTINED,
            $file->scan_status,
            'New uploads from PC must default to scan_status=quarantined.'
        );

        Queue::assertPushed(ScanShootFileJob::class, function (ScanShootFileJob $job) use ($file) {
            return $job->shootFileId === $file->id;
        });
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }

    #[Test]
    public function shoot_media_upload_quarantines_non_image_files_and_dispatches_only_the_scan_job(): void
    {
        // Non-image uploads (video, archive) must still enter quarantine and
        // get a Scan_Job (Req 14.1/14.2). ProcessImageJob would never have run
        // for these files anyway; the wiring contract is "every uploaded
        // ShootFile gets a Scan_Job", not "image uploads get a Scan_Job".
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->create('walkthrough.mp4', 256, 'video/mp4')],
            'upload_type' => 'raw',
        ]);

        $response->assertOk();

        $file = ShootFile::where('shoot_id', $shoot->id)->latest('id')->first();
        $this->assertNotNull($file, 'Expected a ShootFile row to be created on a valid video upload.');
        $this->assertSame(
            ShootFile::SCAN_STATUS_QUARANTINED,
            $file->scan_status,
            'Non-image uploads must also default to scan_status=quarantined.'
        );

        Queue::assertPushed(ScanShootFileJob::class, function (ScanShootFileJob $job) use ($file) {
            return $job->shootFileId === $file->id;
        });
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }
}
