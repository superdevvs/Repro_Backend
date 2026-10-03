<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootService, ShootRawUploadBatch, User};
use Illuminate\Support\Facades\{DB, Queue};
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ShootRawUploadBatchConcurrencyTest extends TestCase
{
    public function test_real_sqlite_writers_reserve_disjoint_ranges_and_share_idempotent_identity(): void
    {
        $directory = sys_get_temp_dir().'/repro-batch-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/test.sqlite'; touch($database);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
            'database.connections.sqlite.busy_timeout' => 5, 'cache.default' => 'array']);
        DB::purge('sqlite');
        $workers = [];
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            DB::statement('PRAGMA journal_mode=WAL');
            Queue::fake();
            $actor = User::factory()->create(['role' => 'photographer']);
            $shoot = Shoot::factory()->create(['photographer_id' => $actor->id]);
            $service = Service::factory()->create(['upload_intake_type' => 'photo', 'uses_hdr_brackets' => true]);
            $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
                'photographer_id' => $actor->id, 'bracket_mode' => 5, 'quantity' => 1, 'price' => 0]);
            foreach (['a', 'b'] as $name) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/upload-batch-worker.php'),
                    $database, (string) $shoot->id, (string) $actor->id, (string) $item->id, $name, $directory.'/barrier'], base_path());
                $worker->setTimeout(120); $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 90;
            while (!is_file($directory.'/barrier.ready-a') || !is_file($directory.'/barrier.ready-b')) {
                if (microtime(true) > $deadline) { $this->fail('Concurrent workers did not initialize.'); }
                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) { $this->fail($worker->getErrorOutput().$worker->getOutput()); }
                }
                usleep(10000);
            }
            // Force a real SQLITE_BUSY on both contenders before releasing them.
            DB::beginTransaction();
            DB::table('shoots')->where('id', $shoot->id)->update(['updated_at' => DB::raw('updated_at')]);
            touch($directory.'/barrier.go'); usleep(150000); DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $this->assertSame(range(0, 70, 7), ShootRawUploadBatch::orderBy('start_position')->pluck('start_position')->all());
            $this->assertSame(1, ShootRawUploadBatch::where('batch_id', 'shared-identity')->count());
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::purge('sqlite');
            foreach (glob($directory.'/*') as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
