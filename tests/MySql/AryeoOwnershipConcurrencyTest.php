<?php

namespace Tests\MySql;

use App\Models\{AryeoApiDestination, AryeoApiExport, AryeoApiInstallation, Shoot, User};
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Notification, Queue};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class AryeoOwnershipConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public static function competingDestinations(): array
    {
        return ['same export' => [false], 'same provider destination in separate bindings' => [true]];
    }

    #[DataProvider('competingDestinations')]
    public function test_only_one_concurrent_executor_can_claim_provider_ownership(bool $separateBindings): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $this->assertSame(0, DB::transactionLevel());
        Event::fake(); Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        $user = User::factory()->create();
        $shoot = Shoot::factory()->create(['client_id' => $user->id]);
        $installation = AryeoApiInstallation::create(['environment' => 'sandbox', 'company_id' => (string) Str::uuid(), 'name' => 'Synthetic ownership test']);
        $binding = ['installation_id' => $installation->id, 'shoot_id' => $shoot->id, 'client_id' => $user->id,
            'workflow' => 'ordinary', 'provider_job_id' => (string) Str::uuid(), 'provider_place_id' => (string) Str::uuid(),
            'identity' => [], 'recipients' => [], 'address' => [], 'requirements' => [], 'consent' => [],
            'verified_by' => $user->id, 'verified_at' => now()];
        $destinations = []; $exports = [];
        foreach ([1, 2] as $id) {
            if ($id === 2 && ! $separateBindings) {
                $destinations[2] = $destinations[1]; $exports[2] = $exports[1];
                continue;
            }
            $destinations[$id] = AryeoApiDestination::create($binding + ['binding_key' => hash('sha256', Str::uuid())]);
            $exports[$id] = AryeoApiExport::create(['destination_id' => $destinations[$id]->id,
                'revision' => str_repeat('a', 64), 'snapshot' => [], 'outcomes' => [], 'status' => 'approved',
                'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now()]);
        }
        $directory = sys_get_temp_dir().'/repro-mysql-aryeo-ownership-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode(['exports' => [1 => $exports[1]->id, 2 => $exports[2]->id]], JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];
        try {
            foreach ([1, 2] as $id) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/aryeo-ownership-worker.php', $directory, (string) $id]);
                $worker->setTimeout(30); $worker->start(); $workers[$id] = $worker;
            }
            $this->waitForFile($directory.'/ready-1'); $this->waitForFile($directory.'/ready-2');
            file_put_contents($directory.'/start-1', '1');
            // The first process has really observed that the provider is free.
            $this->waitForFile($directory.'/checked-1');
            file_put_contents($directory.'/start-2', '1');
            $results = [];
            foreach ($workers as $id => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[$id] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($results[$id]['no_external_effects']);
            }
            $this->assertSame(200, $results[1]['status']);
            $this->assertSame(409, $results[2]['status']);
            $this->assertSame($separateBindings ? 'destination_owned' : 'executor_stop_and_reconciliation_required', $results[2]['reason']);
            $this->assertSame($results[1]['owner'], $destinations[1]->fresh()->executor_token);
            $this->assertSame($exports[1]->id, $destinations[1]->fresh()->active_export_id);
            $this->assertSame(1, AryeoApiDestination::where('installation_id', $installation->id)->whereNotNull('executor_token')->count());
            if ($separateBindings) {
                $this->assertNull($destinations[2]->fresh()->active_export_id);
            }
            $this->assertSame(0, DB::table('aryeo_api_operations')->count());
            $this->assertSame(0, DB::table('aryeo_api_attempts')->count());
            Http::assertNothingSent(); Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            foreach (glob($directory.'/*') as $path) unlink($path);
            rmdir($directory);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && microtime(true) < $deadline) usleep(10_000);
        $this->assertFileExists($path);
    }
}
