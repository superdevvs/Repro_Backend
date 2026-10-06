<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\Actions\FinalizeEditedUploadAction;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditedSubmissionContentionTest extends TestCase
{
    public function test_submission_reserves_real_sqlite_writer_before_reads_and_commits_effects_once(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edited-submit-');
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $path]);
        Queue::fake();
        Storage::fake('public');
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=50');
            $manager = User::factory()->create(['role' => 'editing_manager']);
            // Isolated shape of #396; no customer data or production writes.
            $shoot = Shoot::factory()->create(['workflow_status' => 'editing', 'status' => 'editing']);
            foreach (['todo' => 150, 'completed' => 30] as $stage => $count) {
                for ($index = 0; $index < $count; $index++) {
                    ShootFile::create(['shoot_id' => $shoot->id, 'filename' => "$stage-$index.jpg", 'stored_filename' => "$stage-$index.jpg",
                        'path' => "$stage/$index.jpg", 'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $manager->id,
                        'media_type' => $stage === 'todo' ? 'raw' : 'edited', 'workflow_stage' => $stage, 'scan_status' => 'clean']);
                }
            }
            $writer = new \PDO('sqlite:'.$path);
            $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');
            $attempts = 0;
            $blocked = 0;
            $support = \Mockery::mock(ShootMediaMutationSupportService::class, [app(\App\Services\Shoots\ShootAuthorizationSupport::class)])->makePartial();
            $support->shouldReceive('refreshMediaCounters')->andReturnUsing(function ($fresh) use (&$attempts, &$blocked, $writer) {
                $this->assertSame(1, DB::transactionLevel());
                ++$attempts;
                try {
                    // An independent writer tries to invalidate every read snapshot.
                    // Without the reservation this exhausts all four retries.
                    $writer->exec('UPDATE shoots SET raw_photo_count = 777 WHERE id = '.(int) $fresh->id);
                } catch (\PDOException $exception) {
                    $this->assertSame(5, (int) $exception->errorInfo[1]);
                    ++$blocked;
                }
                return app(ShootMediaMutationSupportService::class)->refreshMediaCounters($fresh);
            });
            $support->shouldReceive('clearShootFilesCache')->twice()->andReturnUsing(function () {
                $this->assertSame(0, DB::transactionLevel());
            });
            $automation = \Mockery::mock(AutomationService::class);
            $automation->shouldReceive('buildShootContext')->once()->andReturn([]);
            $automation->shouldReceive('handleEvent')->once()->with('EDITING_COMPLETE', \Mockery::type('array'))->andReturnUsing(function () {
                $this->assertSame(0, DB::transactionLevel());
                return [];
            });
            $activity = \Mockery::mock(ShootActivityLogger::class);
            $activity->shouldReceive('log')->once()->andReturnUsing(function () {
                $this->assertSame(0, DB::transactionLevel());
                return new \App\Models\ShootActivityLog;
            });
            Log::spy();
            $action = new FinalizeEditedUploadAction($support, $activity, $automation);
            $result = $action->execute($shoot, $manager);
            $this->assertSame(200, $result['status'], json_encode($result));
            $this->assertSame(1, $attempts);
            $this->assertSame($attempts, $blocked);
            $this->assertSame('ready', $shoot->fresh()->workflow_status);
            $this->assertSame(30, (int) $shoot->fresh()->edited_photo_count);
            $this->assertSame(150, (int) $shoot->fresh()->raw_photo_count);
            Log::shouldNotHaveReceived('warning');
            // Lost response / double click: no second transition, notification or activity.
            $repeat = $action->execute($shoot->fresh(), $manager);
            $this->assertSame(200, $repeat['status']);
            $this->assertFalse($repeat['payload']['workflow_status_changed']);
            $this->assertSame(1, $shoot->workflowLogs()->where('action', 'status_changed_to_ready')->count());

            // A writer that never releases is bounded and returns a safe retryable
            // failure. The previously committed status and notifications stay intact.
            $shoot->fresh()->update(['raw_photo_count' => 0]);
            $writer->beginTransaction();
            $writer->exec('UPDATE shoots SET raw_photo_count = 888 WHERE id = '.(int) $shoot->id);
            $busy = $action->execute($shoot->fresh(), $manager);
            $writer->rollBack();
            $this->assertSame(503, $busy['status']);
            $this->assertTrue($busy['payload']['retryable']);
            $this->assertSame('submission_busy', $busy['payload']['error_type']);
            $this->assertArrayNotHasKey('error', $busy['payload']);
            $this->assertNotEmpty($busy['payload']['correlation_id']);
            $this->assertSame('ready', $shoot->fresh()->workflow_status);
            $this->assertSame(2, $attempts, 'A held writer fails before reading any submission decisions.');
            $this->assertSame($attempts, $blocked);
            $writer->exec('UPDATE shoots SET raw_photo_count = 999 WHERE id = '.(int) $shoot->id);
            $this->assertSame(999, (int) $shoot->fresh()->raw_photo_count, 'The failed response must release its transaction and write reservation.');
            Log::shouldHaveReceived('error')->with('Edited submission failed', \Mockery::on(fn ($context) => $context['correlation_id'] === $busy['payload']['correlation_id'] && $context['exception'] instanceof \Throwable))->once();
        } finally {
            DB::purge('sqlite');
            $writer = null;
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) if (is_file($file)) unlink($file);
        }
    }
}
