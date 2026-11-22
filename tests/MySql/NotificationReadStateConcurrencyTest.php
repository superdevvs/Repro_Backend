<?php

namespace Tests\MySql;

use App\Models\User;
use App\Services\NotificationReadStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class NotificationReadStateConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public static function receiptStates(): array
    {
        return ['first simultaneous receipts' => [false], 'existing receipt duplicate-key upgrade' => [true]];
    }

    #[DataProvider('receiptStates')]
    public function test_simultaneous_devices_preserve_both_receipt_sets_without_deadlock(bool $existing): void
    {
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $this->assertSame(0, DB::transactionLevel());
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $user = User::withoutEvents(fn () => User::factory()->create(['role' => 'client']));
        $other = User::withoutEvents(fn () => User::factory()->create(['role' => 'client']));
        $watermark = (int) now()->subMinute()->getTimestampMs();
        $states = app(NotificationReadStateService::class);
        $request = static function (User $user): Request {
            $request = Request::create('/synthetic-read-state', 'POST');
            $request->setUserResolver(static fn () => $user);

            return $request;
        };
        if ($existing) {
            $states->merge($request($user), ['previous-device'], $watermark - 1000);
        }
        $states->merge($request($other), ['other-account-only'], $watermark);
        $untouched = (array) DB::table('notification_read_states')->where('user_id', $other->id)->sole();
        $directory = sys_get_temp_dir().'/repro-mysql-notification-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode(['user_id' => $user->id, 'watermark' => $watermark], JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];
        try {
            foreach (['a', 'b'] as $slot) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/notification-read-state-worker.php', $directory, $slot]);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-a') || ! is_file($directory.'/ready-b')) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($directory.'/ready-a');
            $this->assertFileExists($directory.'/ready-b');
            file_put_contents($directory.'/start', '1');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['paused_at_real_insert']);
                $this->assertTrue($result['no_external_effects']);
                $this->assertSame(100, $result['committed_merges']);
            }
            $state = $states->forUser($user);
            $expected = $existing ? ['previous-device'] : [];
            foreach (['a', 'b'] as $slot) {
                for ($index = 0; $index < 100; $index++) {
                    $expected[] = 'device-'.$slot.'-'.$index;
                }
            }
            $actual = array_keys((array) $state['readIds']);
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual);
            $this->assertSame($watermark, $state['lastSeenAt']);
            $this->assertSame(1, DB::table('notification_read_states')->where('user_id', $user->id)->count());
            $this->assertSame($untouched, (array) DB::table('notification_read_states')->where('user_id', $other->id)->sole());
            Http::assertNothingSent();
            Queue::assertNothingPushed();
            Mail::assertNothingSent();
            Notification::assertNothingSent();
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
