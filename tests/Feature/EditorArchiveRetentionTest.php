<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\Media\EditorArchiveLifecycle;
use App\Services\Media\EditorArchiveRetention;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EditorArchiveRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-22 12:00:00';

    private Shoot $shoot;

    private string $tracking;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Storage::fake('local');
        Storage::fake('public');
        $this->tracking = Storage::disk('local')->path('retention-test');
        config([
            'media.local_disk' => 'local', 'media.legacy_public_disk' => 'public',
            'media.editor_archive_tracking_root' => $this->tracking,
            'media.editor_archive_retention_days' => 14,
            'media.signed_url_ttl' => 604800, 'media.temporary_url_ttl' => 900,
            'media.bright_mls_signed_url_ttl' => 2592000,
            'media.read_from_r2' => false, 'media.r2_only' => false, 'media.dual_write' => false,
            'media.tiered_storage_enabled' => false, 'media.download_offload' => false,
        ]);
        $this->shoot = Shoot::factory()->createQuietly(['status' => 'delivered', 'workflow_status' => 'delivered']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_has_no_filesystem_side_effects_and_first_apply_only_starts_grace(): void
    {
        $key = $this->archive();
        $dry = $this->runCleanup();
        $this->assertSame(1, $dry['eligible_count']);
        $this->assertSame(0, $dry['deleted_count']);
        $this->assertDirectoryDoesNotExist($this->tracking);
        $this->artisan('media:prune-editor-archives', ['--json' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->tracking);
        $apply = $this->runCleanup(true);
        $this->assertSame(0, $apply['deleted_count']);
        $this->assertSame(now()->addDays(31)->format('c'), $apply['grace_until']);
        $this->assertSame(1, $apply['totals']['rollout_grace']['count']);
        Carbon::setTestNow(now()->addDays(2));
        $again = $this->runCleanup(true);
        $this->assertSame($apply['grace_until'], $again['grace_until']);
        Storage::disk('local')->assertExists($key);
    }

    public function test_old_terminal_shoot_caches_on_both_disks_expire_after_grace_and_originals_survive(): void
    {
        $this->expiredGrace();
        $private = $this->archive();
        $legacy = $this->archive('public', str_repeat('b', 40));
        Storage::disk('local')->put('shoots/'.$this->shoot->id.'/raw/source.cr2', 'original bytes');
        Storage::disk('public')->put('shoots/'.$this->shoot->id.'/completed/photo.jpg', 'edited original');
        $this->assertSame(2, $this->runCleanup()['totals']['would_delete']['count']);
        $result = $this->runCleanup(true);
        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame(26, $result['deleted_bytes']);
        Storage::disk('local')->assertMissing($private);
        Storage::disk('public')->assertMissing($legacy);
        $this->assertSame('original bytes', Storage::disk('local')->get('shoots/'.$this->shoot->id.'/raw/source.cr2'));
        $this->assertSame('edited original', Storage::disk('public')->get('shoots/'.$this->shoot->id.'/completed/photo.jpg'));
        $this->assertFileExists($this->tracking.'/'.hash('sha256', $private).'.lock');
        $this->assertSame('delivered', $this->shoot->fresh()->status);
    }

    public function test_exact_inactivity_cutoff_and_link_expiry_equality_are_preserved(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        touch(Storage::disk('local')->path($key), now()->subDays(14)->timestamp);
        $this->assertSame(1, $this->runCleanup(true)['totals']['recently_used']['count']);
        Carbon::setTestNow(now()->addSecond());
        $this->assertSame(1, $this->runCleanup(true)['deleted_count']);
    }

    public function test_active_generation_lock_and_existing_queue_lock_preserve_old_cache(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $lease = app(EditorArchiveLifecycle::class)->acquire($key);
        Carbon::setTestNow(now()->addDays(16));
        $this->assertSame(1, $this->runCleanup(true)['totals']['active_operation']['count']);
        $lease->release();
        Cache::put('editor-raw-zip:'.$this->shoot->id.':'.str_repeat('a', 64), 1, 3600);
        $this->assertSame(1, $this->runCleanup(true)['totals']['queued_or_building']['count']);
        Cache::flush();
        $this->assertSame(1, $this->runCleanup(true)['deleted_count']);
    }

    public function test_signed_url_records_actual_long_ttl_while_holding_the_cleanup_lock(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $lifecycle = app(EditorArchiveLifecycle::class);
        URL::shouldReceive('temporarySignedRoute')->once()->andReturnUsing(function () use ($lifecycle, $key) {
            $this->assertNull($lifecycle->cleanupLock($key, true));
            $this->assertSame(now()->addDays(46)->timestamp, $lifecycle->state($key)['protected_until']);

            return 'https://example.test/valid-signed-link';
        });
        app(MediaStorage::class)->signedAppUrl($key, 45 * 86400);
        Carbon::setTestNow(now()->addDays(46));
        $this->assertSame(1, $this->runCleanup(true)['totals']['protected_link_or_download']['count']);
        Carbon::setTestNow(now()->addSecond());
        $this->assertSame(1, $this->runCleanup(true)['deleted_count']);
    }

    public function test_url_issuance_rechecks_that_archive_exists_after_taking_the_lock(): void
    {
        $key = 'editor-downloads/'.$this->shoot->id.'/'.str_repeat('a', 64).'.zip';
        URL::shouldReceive('temporarySignedRoute')->never();
        try {
            app(MediaStorage::class)->signedAppUrl($key);
            $this->fail('A missing archive must not receive a new signed URL.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $lock = app(EditorArchiveLifecycle::class)->cleanupLock($key, true);
        $this->assertNotNull($lock);
        $lock->release();
    }

    public function test_configured_longer_ttl_extends_initial_grace_without_resetting_it(): void
    {
        config(['media.signed_url_ttl' => 60 * 86400]);
        $this->archive();
        $first = $this->runCleanup(true);
        $this->assertSame(now()->addDays(61)->format('c'), $first['grace_until']);
        Carbon::setTestNow(now()->addDays(62));
        $next = $this->runCleanup(true);
        $this->assertSame($first['grace_until'], $next['grace_until']);
        $this->assertSame(1, $next['deleted_count']);
    }

    public function test_php_stream_holds_its_lock_until_the_response_callback_finishes(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $response = app(MediaStorage::class)->streamResponse($key, 'application/zip');
        Carbon::setTestNow(now()->addDays(16));
        $this->assertSame(1, $this->runCleanup(true)['totals']['active_operation']['count']);
        ob_start();
        $response->sendContent();
        $this->assertSame('archive bytes', ob_get_clean());
        $this->assertSame(1, $this->runCleanup(true)['deleted_count']);
    }

    public function test_offloaded_response_renews_usage_and_leaves_delivery_grace(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $lifecycle = app(EditorArchiveLifecycle::class);
        $lifecycle->withResponse($key, fn () => response('', 200, ['X-Accel-Redirect' => '/protected/archive.zip']));
        $this->assertSame(now()->addDay()->timestamp, $lifecycle->state($key)['protected_until']);
        $this->assertSame(1, $this->runCleanup(true)['totals']['protected_link_or_download']['count']);
        Carbon::setTestNow(now()->addDays(13));
        $this->assertSame(1, $this->runCleanup(true)['totals']['recently_used']['count']);
    }

    public function test_unknown_paths_partial_files_symlinks_and_hardlinks_are_never_deleted(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $path = Storage::disk('local')->path($key);
        $original = Storage::disk('local')->path('original.cr2');
        file_put_contents($original, 'original bytes');
        unlink($path);
        symlink($original, $path);
        $hardKey = 'editor-downloads/'.$this->shoot->id.'/'.str_repeat('b', 64).'.zip';
        link($original, Storage::disk('local')->path($hardKey));
        Storage::disk('local')->put('editor-downloads/'.$this->shoot->id.'/unknown.zip', 'unknown');
        Storage::disk('local')->put('editor-downloads/'.$this->shoot->id.'/.'.str_repeat('c', 64).'.zip.archive-123', 'in progress');
        $outside = Storage::disk('public')->path('outside');
        mkdir($outside);
        file_put_contents($outside.'/'.str_repeat('d', 64).'.zip', 'external file');
        symlink($outside, Storage::disk('local')->path('editor-downloads/999999'));
        $report = $this->runCleanup(true);
        $this->assertSame(0, $report['deleted_count']);
        $this->assertSame(2, $report['totals']['unsafe_file']['count']);
        $this->assertSame(2, $report['totals']['unknown_path']['count']);
        $this->assertSame('original bytes', file_get_contents($original));
        $this->assertSame('external file', file_get_contents($outside.'/'.str_repeat('d', 64).'.zip'));
    }

    public function test_corrupt_or_linked_usage_metadata_fails_closed(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $metadata = $this->tracking.'/'.hash('sha256', $key).'.json';
        file_put_contents($metadata, '{invalid');
        $this->assertSame(1, $this->runCleanup(true)['totals']['inspection_failed']['count']);
        Storage::disk('local')->assertExists($key);
        unlink($metadata);
        symlink($this->tracking.'/rollout.json', $metadata);
        $this->assertSame(1, $this->runCleanup(true)['totals']['inspection_failed']['count']);
        Storage::disk('local')->assertExists($key);
    }

    public function test_active_shoots_and_unknown_shoot_ids_are_preserved(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        foreach (['editing', 'review', 'on_hold', 'scheduled', 'ready'] as $status) {
            $this->shoot->forceFill(['status' => $status, 'workflow_status' => $status])->saveQuietly();
            $this->assertSame(1, $this->runCleanup(true)['totals']['active_shoot']['count']);
        }
        $this->shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'editing'])->saveQuietly();
        $this->assertSame(1, $this->runCleanup(true)['totals']['active_shoot']['count']);
        Storage::disk('local')->put('editor-downloads/999999/'.str_repeat('a', 64).'.zip', 'unknown shoot');
        touch(Storage::disk('local')->path('editor-downloads/999999/'.str_repeat('a', 64).'.zip'), now()->subDays(40)->timestamp);
        $this->assertSame(1, $this->runCleanup(true)['totals']['unknown_shoot']['count']);
        Storage::disk('local')->assertExists($key);
    }

    public function test_invalid_rollout_record_aborts_before_deleting_anything(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        foreach ([['unexpected' => true], ['started_at' => now()->timestamp, 'protected_until' => 0, 'grace_seconds' => 691200],
            ['started_at' => now()->subDays(40)->timestamp, 'protected_until' => now()->subDays(39)->timestamp, 'grace_seconds' => 691200]] as $invalid) {
            file_put_contents($this->tracking.'/rollout.json', json_encode($invalid));
            $this->artisan('media:prune-editor-archives', ['--apply' => true])->assertFailed();
            Storage::disk('local')->assertExists($key);
        }
    }

    public function test_symlinked_disk_root_is_not_followed(): void
    {
        $this->expiredGrace();
        $key = $this->archive();
        $linkedRoot = Storage::disk('public')->path('linked-private');
        symlink(rtrim(Storage::disk('local')->path(''), '/'), $linkedRoot);
        config(['filesystems.disks.linked' => ['driver' => 'local', 'root' => $linkedRoot], 'media.local_disk' => 'linked']);
        $report = $this->runCleanup(true);
        $this->assertSame(0, $report['deleted_count']);
        $this->assertSame(1, $report['totals']['unsafe_or_unreadable_directory']['count']);
        Storage::disk('local')->assertExists($key);
    }

    private function expiredGrace(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW)->subDays(40));
        app(EditorArchiveLifecycle::class)->rollout(true);
        Carbon::setTestNow(self::NOW);
    }

    private function archive(string $disk = 'local', ?string $signature = null): string
    {
        $key = 'editor-downloads/'.$this->shoot->id.'/'.($signature ?? str_repeat('a', 64)).'.zip';
        Storage::disk($disk)->put($key, 'archive bytes');
        touch(Storage::disk($disk)->path($key), now()->subDays(40)->timestamp);

        return $key;
    }

    private function runCleanup(bool $apply = false): array
    {
        return app(EditorArchiveRetention::class)->run($apply);
    }
}
