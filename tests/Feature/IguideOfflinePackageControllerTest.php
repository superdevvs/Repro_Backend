<?php

namespace Tests\Feature;

use App\Jobs\FinalizeIguideOfflinePackageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\IguideOfflinePackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class IguideOfflinePackageControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function authorized_staff_can_queue_a_structurally_valid_private_package(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'iguide_data' => [
                'provider_payload' => ['work_order' => 'IG-123'],
                'manual_offline_package' => [
                    'status' => 'ready',
                    'file_id' => 77,
                    'original_filename' => 'old.zip',
                ],
            ],
        ]);
        Sanctum::actingAs($admin);

        $response = $this->post("/api/integrations/shoots/{$shoot->id}/iguide/offline-package", [
            'package' => $this->zip([
                '9137 Lakeland Valley/Index.HTML' => '<!doctype html><title>Tour</title>',
                '9137 Lakeland Valley/assets/app.js' => 'console.log("tour")',
            ], '9137 Lakeland Valley - offline_en.zip'),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('manual_offline_package.status', 'scanning')
            ->assertJsonPath('manual_offline_package.original_filename', '9137 Lakeland Valley - offline_en.zip')
            ->assertJsonPath('manual_offline_package.index_entry_path', '9137 Lakeland Valley/Index.HTML')
            ->assertJsonPath(
                'manual_offline_package.publication_attestation.policy',
                'authorized_staff_official_iguide_export'
            )
            ->assertJsonPath('manual_offline_package.publication_attestation.version', 1)
            ->assertJsonPath('manual_offline_package.publication_attestation.audiences', ['branded', 'mls'])
            ->assertJsonPath('manual_offline_package.publication_attestation.attested_by', $admin->id)
            ->assertJsonPath('manual_offline_package.previous_ready.file_id', 77)
            ->assertJsonPath('iguide_data.provider_payload.work_order', 'IG-123');

        $file = ShootFile::query()->where('shoot_id', $shoot->id)->sole();
        $this->assertSame(ShootFile::MEDIA_TYPE_IGUIDE, $file->media_type);
        $this->assertSame(ShootFile::STAGE_ARCHIVED, $file->workflow_stage);
        $this->assertSame(ShootFile::SCAN_STATUS_QUARANTINED, $file->scan_status);
        $this->assertTrue($file->isIguideOfflinePackage());
        $this->assertSame('9137 Lakeland Valley/Index.HTML', data_get($file->metadata, 'index_entry_path'));
        $this->assertStringStartsWith("secure/iguide-packages/{$shoot->id}/", $file->path);
        Storage::disk('local')->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
        Queue::assertPushed(ScanShootFileJob::class, fn (ScanShootFileJob $job) => $job->shootFileId === $file->id);

        $shoot->refresh();
        $this->assertSame('IG-123', data_get($shoot->iguide_data, 'provider_payload.work_order'));
        $this->assertSame('scanning', data_get($shoot->iguide_data, 'manual_offline_package.status'));

        // A failed replacement promotes the prior clean package, and a late
        // markScanning callback cannot hide that restored download.
        $packages = app(IguideOfflinePackageService::class);
        $file->forceFill(['scan_status' => ShootFile::SCAN_STATUS_INFECTED])->save();
        $packages->markFailed($file, 'infected');
        $packages->markScanning($file);
        $restored = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $restored['status']);
        $this->assertSame(77, $restored['file_id']);
        $this->assertSame('The uploaded package did not pass its security scan.', $restored['last_replacement_failure']['error']);
        $this->assertSame($file->id, $restored['last_replacement_failure']['file_id']);
    }

    #[Test]
    public function non_privileged_users_are_forbidden_before_the_package_is_stored(): void
    {
        Storage::fake('local');
        Queue::fake();

        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($client);

        $this->post("/api/integrations/shoots/{$shoot->id}/iguide/offline-package", [
            'package' => $this->zip(['index.html' => '<html></html>']),
        ])->assertForbidden();

        $this->assertDatabaseCount('shoot_files', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function clean_finalization_switches_the_pointer_and_downloads_only_through_the_authenticated_endpoint(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $this->post("/api/integrations/shoots/{$shoot->id}/iguide/offline-package", [
            'package' => $this->zip([
                'tour/index.html' => '<html>tour</html>',
                'tour/app.js' => 'console.log("tour")',
            ], 'offline-tour.zip'),
        ])->assertAccepted();

        $file = ShootFile::query()->where('shoot_id', $shoot->id)->sole();
        $file->update([
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'scan_result' => 'all package members clean',
            'scanned_at' => now(),
        ]);

        (new FinalizeIguideOfflinePackageJob($file->id))->handle(
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );

        $lifecycle = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $lifecycle['status']);
        $this->assertSame($file->id, $lifecycle['file_id']);
        $this->assertSame("/api/shoots/{$shoot->id}/media/{$file->id}/download", $lifecycle['download_url']);

        app(IguideOfflinePackageService::class)->markFailed($file, 'late failure callback');
        $this->assertSame('ready', data_get($shoot->fresh()->iguide_data, 'manual_offline_package.status'));

        // Even a JSON Accept header cannot trade the authenticated request for a
        // public/Dropbox URL; the endpoint streams the private ZIP directly.
        $this->withHeader('Accept', 'application/json')
            ->get("/api/shoots/{$shoot->id}/media/{$file->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    #[Test]
    public function malformed_or_unsafe_packages_are_rejected_before_quarantine_storage(): void
    {
        Storage::fake('local');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->post("/api/integrations/shoots/{$shoot->id}/iguide/offline-package", [
            'package' => $this->zip([
                'tour/index.html' => '<html></html>',
                'tour/shell.php' => '<?php echo "unsafe";',
            ]),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('package');
        $this->assertDatabaseCount('shoot_files', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_non_throwing_storage_failure_creates_no_file_row_and_marks_the_lifecycle_failed(): void
    {
        Queue::fake();

        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->post("/api/integrations/shoots/{$shoot->id}/iguide/offline-package", [
            'package' => $this->zip([
                'tour/index.html' => '<html>tour</html>',
                'tour/app.js' => 'console.log("tour")',
            ]),
        ]);

        $response->assertInternalServerError()
            ->assertJsonPath('manual_offline_package.status', 'failed');
        $this->assertDatabaseCount('shoot_files', 0);
        $this->assertSame('failed', data_get($shoot->fresh()->iguide_data, 'manual_offline_package.status'));
        Queue::assertNothingPushed();
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries, string $name = 'package.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iguide-feature-');
        if ($path === false) {
            $this->fail('Could not allocate a temporary ZIP.');
        }
        $this->temporaryFiles[] = $path;

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $contents) {
            $this->assertTrue($archive->addFromString($entry, $contents));
        }
        $archive->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }
}
