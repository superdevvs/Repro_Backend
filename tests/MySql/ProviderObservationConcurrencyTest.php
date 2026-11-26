<?php

namespace Tests\MySql;

use App\Models\{Service, Shoot, ShootService};
use App\Services\Shoots\ProviderAutoDeliveryService;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class ProviderObservationConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public static function sources(): array
    {
        return ['authenticated webhook replay' => ['authenticated_webhook'], 'identical credentialed read' => ['provider_api']];
    }

    #[DataProvider('sources')]
    public function test_concurrent_identical_observations_return_the_same_preserved_event(string $source): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
            'admin_verified_at' => null, 'completed_at' => null, 'active_work_started_at' => null,
            'iguide_property_id' => 'ig-concurrent', 'iguide_tour_url' => 'https://youriguide.com/concurrent/',
        ]));
        $catalog = Service::withoutEvents(fn () => Service::factory()->noIntake()->create([
            'name' => 'Synthetic provider concurrency product', 'requires_editing' => false, 'is_migration_only' => false,
        ]));
        $item = ShootService::withoutEvents(fn () => $shoot->serviceItems()->create([
            'service_id' => $catalog->id, 'price' => 250, 'quantity' => 1, 'is_deliverable' => true,
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
        ]));
        $item->forceFill(['delivery_requirements' => [
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide'],
        ]])->saveQuietly();
        $parsed = ['property_id' => 'ig-concurrent', 'tour_url' => $shoot->iguide_tour_url,
            'delivery_readiness' => ['state' => 'ready']];
        $fingerprint = hash('sha256', 'synthetic-concurrent-provider-event');
        $directory = sys_get_temp_dir().'/repro-mysql-provider-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode([
            'shoot_id' => $shoot->id, 'parsed' => $parsed, 'fingerprint' => $fingerprint, 'source' => $source,
        ], JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];

        try {
            foreach ([1, 2] as $id) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/provider-observation-worker.php', $directory, (string) $id]);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($directory.'/ready-1');
            $this->assertFileExists($directory.'/ready-2');
            file_put_contents($directory.'/start', '1');
            $ids = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['no_provider_requests']);
                $ids[] = $result['observation_id'];
            }
            $this->assertGreaterThan(0, $ids[0]);
            $this->assertSame($ids[0], $ids[1]);
            $rows = DB::table('shoot_provider_delivery_verifications')->where('shoot_id', $shoot->id)->get();
            $this->assertCount(1, $rows);
            $before = (array) $rows->sole();
            $this->assertSame('initial', $before['execution_key']);
            $this->assertSame($source, $before['source']);
            $this->assertSame($ids[0], app(ProviderAutoDeliveryService::class)->observe(
                $shoot->fresh(), 'iguide', $parsed, $source, $fingerprint, false,
            ));
            $this->assertSame($before, (array) DB::table('shoot_provider_delivery_verifications')->find($ids[0]));
            $this->assertSame(0, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->count());
            $this->assertSame(0, DB::table('shoot_delivery_notification_outbox')->where('shoot_id', $shoot->id)->count());
            Http::assertNothingSent(); Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) $worker->stop();
            }
            foreach (glob($directory.'/*') as $path) unlink($path);
            rmdir($directory);
        }
    }
}
