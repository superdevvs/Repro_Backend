<?php

namespace Tests\MySql;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

/** Producer replay (P2-03): overlapping identical replays of one website application. */
final class RecruitingReplayConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_mysql_overlapping_identical_replays_give_one_applicant_and_one_file(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame(0, DB::transactionLevel());
        User::withoutEvents(fn () => User::factory()->superAdmin()->create());
        $reference = (string) Str::uuid();
        $resume = "%PDF-1.4\nSynthetic concurrent resume\n%%EOF";

        $directory = sys_get_temp_dir().'/repro-mysql-recruiting-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $this->assertTrue(mkdir($directory.'/disk', 0700));
        $workers = [];

        try {
            file_put_contents($directory.'/fields.json', json_encode([
                'name' => 'Synthetic Applicant', 'email' => 'recruit-'.$reference.'@example.test',
                'role' => 'photographer', 'external_reference' => $reference,
            ], JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/resume.pdf', $resume);
            foreach (['a', 'b'] as $slot) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/recruiting-replay-worker.php', $directory, $slot],
                    null, ['APP_KEY' => config('app.key')]);
                $worker->setTimeout(45);
                $workers[$slot] = $worker;
                $worker->start();
            }
            $this->awaitMarker($directory.'/ready-a');
            $this->awaitMarker($directory.'/ready-b');
            $connections = array_map(fn (string $slot) => (int) file_get_contents($directory.'/ready-'.$slot), ['a', 'b']);
            $this->assertNotSame($connections[0], $connections[1]);

            file_put_contents($directory.'/start-a', '1');
            $this->awaitMarker($directory.'/selected-a');
            file_put_contents($directory.'/start-b', '1');
            // B blocks on A's uncommitted receipt row, either in its INSERT IGNORE
            // duplicate check or in the locking read that follows it.
            // With the MySQL writer policy, B waits earlier, for A's writer token.
            $policy = config('database.connections.mysql.repro_writer_policy') === true;
            $deadline = microtime(true) + 8;
            do {
                $waiting = collect(DB::select('SHOW FULL PROCESSLIST'))->contains(fn ($thread) => (int) $thread->Id === $connections[1]
                    && is_string($thread->Info) && ($policy
                        ? str_contains($thread->Info, '`writer_token`') && str_contains(strtolower($thread->Info), 'for update')
                        : str_contains($thread->Info, '`recruit_application_receipts`')
                            && (str_contains(strtolower($thread->Info), 'for update') || str_starts_with(strtolower($thread->Info), 'insert ignore'))));
                if (! $waiting) {
                    usleep(20_000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second session must wait on the receipt lock while the first holds it.');
            file_put_contents($directory.'/release-a', '1');

            $ids = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertMatchesRegularExpression('/^applicant:\d+$/', trim($worker->getOutput()));
                $ids[] = (int) substr(trim($worker->getOutput()), 10);
            }

            $applicant = DB::table('recruit_applicants')->sole();
            $this->assertSame([$applicant->id, $applicant->id], $ids);
            $receipt = DB::table('recruit_application_receipts')->sole();
            $this->assertSame($applicant->id, (int) $receipt->applicant_id);
            $this->assertSame(json_encode(['resume:'.hash('sha256', $resume)]), $receipt->attachment_sha256s);
            $file = DB::table('recruit_applicant_files')->sole();
            $this->assertSame(hash('sha256', $resume), $file->intake_checksum);
            $this->assertSame($resume, file_get_contents($directory.'/disk/'.$file->path));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $this->removeTree($directory);
        }
    }

    private function awaitMarker(string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($file);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
