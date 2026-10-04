<?php

namespace Tests\Feature;

use App\Models\{Category, Service, Shoot, ShootEditingDispatch, ShootFile, User};
use App\Jobs\{PrepareEditingDispatch, ProcessStudioWorkspace};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class EditingDispatchContentionTest extends TestCase
{
    public function test_dispatch_reserves_sqlite_writer_before_reads_and_keeps_replay_and_conflict_atomic(): void
    {
        $this->withDatabase(function ($shoot, $file, $writer) {
            $interleavings = 0;
            $blocked = 0;
            DB::listen(function (QueryExecuted $query) use ($shoot, $writer, &$interleavings, &$blocked) {
                if (DB::transactionLevel() !== 1 || !str_starts_with(strtolower($query->sql), 'select')
                    || !str_contains($query->sql, 'shoot_editing_dispatches')) {
                    return;
                }
                ++$interleavings;
                try {
                    $writer->exec('UPDATE shoots SET raw_photo_count = raw_photo_count + 1 WHERE id = '.(int) $shoot->id);
                } catch (\PDOException $exception) {
                    $this->assertSame(5, (int) $exception->errorInfo[1]);
                    ++$blocked;
                }
            });
            $payload = $this->payload($file);
            $response = $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload)->assertAccepted();
            $id = $response->json('data.dispatchId');
            $this->assertGreaterThan(0, $interleavings);
            $this->assertSame($interleavings, $blocked, 'Competing commits must not invalidate the dispatch read snapshot.');
            $this->assertSame(0, (int) $shoot->fresh()->raw_photo_count);
            $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload)
                ->assertAccepted()->assertJsonPath('data.dispatchId', $id);
            $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload+['instructions'=>'changed'])
                ->assertConflict();
            $this->assertDatabaseCount('shoot_editing_dispatches', 1);
            $this->assertDatabaseCount('shoot_editing_dispatch_items', 1);
            Queue::assertNotPushed(PrepareEditingDispatch::class);
            Queue::assertNotPushed(ProcessStudioWorkspace::class);
            $writer->exec('UPDATE shoots SET raw_photo_count = 9 WHERE id = '.(int) $shoot->id);
            $this->assertSame(9, (int) $shoot->fresh()->raw_photo_count, 'No write lock may survive the HTTP response.');
        });
    }

    public function test_held_writer_returns_bounded_retryable_response_and_same_identity_can_finish_after_unlock(): void
    {
        $this->withDatabase(function ($shoot, $file, $writer) {
            $payload = $this->payload($file);
            $writer->beginTransaction();
            $writer->exec('UPDATE shoots SET raw_photo_count = 8 WHERE id = '.(int) $shoot->id);
            try {
                $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload)
                    ->assertStatus(503)->assertHeader('Retry-After', '1')
                    ->assertJsonPath('error_type', 'editing_dispatch_busy')
                    ->assertJsonPath('retryable', true)->assertJsonPath('dispatch_request_id', $payload['request_id']);
                $this->assertDatabaseCount('shoot_editing_dispatches', 0);
                $this->assertDatabaseCount('shoot_editing_dispatch_items', 0);
                Queue::assertNotPushed(PrepareEditingDispatch::class);
                Queue::assertNotPushed(ProcessStudioWorkspace::class);
            } finally {
                $writer->rollBack();
            }
            $id = $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload)->assertAccepted()->json('data.dispatchId');
            $this->postJson('/api/shoots/'.$shoot->id.'/editing-dispatch', $payload)
                ->assertAccepted()->assertJsonPath('data.dispatchId', $id);
            $this->assertSame('assigned', ShootEditingDispatch::findOrFail($id)->status);
            $this->assertDatabaseCount('shoot_editing_dispatches', 1);
            $this->assertDatabaseCount('shoot_editing_dispatch_items', 1);
        });
    }

    private function payload(ShootFile $file): array
    {
        return ['mode'=>'editor', 'scope'=>'selected', 'preset'=>'listing-ready', 'file_ids'=>[$file->id],
            'source_versions'=>[$file->id=>1], 'request_id'=>(string) Str::uuid()];
    }

    private function withDatabase(callable $test): void
    {
        $path = tempnam(sys_get_temp_dir(), 'editing-dispatch-');
        DB::purge('sqlite');
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>$path, 'cache.default'=>'array',
            'media.archive_prewarm'=>false]);
        $writer = null;
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('local'); Storage::fake('public');
        try {
            $this->artisan('migrate:fresh', ['--force'=>true])->assertSuccessful();
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=25');
            $manager = User::factory()->create(['role'=>'editing_manager']);
            $editor = User::factory()->create(['role'=>'editor', 'metadata'=>['editing_capabilities'=>['photo']]]);
            $shoot = Shoot::factory()->create(['status'=>'editing', 'workflow_status'=>'editing', 'editor_id'=>$editor->id, 'raw_photo_count'=>0]);
            $service = Service::factory()->create(['upload_intake_type'=>'photo', 'category_id'=>Category::firstOrCreate(['name'=>'Photos'])->id]);
            $shoot->services()->attach($service, ['price'=>100, 'quantity'=>1, 'editor_id'=>$editor->id]);
            $file = ShootFile::create(['shoot_id'=>$shoot->id, 'shoot_service_id'=>$shoot->services()->first()->pivot->id,
                'filename'=>'fixture.jpg', 'stored_filename'=>'fixture.jpg', 'path'=>'shoots/fixture.jpg',
                'file_type'=>'image/jpeg', 'mime_type'=>'image/jpeg', 'file_size'=>100, 'media_type'=>'photo',
                'workflow_stage'=>'todo', 'scan_status'=>'clean', 'uploaded_by'=>$manager->id])->fresh();
            $writer = new \PDO('sqlite:'.$path);
            $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');
            $this->actingAs($manager);
            $test($shoot, $file, $writer);
        } finally {
            while (DB::transactionLevel()>0) DB::rollBack();
            DB::purge('sqlite');
            $writer = null;
            foreach ([$path,$path.'-wal',$path.'-shm']as $file) if (is_file($file)) unlink($file);
        }
    }
}
