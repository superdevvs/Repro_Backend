<?php

namespace Tests\Feature;

use App\Support\VoiceCache;
use App\Support\VoiceLocks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VoiceLocksTest extends TestCase
{
    public function test_explicit_store_does_not_use_general_file_cache_and_callback_failure_releases_lease(): void
    {
        config(['cache.default' => 'file', 'voice_calls.lock_store' => 'array']);
        $lock = VoiceLocks::lock('voice-transcript:test', 30);
        try {
            $lock->get(function (): void {
                throw new RuntimeException('Test callback failure');
            });
            $this->fail('Expected callback failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test callback failure', $exception->getMessage());
        }
        $next = VoiceLocks::lock('voice-transcript:test', 30);
        $this->assertTrue($next->get());
        $this->assertTrue($next->release());
    }

    public function test_blank_store_fails_instead_of_falling_back_to_default_file_cache(): void
    {
        config(['cache.default' => 'file', 'voice_calls.lock_store' => '']);
        $this->expectException(LogicException::class);
        VoiceLocks::lock('voice-transcript:test', 30);
    }

    public function test_database_leases_exclude_other_php_processes_and_respect_owner_and_expiry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'voice-locks-');
        $process = null;
        config([
            'database.connections.voice_lock_test' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'busy_timeout' => 5000],
            'cache.default' => 'file',
            'cache.stores.voice_test' => ['driver' => 'database', 'connection' => 'voice_lock_test', 'table' => 'cache', 'lock_table' => 'cache_locks', 'lock_lottery' => [0, 100]],
            'cache.prefix' => 'voice-lock-test:',
            'voice_calls.lock_store' => 'voice_test',
            'voice_calls.cache_store' => 'voice_test',
        ]);
        try {
            Schema::connection('voice_lock_test')->create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
            Schema::connection('voice_lock_test')->create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
            $input = new InputStream;
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/voice-lock-worker.php'), $path], base_path(), timeout: 20);
            $process->setInput($input);
            $process->start();
            $this->assertTrue($process->waitUntil(fn () => str_contains($process->getOutput(), "ready\n")), $process->getErrorOutput());
            $send = function (string $action, string $key, mixed $value = null) use ($input, $process): mixed {
                $before = substr_count($process->getOutput(), "\n");
                $input->write(json_encode(compact('action', 'key', 'value'))."\n");
                $this->assertTrue($process->waitUntil(fn () => substr_count($process->getOutput(), "\n") > $before), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));

                return json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR)['result'];
            };

            VoiceCache::store()->put('voice:recording:test', ['url' => 'https://example.test/first'], 30);
            $this->assertSame(['url' => 'https://example.test/first'], $send('cache_get', 'voice:recording:test'));
            $this->assertTrue($send('cache_put', 'voice:recording:test', ['url' => 'https://example.test/refreshed']));
            $this->assertSame(['url' => 'https://example.test/refreshed'], VoiceCache::store()->get('voice:recording:test'));
            $this->assertTrue($send('cache_forget', 'voice:recording:test'));
            $this->assertNull(VoiceCache::store()->get('voice:recording:test'));

            $owner = VoiceLocks::lock('voice-transcript:shared', 30);
            $this->assertTrue($owner->get());
            $this->assertFalse($send('acquire', 'voice-transcript:shared'));
            $this->assertTrue($send('acquire', 'voice-transcript:independent'));
            $this->assertTrue($send('release', 'voice-transcript:independent'));
            $this->assertTrue($owner->release());
            $this->assertTrue($send('acquire', 'voice-transcript:shared'));
            $this->assertFalse(VoiceLocks::lock('voice-transcript:shared', 30)->get());
            $this->assertFalse($owner->release(), 'A former owner cannot release the new process lease.');
            $this->assertTrue($send('release', 'voice-transcript:shared'));

            $expired = VoiceLocks::lock('voice-transcript:expiry', 1);
            $this->assertTrue($expired->get());
            sleep(2);
            $this->assertTrue($send('acquire', 'voice-transcript:expiry'));
            $this->assertFalse($expired->release());
            $this->assertFalse(VoiceLocks::lock('voice-transcript:expiry', 30)->get());
            $this->assertTrue($send('release', 'voice-transcript:expiry'));
            $this->assertSame(0, DB::connection('voice_lock_test')->table('cache_locks')->count());
            $input->write("{\"action\":\"exit\"}\n");
            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        } finally {
            $process?->stop();
            Cache::forgetDriver('voice_test');
            DB::purge('voice_lock_test');
            unlink($path);
        }
    }
}
