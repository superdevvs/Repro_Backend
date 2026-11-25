<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpsHeartbeatCommandTest extends TestCase
{
    private const PING = 'https://hc-ping.test/0f4c-heartbeat';

    private string $backupDirectory;

    private int $alertServiceStatus = 200;

    private bool $alertServiceUnreachable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->alertServiceUnreachable
            ? Http::failedConnection()($request)
            : Http::response('OK', $this->alertServiceStatus));

        $this->backupDirectory = sys_get_temp_dir().'/heartbeat-backups-'.bin2hex(random_bytes(6));
        mkdir($this->backupDirectory, 0775, true);
        $this->writeBackup('database-today.sqlite', now()->subHours(3)->getTimestamp());

        config([
            'health.expected_engine' => '',
            'heartbeat.url' => self::PING,
            'heartbeat.fail_url' => '',
            'heartbeat.backup.glob' => $this->backupDirectory.'/*.sqlite',
            'heartbeat.backup.max_age_hours' => 26,
            'heartbeat.disk.paths' => $this->backupDirectory,
            'heartbeat.disk.min_free_gb' => 0.001,
            'heartbeat.queue.max_age_minutes' => 10,
            'heartbeat.queue.queues' => '',
            'queue.default' => 'database',
        ]);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupDirectory);
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    public function test_a_healthy_server_pings_the_heartbeat_url_with_a_summary(): void
    {
        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->url() === self::PING
                && $request->method() === 'POST'
                && str_contains($body, 'database: ok')
                && str_contains($body, 'backup: ok')
                && str_contains($body, 'disk: ok')
                && str_contains($body, 'queue: ok');
        });
    }

    public function test_an_old_backup_pings_the_fail_url(): void
    {
        $this->writeBackup('database-today.sqlite', now()->subHours(30)->getTimestamp());

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING.'/fail'
            && str_contains($request->body(), 'backup: FAIL'));
        Http::assertNotSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_a_missing_or_empty_backup_fails(): void
    {
        @unlink($this->backupDirectory.'/database-today.sqlite');
        $this->artisan('ops:heartbeat')->assertFailed();

        touch($this->backupDirectory.'/database-empty.sqlite');
        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_the_newest_backup_decides_the_age(): void
    {
        $this->writeBackup('database-old.sqlite', now()->subDays(5)->getTimestamp());

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_low_free_disk_space_pings_the_fail_url(): void
    {
        config(['heartbeat.disk.min_free_gb' => 1_000_000_000]);

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING.'/fail'
            && str_contains($request->body(), 'disk: FAIL'));
    }

    public function test_an_old_waiting_job_pings_the_fail_url(): void
    {
        $this->insertJob('mail', now()->subMinutes(25)->getTimestamp());

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING.'/fail'
            && str_contains($request->body(), 'queue: FAIL')
            && str_contains($request->body(), 'queue=mail'));
    }

    public function test_delayed_and_reserved_jobs_do_not_count_as_waiting(): void
    {
        $this->insertJob('default', now()->addHour()->getTimestamp());
        $this->insertJob('default', now()->subMinutes(25)->getTimestamp(), reservedAt: now()->getTimestamp());

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_the_queue_check_can_watch_only_named_queues(): void
    {
        config(['heartbeat.queue.queues' => 'mail, default']);
        $this->insertJob('unserved', now()->subHours(2)->getTimestamp());

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_a_wrong_database_engine_pings_the_fail_url(): void
    {
        config(['health.expected_engine' => DB::connection()->getDriverName() === 'mysql' ? 'sqlite' : 'mysql']);

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING.'/fail'
            && str_contains($request->body(), 'database: FAIL engine_mismatch'));
    }

    public function test_a_configured_fail_url_is_used_for_failures(): void
    {
        config(['heartbeat.fail_url' => 'https://hc-ping.test/separate-fail', 'heartbeat.disk.min_free_gb' => 1_000_000_000]);

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.test/separate-fail');
    }

    public function test_an_empty_url_turns_the_ping_off(): void
    {
        config(['heartbeat.url' => '']);

        $this->artisan('ops:heartbeat')
            ->expectsOutputToContain('HEARTBEAT_PING_URL is empty')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_prints_the_checks_without_pinging(): void
    {
        $this->artisan('ops:heartbeat', ['--dry-run' => true])
            ->expectsOutputToContain('backup: ok')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_url_that_is_not_https_is_refused(): void
    {
        config(['heartbeat.url' => 'http://hc-ping.test/plain']);

        $this->artisan('ops:heartbeat')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_alert_service_fails_the_run_without_throwing(): void
    {
        $this->alertServiceStatus = 503;

        $this->artisan('ops:heartbeat')
            ->expectsOutputToContain('Heartbeat ping failed')
            ->assertFailed();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_a_connection_error_never_prints_the_ping_address(): void
    {
        // The address is a private token; cURL errors quote the full URL.
        $this->alertServiceUnreachable = true;

        $exitCode = Artisan::call('ops:heartbeat');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Heartbeat ping failed: ConnectionException.', $output);
        $this->assertStringNotContainsString('0f4c-heartbeat', $output);
        Http::assertSent(fn (Request $request) => $request->url() === self::PING);
    }

    public function test_heartbeat_defaults_are_off_and_match_the_pass_marks(): void
    {
        $config = require config_path('heartbeat.php');

        $this->assertSame('', $config['url']);
        $this->assertSame('', $config['fail_url']);
        $this->assertSame('', $config['backup']['glob']);
        $this->assertSame(26.0, $config['backup']['max_age_hours']);
        $this->assertSame(10, $config['queue']['max_age_minutes']);
    }

    public function test_the_heartbeat_is_scheduled_every_minute_only_when_a_url_is_set(): void
    {
        $event = $this->heartbeatEvent();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);

        config(['heartbeat.url' => '']);
        $this->assertFalse($event->filtersPass($this->app));

        config(['heartbeat.url' => self::PING]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    private function heartbeatEvent(): Event
    {
        $this->app->make(ConsoleKernel::class)->all();
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'ops:heartbeat'));
        $this->assertNotNull($event, 'ops:heartbeat is not scheduled.');

        return $event;
    }

    private function writeBackup(string $name, int $modifiedAt): void
    {
        $path = $this->backupDirectory.'/'.$name;
        file_put_contents($path, 'SQLite format 3');
        touch($path, $modifiedAt);
    }

    private function insertJob(string $queue, int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('migrations')) {
            Schema::create('migrations', function (Blueprint $table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
