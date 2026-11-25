<?php

namespace Tests\Feature;

use App\Providers\TransactionTimingServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionTimingLogTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir().'/transaction-timing-'.bin2hex(random_bytes(6));
        mkdir($this->logDirectory, 0775, true);
        config([
            'logging.channels.transactions.handler_with.filename' => $this->logDirectory.'/transactions.log',
            'transaction_timing.threshold_ms' => 50,
        ]);
        Log::forgetChannel('transactions');

        Schema::dropIfExists('timing_probes');
        Schema::create('timing_probes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('timing_probes');
        Log::forgetChannel('transactions');
        foreach (glob($this->logDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDirectory);

        parent::tearDown();
    }

    public function test_the_log_is_off_by_default(): void
    {
        $config = require config_path('transaction_timing.php');

        $this->assertFalse($config['enabled']);
        $this->assertSame(250, $config['threshold_ms']);
    }

    public function test_flag_off_logs_nothing(): void
    {
        config(['transaction_timing.enabled' => false]);
        $this->app->register(TransactionTimingServiceProvider::class, true);

        $this->slowBeforeFirstWrite();

        $this->assertSame([], $this->records());
    }

    public function test_a_sleep_before_the_first_insert_logs_duration_and_time_to_first_write(): void
    {
        $this->enable();

        $line = $this->slowBeforeFirstWrite();

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('slow_transaction', $records[0]['message']);
        $context = $records[0]['context'];
        $this->assertGreaterThanOrEqual(115, $context['duration_ms']);
        $this->assertGreaterThanOrEqual(115, $context['first_write_ms']);
        // The two parts split the duration at the first write.
        $this->assertEqualsWithDelta($context['duration_ms'], $context['first_write_ms'] + $context['writer_held_ms'], 0.2);
        $this->assertSame(2, $context['queries']);
        $this->assertSame(1, $context['writes']);
        $this->assertSame(DB::getDefaultConnection(), $context['connection']);
        $this->assertSame('commit', $context['outcome']);
        $this->assertSame('tests/Feature/TransactionTimingLogTest.php:'.$line, $context['origin']);
    }

    public function test_writer_held_ms_covers_the_time_after_the_first_write(): void
    {
        $this->enable();

        DB::transaction(function () {
            DB::table('timing_probes')->insert(['name' => 'early write']);
            usleep(120_000);
        });

        $context = $this->records()[0]['context'];
        $this->assertGreaterThanOrEqual(115, $context['writer_held_ms']);
        $this->assertEqualsWithDelta($context['duration_ms'], $context['first_write_ms'] + $context['writer_held_ms'], 0.2);
    }

    public function test_fast_transactions_are_not_logged(): void
    {
        $this->enable();
        config(['transaction_timing.threshold_ms' => 5_000]);

        $this->slowBeforeFirstWrite();

        $this->assertSame([], $this->records());
    }

    public function test_a_rolled_back_transaction_is_logged_with_its_outcome(): void
    {
        $this->enable();

        try {
            DB::transaction(function () {
                DB::table('timing_probes')->insert(['name' => 'rolled back']);
                usleep(80_000);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('rollback', $records[0]['context']['outcome']);
        $this->assertSame(0, DB::table('timing_probes')->count());
    }

    public function test_nested_transactions_log_one_record_for_the_outer_transaction(): void
    {
        $this->enable();

        DB::transaction(function () {
            DB::table('timing_probes')->insert(['name' => 'outer']);
            DB::transaction(function () {
                DB::table('timing_probes')->insert(['name' => 'inner']);
                usleep(80_000);
            });
        });

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame(2, $records[0]['context']['writes']);
    }

    public function test_a_read_only_transaction_has_no_write_timings(): void
    {
        $this->enable();

        DB::transaction(function () {
            DB::table('timing_probes')->count();
            usleep(80_000);
        });

        $context = $this->records()[0]['context'];
        $this->assertSame(0, $context['writes']);
        $this->assertArrayNotHasKey('first_write_ms', $context);
        $this->assertArrayNotHasKey('writer_held_ms', $context);
    }

    public function test_a_log_that_cannot_be_written_never_breaks_the_transaction(): void
    {
        $this->enable();
        // A directory where the day file should be: no process can open it.
        mkdir($this->logDirectory.'/transactions-'.date('Y-m-d').'.log');
        $errorLog = $this->logDirectory.'/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            $this->slowBeforeFirstWrite();
        } finally {
            ini_set('error_log', (string) $previous);
            @rmdir($this->logDirectory.'/transactions-'.date('Y-m-d').'.log');
        }

        $this->assertSame(1, DB::table('timing_probes')->count());
        $this->assertStringContainsString('Transaction timing log could not be written', (string) @file_get_contents($errorLog));
    }

    public function test_the_channel_keeps_fourteen_daily_json_files_readable_by_the_group_only(): void
    {
        $channel = config('logging.channels.transactions');

        $this->assertSame(14, $channel['handler_with']['maxFiles']);
        $this->assertSame(0660, $channel['handler_with']['filePermission']);
        $this->assertSame('info', $channel['level']);
        $this->assertSame(\Monolog\Formatter\JsonFormatter::class, $channel['formatter']);
    }

    public function test_a_day_file_created_by_one_user_joins_the_log_directory_group(): void
    {
        if (! function_exists('posix_geteuid')) {
            $this->markTestSkipped('POSIX file ownership only.');
        }
        $group = $this->groupOtherThanOurs();
        if ($group === null || ! @chgrp($this->logDirectory, $group)) {
            $this->markTestSkipped('This user belongs to no second group.');
        }

        Log::channel('transactions')->info('slow_transaction', ['duration_ms' => 1.0]);

        $files = glob($this->logDirectory.'/transactions-*.log');
        $this->assertCount(1, $files);
        clearstatcache();
        $this->assertSame($group, filegroup($files[0]), 'Web workers in the directory group must be able to append.');
        $this->assertSame(0660, fileperms($files[0]) & 0777);
    }

    public function test_production_records_pass_the_privacy_filter_with_their_timings(): void
    {
        $this->app['env'] = 'production';
        Log::forgetChannel('transactions');
        $this->enable();

        $this->slowBeforeFirstWrite();

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('slow_transaction', $records[0]['message']);
        $context = $records[0]['context'];
        foreach (['duration_ms', 'first_write_ms', 'writer_held_ms', 'queries', 'writes', 'connection', 'outcome'] as $key) {
            $this->assertArrayHasKey($key, $context);
        }
        $this->assertSame([], array_diff(array_keys($context), [
            'duration_ms', 'first_write_ms', 'writer_held_ms', 'queries', 'writes', 'connection', 'outcome', 'origin',
        ]));
    }

    private function enable(): void
    {
        config(['transaction_timing.enabled' => true]);
        $this->app->register(TransactionTimingServiceProvider::class, true);
    }

    /** Runs a slow transaction and returns the line that opened it. */
    private function slowBeforeFirstWrite(): int
    {
        $line = __LINE__ + 1;
        DB::transaction(function () {
            DB::table('timing_probes')->count();
            usleep(120_000);
            DB::table('timing_probes')->insert(['name' => 'after a pause']);
        });

        return $line;
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $records = [];
        foreach (glob($this->logDirectory.'/transactions-*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $records[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $records;
    }

    private function groupOtherThanOurs(): ?int
    {
        if (posix_geteuid() === 0) {
            return posix_getegid() === 4242 ? 4243 : 4242;
        }
        foreach (posix_getgroups() ?: [] as $group) {
            if ($group !== posix_getegid()) {
                return $group;
            }
        }

        return null;
    }
}
