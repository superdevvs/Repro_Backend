<?php

namespace Tests\Feature;

use App\Jobs\CutoverSmokeJob;
use App\Support\Cutover\CutoverEngineProbe;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Concerns\CutoverSmokeRootStandIn;
use Tests\Concerns\CutoverTableSnapshots;
use Tests\TestCase;

/**
 * cutover:smoke and scripts/cutover/probe/fpm-engine.php (design v2, 5.3 and 1.3).
 *
 * Both print the raw route values of the live config; Python canonicalises and
 * hashes them. scripts/cutover/probe/route-hash-vectors.json is shared with
 * scripts/cutover/probe/test_route_hash_vectors.py.
 */
class CutoverSmokeTest extends TestCase
{
    use CutoverSmokeRootStandIn;
    use CutoverTableSnapshots;
    use RefreshDatabase;

    private const VECTORS = 'scripts/cutover/probe/route-hash-vectors.json';

    private const ROUTE_KEYS = ['database.default', 'database.connections', 'cache.default', 'cache.stores', 'queue.default', 'queue.connections'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->standInForRoot();
    }

    public function test_smoke_refuses_root_before_any_database_work(): void
    {
        $this->createFixtures();
        $before = $this->tableSnapshot();
        $this->app->instance(CutoverEngineProbe::EFFECTIVE_UID_SEAM, 0);
        $statements = $this->recordStatements();
        $queued = collect();
        Event::listen(JobQueued::class, fn (JobQueued $event) => $queued->push(get_class($event->job)));

        foreach (['--engine-only', '--read-only', '--write'] as $mode) {
            [$exit, $out, $raw] = $this->smoke($mode);

            $this->assertSame(1, $exit, $raw);
            $this->assertSame(['version', 'mode', 'ok', 'problems'], array_keys($out), $raw);
            $this->assertFalse($out['ok']);
            $this->assertCount(1, $out['problems']);
            $this->assertStringContainsString('refused: cutover:smoke never runs as root', $out['problems'][0]);
        }
        $exit = Artisan::call('cutover:smoke', ['--write' => true]);
        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/\Aproblem: refused: cutover:smoke never runs as root.*\nfailed\n\z/s', Artisan::output());

        $this->assertSame([], $statements->all(), 'the refused smoke ran a query');
        $this->assertSame([], $queued->all());
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()));
    }

    public function test_without_the_seam_the_smoke_reads_the_real_effective_user(): void
    {
        $this->app->forgetInstance(CutoverEngineProbe::EFFECTIVE_UID_SEAM);
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;

        [$exit, $out, $raw] = $this->smoke('--engine-only');

        $this->assertSame($root ? 1 : 0, $exit, $raw);
        $this->assertSame($root, str_contains(implode(' ', $out['problems']), 'never runs as root'), $raw);
    }

    public function test_engine_only_prints_route_values_without_passwords(): void
    {
        $secret = 'smoke-test-password-'.bin2hex(random_bytes(8));
        config([
            'database.connections.sqlite.password' => $secret,
            'cache.default' => 'smoke-store',
            'cache.stores.smoke-store' => ['driver' => 'array', 'options' => ['password' => $secret, 'empty' => ['password' => '']]],
        ]);

        [$exit, $out, $raw] = $this->smoke('--engine-only');

        $this->assertSame(0, $exit, $raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString((string) config('app.key'), $raw);
        $this->assertSame(1, $out['version']);
        $this->assertSame('engine-only', $out['mode']);
        $this->assertTrue($out['ok'], $raw);
        $this->assertSame([], $out['problems']);
        $this->assertSame('sqlite', $out['engine']);
        $this->assertSame('sqlite', $out['connection']);
        $this->assertNull($out['server_uuid']);
        $this->assertIsString($out['server_version']);
        $this->assertFalse($out['config_cached']);
        $this->assertSame(['euid', 'user', 'egid', 'group', 'cwd', 'umask'], array_keys($out['process']));
        $this->assertMatchesRegularExpression('/\A0[0-7]{3}\z/', $out['process']['umask']);

        $values = $out['route_values'];
        $this->assertSame(['app.key_sha256', 'cache.default', 'cache.stores.default', 'cache.stores.scheduling', 'database.connections',
            'database.default', 'database.repro_leftover_transactions', 'queue.connections', 'queue.default'], array_keys($values));
        $this->assertSame(hash('sha256', (string) config('app.key')), $values['app.key_sha256']);
        $this->assertArrayNotHasKey('password', $values['database.connections']['sqlite']);
        $this->assertTrue($values['database.connections']['sqlite']['password_set']);
        $this->assertTrue($values['cache.stores.default']['options']['password_set']);
        $this->assertFalse($values['cache.stores.default']['options']['empty']['password_set']);
        $this->assertSame('smoke-store', $values['cache.default']);
        $this->assertSame(array_keys(config('queue.connections')), array_keys($values['queue.connections']));
        foreach ($values['queue.connections'] as $name => $settings) {
            $this->assertSame([], array_diff(array_keys($settings), ['driver', 'connection', 'table']), $name);
            $this->assertSame(config("queue.connections.$name.driver"), $settings['driver'], $name);
        }
    }

    public function test_engine_only_output_matches_route_hash_vectors(): void
    {
        $original = $this->routeConfig();
        $root = $this->temporaryDirectory();
        try {
            foreach ($this->localVectors($root) as $vector) {
                $this->overlay($vector['config']);

                [, $out, $raw] = $this->smoke('--engine-only');

                $this->assertPathsUnder($root, $out['route_values'], $vector['name']);
                $this->assertSame($vector['engine'], $out['engine'], $vector['name']);
                $this->assertSame($this->canonical($vector['route_values']), $this->canonical($out['route_values']), $vector['name']);
                $this->assertOnlyObjects(json_decode($raw)->route_values, $vector['name']);
                foreach (['vector-app-password', 'nested-secret', 'store-secret'] as $secret) {
                    $this->assertStringNotContainsString($secret, $raw, $vector['name']);
                }
            }
        } finally {
            $this->restore($original);
        }
    }

    public function test_engine_only_refuses_a_url_that_carries_a_password(): void
    {
        config(['database.connections.sqlite.url' => 'sqlite://repro:url-secret-4242@localhost/database.sqlite']);

        [$exit, $out, $raw] = $this->smoke('--engine-only');

        $this->assertSame(1, $exit, $raw);
        $this->assertFalse($out['ok']);
        $this->assertNull($out['route_values']);
        $this->assertStringNotContainsString('url-secret-4242', $raw);
        $this->assertStringContainsString('carries a password', implode(' ', $out['problems']));
    }

    public function test_modes_are_exclusive(): void
    {
        $this->assertSame(2, Artisan::call('cutover:smoke', ['--json' => true]));
        $this->assertSame(2, Artisan::call('cutover:smoke', ['--read-only' => true, '--write' => true]));
        $this->assertSame(2, Artisan::call('cutover:smoke', ['--engine-only' => true, '--read-only' => true]));
    }

    public function test_read_only_writes_nothing(): void
    {
        $fixture = $this->createFixtures();
        $before = $this->tableSnapshot();
        $statements = $this->recordStatements();

        [$exit, $out, $raw] = $this->smoke('--read-only');

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertSame('read-only', $out['mode']);
        $this->assertSame('sqlite', $out['engine']);
        $this->assertSame([
            'client_id' => $fixture['client_id'], 'shoot_id' => $fixture['shoot_id'], 'client_read' => true, 'shoot_read' => true,
            'encrypted_value_decrypts' => true, 'smoke_queue' => 'database:cutover-smoke', 'smoke_queue_pending' => 0,
            'smoke_queue_paused' => false,
        ], $out['checks']);
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()));
        $this->assertNotEmpty($statements->all());
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $sql, 'read-only smoke ran a write');
        }
    }

    public function test_read_only_session_refuses_writes_and_restores_the_session(): void
    {
        $connection = DB::connection();

        try {
            CutoverEngineProbe::readOnly($connection, fn () => $connection->table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 0]));
            $this->fail('a write inside the read-only session succeeded');
        } catch (QueryException $refused) {
            $this->assertStringContainsString('readonly', strtolower($refused->getMessage()));
        }

        $connection->table('cache')->insert(['key' => 'after', 'value' => 'v', 'expiration' => 0]);
        $this->assertSame(1, $connection->table('cache')->where('key', 'after')->count());
    }

    public function test_read_only_fails_without_fixtures_or_with_another_app_key(): void
    {
        [$exit, $out] = $this->smoke('--read-only');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not exist', implode(' ', $out['problems']));

        $this->createFixtures();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        [$exit, $out, $raw] = $this->smoke('--read-only');

        $this->assertSame(1, $exit, $raw);
        $this->assertFalse($out['checks']['encrypted_value_decrypts']);
        $this->assertStringContainsString('decrypt', implode(' ', $out['problems']));
    }

    public function test_write_touches_only_smoke_tables(): void
    {
        $fixture = $this->createFixtures();
        $cursor = (int) DB::table('aryeo_changes')->max('id');
        $before = $this->tableSnapshot();
        $statements = $this->recordStatements();
        $events = collect();
        Event::listen('eloquent.*', function (string $event) use ($events) {
            if (preg_match('/^eloquent\.(saving|saved|creating|created|updating|updated|deleting|deleted)/', $event)) {
                $events->push($event);
            }
        });
        $queued = collect();
        Event::listen(JobQueued::class, fn (JobQueued $event) => $queued->push(get_class($event->job)));
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $queued->push('processing '.$event->job->resolveName()));
        Event::listen(NotificationSending::class, fn () => $events->push('notification'));
        Event::listen(MessageSending::class, fn () => $events->push('mail'));

        [$exit, $out, $raw] = $this->smoke('--write');

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertSame('write', $out['mode']);
        $checks = $out['checks'];
        $this->assertSame($fixture['client_id'], $checks['client_id']);
        $this->assertSame($fixture['shoot_id'], $checks['shoot_id']);
        $this->assertTrue($checks['read_back']);
        $this->assertSame(['queued' => true, 'ran' => true, 'row_left' => false, 'failed' => false], $checks['job']);
        $this->assertSame(['users', 'shoots', 'aryeo_changes', 'jobs'], $checks['smoke_tables']);

        $this->assertSame(['aryeo_changes', 'shoots', 'users'], $this->changedTables($before, $this->tableSnapshot()));
        $this->assertSame($checks['value'], DB::table('users')->where('id', $fixture['client_id'])->value('company_notes'));
        $this->assertSame($checks['value'], DB::table('shoots')->where('id', $fixture['shoot_id'])->value('company_notes'));
        $this->assertSame([[$fixture['shoot_id'], $fixture['client_id'], 'shoots.UPDATE']], DB::table('aryeo_changes')->where('id', '>', $cursor)
            ->get()->map(fn ($row) => [(int) $row->shoot_id, (int) $row->client_id, $row->kind])->all());

        $written = [];
        foreach ($statements as $sql) {
            if (preg_match('/\A\s*(insert(?:\s+or\s+\w+)?\s+into|update|delete\s+from|replace\s+into)\s+["`]?(\w+)/i', $sql, $match)) {
                $written[strtolower($match[2])] = true;
            } else {
                $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $sql);
            }
        }
        $this->assertEqualsCanonicalizing(['users', 'shoots', 'jobs'], array_keys($written));
        $this->assertSame([], $events->all(), 'no model event, notification or mail');
        $this->assertSame([CutoverSmokeJob::class, 'processing '.CutoverSmokeJob::class], $queued->all());
    }

    public function test_write_refuses_a_paused_or_busy_smoke_queue_and_changed_fixtures(): void
    {
        $fixture = $this->createFixtures();

        Queue::pause('database', 'cutover-smoke');
        $this->assertRefusedWithoutChange('paused');
        Queue::resume('database', 'cutover-smoke');

        $leftover = Queue::connection('database')->pushOn('cutover-smoke', new CutoverSmokeJob('leftover'));
        $problem = $this->assertRefusedWithoutChange('pending');
        // The smoke leaves the row (asserted above) and prints the documented manual cleanup.
        $cleanup = $this->leftoverCleanup();
        $this->assertStringContainsString("the smoke never deletes them. For a leftover CutoverSmokeJob see 'Leftover smoke job' in scripts/cutover/probe/README.md", $problem);
        $this->assertStringContainsString($cleanup['SELECT'], $problem);
        $this->assertStringContainsString($cleanup['DELETE'], $problem);
        DB::table('jobs')->where('id', $leftover)->delete();

        DB::table('shoots')->where('id', $fixture['shoot_id'])->update(['iguide_work_order_id' => 'WO-1']);
        $this->assertRefusedWithoutChange('iguide_work_order_id');
    }

    public function test_documented_leftover_cleanup_removes_only_unreserved_smoke_jobs(): void
    {
        $cleanup = $this->leftoverCleanup();
        $queue = Queue::connection('database');
        $leftover = $queue->pushOn('cutover-smoke', new CutoverSmokeJob('leftover'));
        $reserved = $queue->pushOn('cutover-smoke', new CutoverSmokeJob('reserved'));
        DB::table('jobs')->where('id', $reserved)->update(['reserved_at' => time(), 'attempts' => 1]);
        $other = DB::table('jobs')->insertGetId(['queue' => 'cutover-smoke', 'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeOtherJob']),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $elsewhere = $queue->pushOn('default', new CutoverSmokeJob('elsewhere'));
        $ids = fn () => DB::table('jobs')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $shown = DB::select($cleanup['SELECT']);
        $this->assertSame([$leftover, $reserved, $other], array_map(fn ($row) => (int) $row->id, $shown));
        $this->assertStringContainsString('CutoverSmokeJob', $shown[0]->payload_start);
        $this->assertStringContainsString('SomeOtherJob', $shown[2]->payload_start);

        $this->assertSame(1, DB::delete($cleanup['DELETE']));
        $this->assertSame([$reserved, $other, $elsewhere], $ids());

        // A worker that died holding the row: release it, then the same DELETE removes it.
        $this->assertSame(1, DB::update($cleanup['UPDATE']));
        $this->assertSame(1, DB::delete($cleanup['DELETE']));
        $this->assertSame([$other, $elsewhere], $ids());
    }

    public function test_job_does_nothing_and_leaves_no_row(): void
    {
        $job = new CutoverSmokeJob('job-test-token');
        $this->assertSame(['database', 'cutover-smoke', 1], [$job->connection, $job->queue, $job->tries]);

        $seen = collect();
        Event::listen('*', fn (string $event) => $seen->push($event));
        $job->handle();
        $this->assertSame([], $seen->all(), 'handle() fired an event (a query, a cache write, a dispatch)');
        $this->assertTrue(CutoverSmokeJob::ran('job-test-token'));

        $id = Queue::connection('database')->pushOn('cutover-smoke', new CutoverSmokeJob('job-cycle-token'));
        $this->assertSame('cutover-smoke', DB::table('jobs')->where('id', $id)->value('queue'));
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'cutover-smoke', '--once' => true,
            '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertTrue(CutoverSmokeJob::ran('job-cycle-token'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_fpm_engine_output_matches_route_hash_vectors(): void
    {
        if ($this->probeRefusesRoot()) {
            return;
        }

        $root = $this->temporaryDirectory();
        foreach ($this->localVectors($root) as $vector) {
            [$exit, $out, $raw] = $this->probe(['APP_CONFIG_CACHE' => $this->probeConfig($vector['config'])]);

            $this->assertPathsUnder($root, $out['route_values'] ?? null, $vector['name']);
            $this->assertSame('fpm-engine', $out['probe'] ?? null, $raw);
            $this->assertTrue($out['config_cached'], $vector['name']);
            $this->assertSame($vector['engine'], $out['engine'], $vector['name']);
            $this->assertSame($this->canonical($vector['route_values']), $this->canonical($out['route_values']), $vector['name']);
            $this->assertOnlyObjects(json_decode($raw)->route_values, $vector['name']);
            foreach (['vector-app-password', 'nested-secret', 'store-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, $vector['name']);
            }
            // No vector's database exists here: the probe says so, exits 1 and still prints the values.
            $this->assertSame(1, $exit, $raw);
            $this->assertStringContainsString('database: ', implode(' ', $out['problems']), $vector['name']);
        }
    }

    public function test_fpm_engine_reads_without_writing(): void
    {
        if ($this->probeRefusesRoot()) {
            return;
        }
        $directory = $this->temporaryDirectory();
        $sqlite = $directory.'/probe.sqlite';
        $pdo = new \PDO('sqlite:'.$sqlite);
        $pdo->exec('PRAGMA journal_mode = DELETE; CREATE TABLE t (id INTEGER PRIMARY KEY); INSERT INTO t VALUES (1);');
        unset($pdo);
        $cache = $this->probeConfig([
            'app' => ['key' => config('app.key')],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'url' => null, 'database' => $sqlite,
                'prefix' => '', 'foreign_key_constraints' => true]]],
            'cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => $directory.'/cache']]],
            'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']]],
        ]);
        // The probe boots bootstrap/app.php, so it writes (if it ever did) under the checkout's own
        // storage/, not under this process's storage_path(): with CI's TEST_TOKEN that is
        // storage/framework/testing/parallel/worker-N. storage/framework/testing holds only the test
        // runs' own files (parallel workers, fake disks), which other workers change meanwhile.
        $watched = [$directory, base_path('storage'), base_path('bootstrap/cache')];
        $testOnly = [base_path('storage/framework/testing')];
        $before = $this->fileState($watched, $testOnly);

        [$exit, $out, $raw] = $this->probe(['APP_CONFIG_CACHE' => $cache]);

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertSame('sqlite', $out['engine']);
        $this->assertSame($sqlite, $out['database']);
        $this->assertNull($out['server_uuid']);
        $this->assertSame(PHP_SAPI, $out['sapi']);
        $this->assertSame(['enabled', 'cache_full', 'restart_pending', 'num_cached_scripts', 'start_time', 'last_restart_time',
            'validate_timestamps'], array_keys($out['opcache']));
        $this->assertSame($before, $this->fileState($watched, $testOnly), 'the probe wrote a file');
    }

    public function test_fpm_engine_refuses_to_boot_when_a_bootstrap_manifest_is_missing(): void
    {
        if ($this->probeRefusesRoot()) {
            return;
        }
        $directory = $this->temporaryDirectory();
        $vector = $this->localVectors($directory)[0];

        [$exit, $out, $raw] = $this->probe(['APP_CONFIG_CACHE' => $this->probeConfig($vector['config']),
            'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php']);

        $this->assertSame(1, $exit, $raw);
        $this->assertStringContainsString('missing', implode(' ', $out['problems'] ?? []), $raw);
        $this->assertArrayNotHasKey('route_values', $out, 'the probe booted the app');
        $this->assertFileDoesNotExist($directory.'/services.php');
        $this->assertFileDoesNotExist($directory.'/packages.php');
    }

    /** The probe never runs application code as root. Run as root, prove that and stop. */
    private function probeRefusesRoot(): bool
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return false;
        }
        [$exit, $out] = $this->probe([]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('root', implode(' ', $out['problems'] ?? []));

        return true;
    }

    /** @return array{0: ?int, 1: array<string, mixed>, 2: string} */
    private function probe(array $environment): array
    {
        $process = new Process([PHP_BINARY, base_path('scripts/cutover/probe/fpm-engine.php')], base_path(), $environment);
        $process->setTimeout(120);
        $process->run();
        $raw = $process->getOutput();

        return [$process->getExitCode(), json_decode($raw, true) ?? [], $raw.$process->getErrorOutput()];
    }

    /** A config cache: this app's config with the route subtrees of $config, as `config:cache` writes it. */
    private function probeConfig(array $config): string
    {
        $all = $this->app['config']->all();
        foreach (self::ROUTE_KEYS as $key) {
            data_set($all, $key, data_get($config, $key));
        }
        $all['app']['key'] = $config['app']['key'] ?? null;
        $all['database']['repro_leftover_transactions'] = $config['database']['repro_leftover_transactions'] ?? null;
        $path = $this->temporaryDirectory().'/config.php';
        file_put_contents($path, '<?php return '.var_export($all, true).';'.PHP_EOL);

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/cutover-smoke-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        return $directory;
    }

    /**
     * @param  list<string>  $skip  paths left out with everything below them
     * @return array<string, string> path => size, mtime and digest
     */
    private function fileState(array $roots, array $skip = []): array
    {
        clearstatcache();
        $state = [];
        foreach ($roots as $root) {
            $tree = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $item) => ! in_array($item->getPathname(), $skip, true));
            $items = new \RecursiveIteratorIterator($tree, \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($items as $item) {
                $state[$item->getPathname()] = $item->isDir() ? 'dir' : $item->getSize().' '.$item->getMTime().' '.hash_file('sha256', $item->getPathname());
            }
        }
        ksort($state);

        return $state;
    }

    /** @return string the problem that names $reason */
    private function assertRefusedWithoutChange(string $reason): string
    {
        $before = $this->tableSnapshot();

        [$exit, $out, $raw] = $this->smoke('--write');

        $this->assertSame(1, $exit, $raw);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString($reason, implode(' ', $out['problems']));
        $this->assertSame([], $this->changedTables($before, $this->tableSnapshot()), "refused ($reason) after a write");

        return collect($out['problems'])->first(fn (string $problem) => str_contains($problem, $reason));
    }

    /**
     * The SQL of README.md's "Leftover smoke job" section, by its first keyword.
     *
     * @return array<string, string>
     */
    private function leftoverCleanup(): array
    {
        $readme = file_get_contents(base_path('scripts/cutover/probe/README.md'));
        $this->assertSame(1, preg_match('/^## Leftover smoke job\n(.*?)(?=^## |\z)/ms', $readme, $section), 'README has no Leftover smoke job section');
        preg_match_all('/^```sql\n(.*?)\n```$/ms', $section[1], $blocks);
        $statements = [];
        foreach ($blocks[1] as $sql) {
            $statements[strtoupper(strtok($sql, ' '))] = trim($sql);
        }
        $this->assertSame(['SELECT', 'DELETE', 'UPDATE'], array_keys($statements));

        return $statements;
    }

    /** @return array{client_id: int, shoot_id: int} */
    private function createFixtures(): array
    {
        $this->assertSame(0, Artisan::call('cutover:smoke-fixtures', ['--create' => true, '--json' => true]));

        return array_intersect_key(json_decode(Artisan::output(), true), ['client_id' => 0, 'shoot_id' => 0]);
    }

    /** @return Collection<int, string> */
    private function recordStatements(): Collection
    {
        $statements = collect();
        DB::listen(fn (QueryExecuted $query) => $statements->push($query->sql));

        return $statements;
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function smoke(string ...$options): array
    {
        $arguments = ['--json' => true];
        foreach ($options as $option) {
            $arguments[$option] = true;
        }
        $exit = Artisan::call('cutover:smoke', $arguments);
        $raw = Artisan::output();

        return [$exit, json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $raw];
    }

    /** @return list<array<string, mixed>> */
    private function vectors(): array
    {
        $document = json_decode(file_get_contents(base_path(self::VECTORS)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $document['version']);
        $this->assertGreaterThanOrEqual(4, count($document['vectors']));

        return $document['vectors'];
    }

    /**
     * The vectors with every path moved under $root. They name production paths
     * (/var/www/backend/database/database.sqlite, /run/repro-mysql84-native/mysql.sock),
     * and the probes connect to the default connection, so on a host where those
     * exist a test would open the real database. Config and expected route values
     * move alike; the pinned hashes stay in test_route_hash_vectors.py.
     *
     * @return list<array<string, mixed>>
     */
    private function localVectors(string $root): array
    {
        return array_map(fn (array $vector) => ['config' => $this->relocate($vector['config'], $root),
            'route_values' => $this->relocate($vector['route_values'], $root)] + $vector, $this->vectors());
    }

    /** Every absolute path in $value, a url's query values included, prefixed with $root. */
    private function relocate(mixed $value, string $root): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->relocate($item, $root), $value);
        }
        if (! is_string($value)) {
            return $value;
        }
        if (str_starts_with($value, '/')) {
            return $root.$value;
        }
        $query = parse_url($value, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return $value;
        }
        parse_str($query, $parameters);

        return str_replace('?'.$query, '?'.http_build_query($this->relocate($parameters, $root), '', '&', PHP_QUERY_RFC3986), $value);
    }

    /** The route values name at least one path, and every one of them lies under $root. */
    private function assertPathsUnder(string $root, mixed $routeValues, string $label): void
    {
        $paths = $this->paths($routeValues);
        $this->assertNotEmpty($paths, $label.': no path in the route values');
        foreach ($paths as $path) {
            $this->assertStringStartsWith($root.'/', $path, $label.': the test reached a path outside its temporary directory');
        }
    }

    /** @return list<string> every absolute path in $value, a url's query values included */
    private function paths(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge([], ...array_map(fn ($item) => $this->paths($item), array_values($value)));
        }
        if (! is_string($value)) {
            return [];
        }
        if (str_starts_with($value, '/')) {
            return [$value];
        }
        parse_str((string) parse_url($value, PHP_URL_QUERY), $query);

        return array_values(array_filter($query, fn ($item) => is_string($item) && str_starts_with($item, '/')));
    }

    /** @return array<string, mixed> */
    private function routeConfig(): array
    {
        $saved = ['app.key' => config('app.key'), 'database.repro_leftover_transactions' => config('database.repro_leftover_transactions')];
        foreach (self::ROUTE_KEYS as $key) {
            $saved[$key] = config($key);
        }

        return $saved;
    }

    private function restore(array $saved): void
    {
        config($saved);
    }

    /** Replace exactly the config subtrees the route values read. */
    private function overlay(array $config): void
    {
        foreach (self::ROUTE_KEYS as $key) {
            config([$key => data_get($config, $key)]);
        }
        config([
            'app.key' => $config['app']['key'] ?? null,
            'database.repro_leftover_transactions' => $config['database']['repro_leftover_transactions'] ?? null,
        ]);
    }

    /** Python's canonical form: maps with sorted string keys, lists as maps of their indexes. */
    private function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($sort, $value);
            ksort($value, SORT_STRING);

            return (object) $value;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function assertOnlyObjects(mixed $value, string $label): void
    {
        $this->assertIsNotArray($value, $label.': route values must print every array as an object');
        if (is_object($value)) {
            foreach (get_object_vars($value) as $item) {
                $this->assertOnlyObjects($item, $label);
            }
        }
    }
}
