<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, ShootUploadAttempt, User};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootUploadWriterReservationTest extends TestCase
{
    public function test_raw_and_edited_uploads_reserve_the_writer_before_media_reads(): void
    {
        // A real WAL database and independent connection reproduce the production
        // race; an in-memory database or a mocked lock exception cannot do that.
        $path = tempnam(sys_get_temp_dir(), 'upload-writer-');
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $path]);
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $writer = null;
        $raceEnabled = false;
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=50');
            $admin = User::factory()->create(['role' => 'admin']);
            $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
            $shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing', 'editor_id' => $editor->id]);
            $service = Service::factory()->unbracketedPhoto()->create(['requires_editing' => true]);
            $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
                'editor_id' => $editor->id, 'quantity' => 1, 'price' => 100]);
            $writer = new \PDO('sqlite:'.$path);
            $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');
            $races = ['order' => 0, 'flags' => 0];
            $blocked = ['order' => 0, 'flags' => 0];
            DB::listen(function (QueryExecuted $query) use (&$raceEnabled, &$races, &$blocked, $writer, $shoot) {
                if (!$raceEnabled || DB::transactionLevel() !== 1) return;
                $kind = str_contains($query->sql, 'max("sort_order")') ? 'order'
                    : (str_contains($query->sql, "pragma_table_xinfo('shoot_files'") ? 'flags' : null);
                if (!$kind) return;
                ++$races[$kind];
                try {
                    // Without reserving the writer first, this commit invalidates
                    // the upload's snapshot on every retry and the image is lost.
                    $writer->exec('UPDATE shoots SET raw_photo_count = raw_photo_count + 1 WHERE id = '.(int) $shoot->id);
                } catch (\PDOException $exception) {
                    $this->assertSame(5, (int) $exception->errorInfo[1]);
                    ++$blocked[$kind];
                }
            });

            foreach (['raw' => $admin, 'edited' => $editor] as $type => $actor) {
                Sanctum::actingAs($actor);
                $raceEnabled = true;
                $file = UploadedFile::fake()->image($type.'.jpg', 16, 16);
                $payload = ['files' => [$file], 'upload_type' => $type, 'shoot_service_id' => $item->id,
                    'idempotency_key' => 'writer-reservation-'.$type];
                $response = $this->post('/api/shoots/'.$shoot->id.'/upload', $payload, ['Accept' => 'application/json']);
                $raceEnabled = false;
                $response->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('error_count', 0);
                $saved = ShootFile::findOrFail($response->json('uploaded_files.0.id'));
                $this->assertSame($actor->id, $saved->uploaded_by);
                $this->assertSame($item->id, $saved->shoot_service_id);
                Storage::disk('local')->assertExists($saved->path);
                $this->assertSame(0, DB::transactionLevel());

                // A repeated response cannot create a duplicate file or attempt.
                $this->post('/api/shoots/'.$shoot->id.'/upload', $payload, ['Accept' => 'application/json'])
                    ->assertOk()->assertJsonPath('uploaded_files.0.id', $saved->id);
            }
            $this->assertSame(2, $races['order'], 'Each image must be saved on its first transaction attempt.');
            $this->assertGreaterThan(0, $races['flags'], 'Exercise the separate attribution transaction too.');
            $this->assertSame($races, $blocked, 'Neither write phase may acquire a stale read snapshot.');
            $this->assertSame(2, ShootFile::where('shoot_id', $shoot->id)->count());
            $this->assertSame(2, ShootUploadAttempt::where('shoot_id', $shoot->id)->where('status', 'completed')->count());
            $this->assertSame([1, 2], ShootFile::where('shoot_id', $shoot->id)->orderBy('sort_order')->pluck('sort_order')->all());
            $writer->exec('UPDATE shoots SET raw_photo_count = 7 WHERE id = '.(int) $shoot->id);
            $this->assertSame(7, (int) $shoot->fresh()->raw_photo_count, 'Successful uploads release the writer.');
        } finally {
            $raceEnabled = false;
            DB::disconnect('sqlite');
            DB::purge('sqlite');
            $writer = null;
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) if (is_file($file)) unlink($file);
        }
    }
}
