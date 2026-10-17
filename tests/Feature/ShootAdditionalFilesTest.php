<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootAdditionalFile, ShootFile, ShootService, User};
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\AdditionalFileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class ShootAdditionalFilesTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); Storage::fake('public'); Queue::fake();
        $scanner = Mockery::mock(ClamAvClient::class);
        $scanner->shouldReceive('scan')->andReturn(ClamAvScanResult::clean());
        app()->instance(ClamAvClient::class, $scanner);
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->andReturnUsing(fn (Shoot $shoot, UploadedFile $file, int $actor, $category = null, $mediaType = null, $scope = null) => ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $scope, 'filename' => $file->getClientOriginalName(),
            'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$shoot->id.'/todo/'.$file->getClientOriginalName(),
            'file_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'media_type' => 'raw',
            'uploaded_by' => $actor, 'workflow_stage' => 'todo',
        ]));
        app()->instance(ShootMediaStorageService::class, $storage);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    private function fixture(string $intake = 'none'): array
    {
        $actor = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $actor->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled', 'total_quote' => 0, 'base_quote' => 0, 'tax_amount' => 0]);
        $service = Service::factory()->create(['upload_intake_type' => $intake, 'uses_hdr_brackets' => $intake !== 'none', 'photo_count' => 10]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'photographer_id' => $actor->id, 'quantity' => 1, 'price' => 0, 'bracket_mode' => 3]);
        Sanctum::actingAs($actor);
        return [$shoot->fresh(), $actor, $item];
    }

    private function photo(): UploadedFile { return UploadedFile::fake()->image('frame.jpg', 20, 20); }
    private function photoBytes(): string { $file = $this->photo(); return file_get_contents($file->getRealPath()); }

    private function zip(array $entries, array $symlinks = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'additional-zip'); $this->temporary[] = $path;
        $zip = new ZipArchive; $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            if (in_array($name, $symlinks, true)) $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        return new UploadedFile($path, 'delivery.zip', 'application/zip', null, true);
    }

    private function upload(Shoot $shoot, UploadedFile $file, array $fields = [])
    {
        return $this->post('/api/shoots/'.$shoot->id.'/upload', $fields + [
            'files' => [$file], 'upload_type' => 'additional', 'idempotency_key' => 'additional-fixture', 'route_media' => '1',
        ], ['Accept' => 'application/json']);
    }

    public function test_non_capture_shoot_accepts_private_additional_media_without_counts_billing_or_workflow_changes(): void
    {
        [$shoot] = $this->fixture();
        $before = $shoot->only(['status', 'workflow_status', 'total_quote', 'expected_raw_count', 'raw_photo_count']);
        $this->upload($shoot, $this->photo())->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('file_outcomes.0.destination', 'additional');
        $this->assertSame(0, ShootFile::count()); $this->assertSame(1, ShootAdditionalFile::count());
        $this->assertSame($before, $shoot->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('payments', 0); $this->assertDatabaseCount('editor_payouts', 0);
        $file = ShootAdditionalFile::sole();
        $this->getJson('/api/shoots/'.$shoot->id.'/additional-files')->assertOk()->assertJsonCount(1, 'files');
        $this->get('/api/shoots/'.$shoot->id.'/additional-files/'.$file->id.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($file->path, $this->getJson('/api/shoots/'.$shoot->id.'/additional-files')->getContent());
    }

    public function test_zip_routes_photos_and_video_to_the_correct_service_and_never_brackets_video(): void
    {
        [$shoot, , $item] = $this->fixture('photo_video');
        $jpeg = $this->photoBytes();
        $video = pack('N', 24).'ftypXAVC'.pack('N', 0).'XAVCmp42'.str_repeat("\0", 64);
        $entries = ['camera/1.jpg' => $jpeg, 'camera/2.jpg' => $jpeg, 'clips/tour.mp4' => $video];
        $archive = $this->zip($entries); $archiveBytes = file_get_contents($archive->getRealPath());
        $this->upload($shoot, $archive)->assertOk()->assertJsonPath('success_count', 4)->assertJsonCount(3, 'uploaded_files');
        $this->assertSame(3, ShootFile::count()); $this->assertSame(1, ShootAdditionalFile::count());
        $this->assertSame([1, 2], ShootFile::where('file_type', 'not like', 'video/%')->orderBy('id')->pluck('sequence')->all());
        $this->assertNull(ShootFile::where('file_type', 'like', 'video/%')->sole()->bracket_group);
        $this->assertSame([$item->id], ShootFile::distinct()->pluck('shoot_service_id')->all());
        $retry = tempnam(sys_get_temp_dir(), 'archive-retry'); $this->temporary[] = $retry; file_put_contents($retry, $archiveBytes);
        $this->upload($shoot, new UploadedFile($retry, 'delivery.zip', 'application/zip', null, true))->assertOk();
        $this->assertSame(3, ShootFile::count()); $this->assertSame(1, ShootAdditionalFile::count());
    }

    public function test_explicit_keep_and_ambiguous_services_do_not_start_editing(): void
    {
        [$shoot, $actor] = $this->fixture('photo');
        $this->upload($shoot, $this->photo(), ['route_media' => '0'])->assertOk()->assertJsonPath('file_outcomes.0.destination', 'additional');
        $second = Service::factory()->create(['upload_intake_type' => 'photo']);
        ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $second->id, 'photographer_id' => $actor->id, 'price' => 0, 'quantity' => 1]);
        $this->upload($shoot, $this->photo(), ['idempotency_key' => 'ambiguous'])->assertOk()->assertJsonPath('file_outcomes.0.destination', 'additional');
        $this->assertSame(0, ShootFile::count());
    }

    public function test_zip_validation_is_atomic_for_traversal_nested_archives_and_unsupported_contents(): void
    {
        [$shoot] = $this->fixture('photo');
        foreach (['../escape.jpg' => 'bad', 'nested.zip' => 'bad', 'run.php' => '<?php echo 1;'] as $name => $bytes) {
            $this->upload($shoot, $this->zip(['safe.jpg' => $this->photoBytes(), $name => $bytes]), ['idempotency_key' => hash('sha256', $name)])->assertUnprocessable();
        }
        $this->assertSame(0, ShootFile::count()); $this->assertSame(0, ShootAdditionalFile::count());
    }

    public function test_infected_zip_entry_rejects_the_whole_archive_and_scanner_outage_is_retryable(): void
    {
        [$shoot] = $this->fixture();
        $scanner = Mockery::mock(ClamAvClient::class);
        $scanner->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::clean());
        $scanner->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::infected('fixture'));
        app()->instance(ClamAvClient::class, $scanner);
        $this->upload($shoot, $this->zip(['photo.jpg' => $this->photoBytes()]))->assertUnprocessable();
        $this->assertSame(0, ShootAdditionalFile::count());
        $scanner = Mockery::mock(ClamAvClient::class); $scanner->shouldReceive('scan')->andThrow(new \RuntimeException('offline')); app()->instance(ClamAvClient::class, $scanner);
        $photo = $this->photo(); $bytes = file_get_contents($photo->getRealPath());
        $this->upload($shoot, $photo, ['idempotency_key' => 'outage'])->assertStatus(503)->assertJsonPath('errors.0.retryable', true);
        $scanner = Mockery::mock(ClamAvClient::class); $scanner->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()); app()->instance(ClamAvClient::class, $scanner);
        $path = tempnam(sys_get_temp_dir(), 'retry-photo'); $this->temporary[] = $path; file_put_contents($path, $bytes);
        $this->upload($shoot, new UploadedFile($path, 'frame.jpg', 'image/jpeg', null, true), ['idempotency_key' => 'outage'])->assertOk();
        $this->assertSame(1, ShootAdditionalFile::count());
    }

    public function test_assignments_are_rechecked_for_upload_list_download_and_cached_models(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $this->upload($shoot, $this->photo(), ['shoot_service_id' => $item->id])->assertOk();
        $file = ShootAdditionalFile::sole(); $shoot->load('serviceItems', 'services');
        $next = User::factory()->create(['role' => 'photographer']);
        $item->update(['photographer_id' => $next->id]); $shoot->photographer_id = $next->id; $shoot->save();
        $this->assertFalse(app(AdditionalFileAccess::class)->canRead($shoot, $file, $actor));
        $this->get('/api/shoots/'.$shoot->id.'/additional-files/'.$file->id.'/download')->assertForbidden();
        $this->getJson('/api/shoots/'.$shoot->id.'/additional-files')->assertForbidden();
        $this->upload($shoot, $this->photo(), ['idempotency_key' => 'revoked'])->assertForbidden();
        Sanctum::actingAs($next);
        $this->get('/api/shoots/'.$shoot->id.'/additional-files/'.$file->id.'/download')->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/shoots/'.$shoot->id.'/additional-files')->assertForbidden();
        $this->upload($shoot, $this->photo(), ['idempotency_key' => 'client'])->assertForbidden();
    }

    public function test_chunked_additional_upload_rechecks_reassignment_at_completion(): void
    {
        [$shoot, , $item] = $this->fixture(); $bytes = $this->photoBytes();
        $init = $this->postJson('/api/shoots/'.$shoot->id.'/upload-sessions', ['filename' => 'frame.jpg', 'size_bytes' => strlen($bytes), 'upload_type' => 'additional',
            'fields' => ['upload_type' => 'additional', 'idempotency_key' => 'chunked-additional', 'shoot_service_id' => (string) $item->id]])->assertCreated();
        $id = $init->json('session_id');
        $this->call('PUT', '/api/shoots/'.$shoot->id.'/upload-sessions/'.$id.'/chunks/0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($bytes)], $bytes)->assertOk();
        $next = User::factory()->create(['role' => 'photographer']); $item->update(['photographer_id' => $next->id]);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-sessions/'.$id.'/complete')->assertForbidden();
        $this->assertSame(0, ShootAdditionalFile::count());
    }

    public function test_showcase_backfill_preserves_prices_counts_and_custom_capabilities(): void
    {
        $premium = Service::factory()->create(['id' => 85, 'name' => 'Zillow SHOWCASE Premium', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false, 'price' => 425, 'photo_count' => null]);
        $standard = Service::factory()->create(['id' => 86, 'name' => 'ZILLOW SHOWCASE Standard', 'upload_intake_type' => 'photo_video', 'uses_hdr_brackets' => false]);
        $migration = require database_path('migrations/2026_10_17_180000_enable_showcase_intake_and_additional_files.php'); $migration->up();
        $this->assertSame('photo', $premium->fresh()->upload_intake_type); $this->assertTrue($premium->fresh()->uses_hdr_brackets);
        $this->assertSame(425.0, (float) $premium->fresh()->price); $this->assertNull($premium->fresh()->photo_count);
        $this->assertSame('photo_video', $standard->fresh()->upload_intake_type); $this->assertFalse($standard->fresh()->uses_hdr_brackets);
    }

    public function test_zip_rejects_links_case_collisions_extreme_compression_and_excess_entries_before_acceptance(): void
    {
        [$shoot] = $this->fixture(); $jpeg = $this->photoBytes();
        $archives = [
            $this->zip(['link.jpg' => $jpeg], ['link.jpg']),
            $this->zip(['A.jpg' => $jpeg, 'a.jpg' => $jpeg]),
            $this->zip(['bomb.jpg' => str_repeat('A', 2 * 1024 * 1024)]),
            $this->zip(array_fill_keys(array_map(fn ($i) => $i.'.jpg', range(1, 1001)), 'x')),
        ];
        foreach ($archives as $i => $archive) $this->upload($shoot, $archive, ['idempotency_key' => 'limits-'.$i])->assertUnprocessable();
        $this->assertSame(0, ShootAdditionalFile::count()); $this->assertSame(0, ShootFile::count());
    }

    public function test_partial_zip_retry_resumes_failed_entries_without_duplicating_accepted_files(): void
    {
        [$shoot] = $this->fixture('photo');
        $calls = 0;
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->andReturnUsing(function (Shoot $target, UploadedFile $file, int $actor, $category = null, $type = null, $scope = null) use (&$calls) {
            if (++$calls === 2) throw new \RuntimeException('Temporary storage failure');
            return ShootFile::create(['shoot_id' => $target->id, 'shoot_service_id' => $scope, 'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg', 'file_size' => $file->getSize(), 'media_type' => 'raw', 'uploaded_by' => $actor, 'workflow_stage' => 'todo']);
        });
        app()->instance(ShootMediaStorageService::class, $storage);
        $archive = $this->zip(['one.jpg' => $this->photoBytes(), 'two.jpg' => $this->photoBytes()]); $bytes = file_get_contents($archive->getRealPath());
        $this->upload($shoot, $archive)->assertStatus(207)->assertJsonPath('error_count', 1);
        $this->assertSame(1, ShootFile::count());
        $retry = tempnam(sys_get_temp_dir(), 'partial-retry'); $this->temporary[] = $retry; file_put_contents($retry, $bytes);
        $this->upload($shoot, new UploadedFile($retry, 'delivery.zip', 'application/zip', null, true))->assertOk()->assertJsonPath('error_count', 0);
        $this->assertSame(2, ShootFile::count()); $this->assertSame(1, ShootAdditionalFile::count()); $this->assertSame(3, $calls);
    }

    public function test_replay_does_not_return_an_old_service_result_when_the_actor_keeps_another_assignment(): void
    {
        [$shoot, , $item] = $this->fixture('photo'); $photo = $this->photo(); $bytes = file_get_contents($photo->getRealPath());
        $this->upload($shoot, $photo)->assertOk();
        $item->update(['photographer_id' => User::factory()->create(['role' => 'photographer'])->id]);
        $retry = tempnam(sys_get_temp_dir(), 'scope-retry'); $this->temporary[] = $retry; file_put_contents($retry, $bytes);
        $this->upload($shoot, new UploadedFile($retry, 'frame.jpg', 'image/jpeg', null, true))->assertForbidden();
        $this->assertSame(1, ShootFile::count()); $this->assertSame(0, ShootAdditionalFile::count());
    }
}
