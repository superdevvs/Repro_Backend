<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Users\PasswordRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PasswordResetConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sqlite_processes_cannot_consume_the_same_reset_token(): void
    {
        $user = User::factory()->create(['password' => 'Legacy123!']);
        $user->createToken('old-session');
        $token = app(PasswordRecoveryService::class)->issue($user);
        $directory = storage_path('framework/testing/auth-race-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        $database = $directory.'/isolated.sqlite';
        $barrier = $directory.'/start';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $processes = [];
        try {
            // Copy schema and only this test's rows; never touch an application DB.
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $pdo->exec($table->sql);
            }
            foreach (['users', 'password_reset_tokens', 'personal_access_tokens'] as $table) {
                foreach (DB::table($table)->get() as $row) {
                    $values = (array) $row;
                    $columns = implode(',', array_map(fn ($column) => '"'.$column.'"', array_keys($values)));
                    $statement = $pdo->prepare('INSERT INTO '.$table.' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                    $statement->execute(array_values($values));
                }
            }
            foreach ([1, 2] as $id) {
                $process = proc_open([
                    PHP_BINARY, base_path('tests/Fixtures/auth-reset-worker.php'), $database,
                    $user->email, $token, 'NewPassword'.$id.'!', $barrier, (string) $id,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((!is_file($barrier.'.ready.1') || !is_file($barrier.'.ready.2')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.ready.1');
            $this->assertFileExists($barrier.'.ready.2');
            file_put_contents($barrier, 'start');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $processes = [];
            sort($results);
            $this->assertSame(['consumed', 'invalid'], $results);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn());
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            $pdo = null;
            unset($statement);
            foreach (glob($directory.'/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }
}
