<?php

namespace Tests\MySql;

use App\Models\MobileDevicePreference;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue};
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class MobileDevicePreferenceConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_concurrent_initial_saves_cannot_overwrite_newer_preferences_with_an_older_sequence(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $this->assertSame(0, DB::transactionLevel());
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        $user = User::factory()->create();
        $token = $user->createToken('synthetic-preference-race')->accessToken;
        $directory = sys_get_temp_dir().'/repro-mysql-preferences-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode([
            'user_id' => $user->id, 'token_id' => $token->id,
            'installation_id' => '00000000-0000-4000-8000-000000000042',
        ], JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];

        try {
            foreach ([1, 2] as $sequence) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/mobile-preference-worker.php', $directory, (string) $sequence]);
                $worker->setTimeout(30);
                $worker->start();
                $workers[$sequence] = $worker;
            }
            $this->waitForFile($directory.'/ready-1');
            $this->waitForFile($directory.'/ready-2');
            file_put_contents($directory.'/start-1', '1');
            // The older request has actually observed the absent preference,
            // while holding the existing token row, before its rival starts.
            $this->waitForFile($directory.'/checked-1');
            file_put_contents($directory.'/start-2', '1');
            foreach ($workers as $sequence => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(200, $result['status']);
                $this->assertSame($sequence, $result['sequence']);
                $this->assertTrue($result['no_external_effects']);
            }
            $this->assertSame(1, MobileDevicePreference::whereKey($token->id)->count());
            $preference = MobileDevicePreference::findOrFail($token->id);
            $this->assertSame(2, $preference->sequence);
            $this->assertFalse($preference->enabled('sms'));
            $this->assertSame((int) $user->id, (int) $preference->user_id);
            $this->assertSame('00000000-0000-4000-8000-000000000042', $preference->installation_id);
            Http::assertNothingSent(); Queue::assertNothingPushed(); Mail::assertNothingSent(); Notification::assertNothingSent();
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) $worker->stop();
            }
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
