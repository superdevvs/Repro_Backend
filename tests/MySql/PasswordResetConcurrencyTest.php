<?php

namespace Tests\MySql;

use App\Models\User;
use App\Services\Users\PasswordRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class PasswordResetConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_independent_mysql_requests_cannot_both_consume_one_reset_token(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $user = User::withoutEvents(fn () => User::factory()->create(['password' => 'Legacy123!']));
        $user->createToken('existing-session');
        $reset = app(PasswordRecoveryService::class)->issue($user);
        $directory = sys_get_temp_dir().'/repro-mysql-reset-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode(['email' => $user->email, 'token' => $reset], JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];

        try {
            foreach ([1, 2] as $id) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/auth-reset-worker.php', $directory, (string) $id]);
                $worker->setTimeout(25);
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
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outcomes[] = trim($worker->getOutput());
            }
            sort($outcomes);
            $this->assertSame(['consumed', 'invalid'], $outcomes);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
            $this->assertSame(0, $user->tokens()->count());
            $user->refresh();
            $this->assertTrue(Hash::check('NewPassword1!', $user->password) || Hash::check('NewPassword2!', $user->password));
            $this->assertNull(app(PasswordRecoveryService::class)->consume($user->email, $reset, 'AnotherPassword!'));
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
