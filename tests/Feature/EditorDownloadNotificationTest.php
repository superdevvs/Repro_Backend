<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Jobs\GenerateEditorRawZipJob;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\EditorRawArchiveService;
use App\Services\Shoots\ShootEditorDownloadService;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class EditorDownloadNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;
    private User $editor;
    private User $admin;
    private Collection $files;
    private array $archiveResponse;
    private ShootMediaArchiveService $archives;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([ShootActivityBroadcast::class]);
        Http::preventStrayRequests();
        Storage::fake('local');
        Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'mobile_notifications.enabled' => true, 'media.local_disk' => 'local',
            'media.tiered_storage_enabled' => false, 'media.download_offload' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false]);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->shoot = Shoot::factory()->create(['editor_id' => $this->editor->id,
            'status' => Shoot::STATUS_EDITING, 'workflow_status' => Shoot::STATUS_EDITING]);
        $this->files = collect(['front', 'kitchen'])->map(function ($name) {
            $path = "shoots/{$this->shoot->id}/todo/{$name}.jpg";
            Storage::disk('local')->put($path, 'raw-'.$name);
            return ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => $name.'.jpg',
                'stored_filename' => $name.'.jpg', 'path' => $path, 'storage_path' => $path,
                'mime_type' => 'image/jpeg', 'file_type' => 'image/jpeg', 'file_size' => 12,
                'uploaded_by' => $this->admin->id, 'media_type' => 'raw',
                'workflow_stage' => ShootFile::STAGE_TODO, 'scan_status' => 'clean']);
        });
        MobileNotificationDevice::create(['id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'personal_access_token_id' => $this->admin->createToken('isolated-download-test')->accessToken->id,
            'token' => 'aabb', 'sequence' => 1, 'environment' => 'sandbox',
            'permission' => 'authorized', 'expires_at' => now()->addDay()]);
        $this->archiveResponse = ['status' => 202, 'payload' => ['type' => 'preparing', 'poll_after_ms' => 3000]];
        $this->archives = Mockery::mock(ShootMediaArchiveService::class);
        $this->archives->shouldReceive('getFilesForType')->andReturnUsing(fn () => $this->files);
        $this->archives->shouldReceive('resolveArchiveResponseData')->andReturnUsing(fn () => $this->archiveResponse);
        app()->instance(ShootMediaArchiveService::class, $this->archives);
    }

    private function download(?array $ids = null, ?User $user = null)
    {
        $request = Request::create("/api/shoots/{$this->shoot->id}/editor-download-raw", 'GET',
            $ids === null ? [] : ['file_ids' => $ids]);
        return app(ShootEditorDownloadService::class)->downloadRaw($request, $this->shoot, $user ?? $this->editor);
    }

    private function ready(): void
    {
        $this->archiveResponse = ['status' => 200, 'payload' => [
            'type' => 'redirect', 'url' => 'https://example.test/ready-raw.zip',
        ]];
    }

    private function assertSilent(): void
    {
        $this->assertSame(0, ShootActivityLog::count());
        $this->assertSame(0, MobileNotificationDelivery::count());
        Event::assertNotDispatched(ShootActivityBroadcast::class);
    }

    public function test_more_than_150_preparation_polls_are_silent_then_ready_retries_notify_once(): void
    {
        for ($poll = 0; $poll < 151; $poll++) {
            $this->assertSame(202, $this->download()->getStatusCode());
        }
        $this->assertSilent();
        $this->ready();
        for ($retry = 0; $retry < 5; $retry++) {
            $this->assertSame(200, $this->download()->getStatusCode());
        }
        $log = ShootActivityLog::sole();
        $this->assertSame('raw_downloaded_by_editor', $log->action);
        $this->assertTrue($log->metadata['download_ready']);
        $this->assertSame(2, $log->metadata['file_count']);
        $this->assertSame(1, MobileNotificationDelivery::where('source_id', $log->id)->count());
        Event::assertDispatchedTimes(ShootActivityBroadcast::class, 1);
    }

    public function test_scoped_selection_polls_are_silent_and_a_ready_archive_notifies_once(): void
    {
        $ids = [$this->files->first()->id];
        for ($poll = 0; $poll < 4; $poll++) {
            $this->assertSame(202, $this->download($ids)->getStatusCode());
        }
        $this->assertSilent();
        Queue::assertPushed(GenerateEditorRawZipJob::class, 1);
        $descriptor = app(EditorRawArchiveService::class)->descriptor($this->shoot, $this->editor, $this->files->take(1));
        Storage::disk('local')->put($descriptor['storage_path'], 'PK-ready-fixture');
        for ($retry = 0; $retry < 3; $retry++) {
            $response = $this->download($ids);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('redirect', $response->getData(true)['type']);
        }
        $log = ShootActivityLog::sole();
        $this->assertSame(1, $log->metadata['file_count']);
        $this->assertSame(1, MobileNotificationDelivery::where('source_id', $log->id)->count());
        Event::assertDispatchedTimes(ShootActivityBroadcast::class, 1);
    }

    public function test_error_or_missing_ready_url_never_records_a_download(): void
    {
        foreach ([['status' => 404, 'payload' => ['error' => 'No files']],
            ['status' => 200, 'payload' => ['type' => 'redirect', 'url' => '']],
            ['status' => 500, 'payload' => ['type' => 'redirect', 'url' => 'https://example.test/not-ready.zip']],
        ] as $response) {
            $this->archiveResponse = $response;
            $this->download();
        }
        $this->assertSilent();
    }

    public function test_cache_clear_and_changed_signed_url_do_not_repeat_a_ready_notification(): void
    {
        $this->ready();
        $this->download();
        Cache::flush();
        $this->archiveResponse['payload']['url'] .= '?expires=99999&signature=changed';
        $this->files = $this->files->reverse()->values();
        $this->download();
        $this->assertSame(1, ShootActivityLog::count());
        $this->assertSame(1, MobileNotificationDelivery::count());
    }

    public function test_new_raw_file_version_is_a_new_download_even_with_the_same_file_count(): void
    {
        $this->ready();
        $this->download();
        $this->files->first()->update(['file_size' => 24]);
        $this->download();
        $this->assertSame(2, ShootActivityLog::count());
        $this->assertSame(2, MobileNotificationDelivery::count());
    }

    public function test_different_downloader_and_later_download_are_not_suppressed(): void
    {
        $this->ready();
        $this->download();
        $this->download(user: $this->admin);
        $this->assertSame(2, ShootActivityLog::count());
        $this->assertSame(1, ShootActivityLog::where('action', 'raw_downloaded_by_admin')->count());
        $this->travel(31)->minutes();
        $this->download();
        $this->assertSame(3, ShootActivityLog::count());
        $this->assertSame(2, MobileNotificationDelivery::count());
    }

    public function test_empty_selection_does_not_generate_download_activity(): void
    {
        $this->files = collect();
        $this->assertSame(404, $this->download()->getStatusCode());
        $this->assertSilent();
    }
}
