<?php

namespace Tests\Feature;

use App\Jobs\MergeStudioHdr;
use App\Jobs\ProcessStudioWorkspace;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StudioWorkspaceLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_hdr_uses_shared_database_lock_when_file_cache_cannot_create_directories(): void
    {
        $blockedPath = tempnam(sys_get_temp_dir(), 'hdr-cache-');
        config(['cache.default' => 'file', 'cache.stores.file.path' => $blockedPath, 'cache.stores.file.lock_path' => $blockedPath]);
        Cache::forgetDriver('file');
        app()->forgetInstance('cache.store');
        $job = new MergeStudioHdr(['id' => 'hdr-stack'], 1, 1);
        $middleware = $job->middleware()[0];
        $before = now()->timestamp;
        try {
            $middleware->handle($job, function () use ($before): void {
                $this->assertSame(1, DB::table('cache_locks')->count());
                $expiresAt = DB::table('cache_locks')->value('expiration');
                $this->assertGreaterThanOrEqual($before + 660, $expiresAt);
                $this->assertLessThanOrEqual($before + 661, $expiresAt);
                throw new RuntimeException('HDR worker stopped');
            });
            $this->fail('Expected the simulated worker failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HDR worker stopped', $exception->getMessage());
        } finally {
            Cache::forgetDriver('file');
            unlink($blockedPath);
        }
        $this->assertSame(0, DB::table('cache_locks')->count(), 'A failed HDR worker must release its lease.');
    }

    public function test_overlapping_hdr_merge_is_discarded_without_releasing_it_to_the_queue(): void
    {
        $job = new MergeStudioHdr(['id' => 'hdr-stack'], 1, 1);
        $queued = Mockery::mock(Job::class);
        $queued->shouldNotReceive('release');
        $job->setJob($queued);
        $middleware = $job->middleware()[0];
        $held = Cache::store('database')->lock($middleware->getLockKey($job), 660);
        $this->assertTrue($held->get());
        try {
            $middleware->handle($job, fn () => $this->fail('An overlapping HDR merge must not run.'));
            $this->assertSame(1, DB::table('cache_locks')->count(), 'The duplicate must retain the original worker lease.');
        } finally {
            $held->release();
        }
    }

    public function test_overlapping_workspace_processing_retains_its_thirty_second_retry(): void
    {
        $job = new ProcessStudioWorkspace('workspace', 'operation');
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(30);
        $job->setJob($queued);
        $middleware = $job->middleware()[0];
        $held = Cache::store('database')->lock($middleware->getLockKey($job), 7260);
        $this->assertTrue($held->get());
        try {
            $middleware->handle($job, fn () => $this->fail('Overlapping workspace processing must not run.'));
            $this->assertSame(1, DB::table('cache_locks')->count());
        } finally {
            $held->release();
        }
    }
}
