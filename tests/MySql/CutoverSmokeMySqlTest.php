<?php

namespace Tests\MySql;

use App\Support\Cutover\CutoverEngineProbe;
use App\Support\Cutover\CutoverSmokeFixture;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concerns\CutoverSmokeRootStandIn;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

/**
 * cutover:smoke on MySQL (design v2, 5.3): what the SQLite feature tests cannot
 * show. No RefreshDatabase transaction: the smoke commits for real, and the
 * binary log shows what it wrote.
 *
 * The first two tests need only the profile's repro_test. The other two also
 * need, on the same server (they skip without them):
 *
 *   REPRO_MYSQL_SMOKE_READER_USERNAME, REPRO_MYSQL_SMOKE_READER_PASSWORD
 *     SELECT on repro_mysql_test.* and on repro_mysql_control.writer_token
 *     (the app account while the gate is closed)
 *   REPRO_MYSQL_SMOKE_BINLOG_USERNAME, REPRO_MYSQL_SMOKE_BINLOG_PASSWORD
 *     REPLICATION SLAVE, REPLICATION CLIENT on *.* (reads the binary log)
 *
 * .github/workflows/mysql.yml provisions both next to repro_pitr_fixture ('%' as
 * the host there; a local socket-only fixture uses localhost):
 *   CREATE USER 'repro_smoke_reader'@'%' IDENTIFIED BY '<random>';
 *   GRANT SELECT ON repro_mysql_test.* TO 'repro_smoke_reader'@'%';
 *   GRANT SELECT ON repro_mysql_control.writer_token TO 'repro_smoke_reader'@'%';
 *   CREATE USER 'repro_smoke_binlog'@'%' IDENTIFIED BY '<random>';
 *   GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'repro_smoke_binlog'@'%';
 * and puts the four variables in $GITHUB_ENV (passwords masked). The binary log
 * is on by default in the mysql:8.4 service.
 */
final class CutoverSmokeMySqlTest extends TestCase
{
    use CutoverSmokeRootStandIn;
    use FreshDatabaseOutsideTransaction;

    /** Bookkeeping events that carry no data change. */
    private const FRAMING_EVENTS = ['Format_desc', 'Previous_gtids', 'Rotate', 'Stop'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->standInForRoot();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame(0, DB::transactionLevel(), 'the smoke must run outside any test transaction');
    }

    public function test_engine_only_reports_this_server_and_its_uuid(): void
    {
        $server = DB::selectOne('SELECT @@server_uuid AS uuid, @@version AS version');

        [$exit, $out, $raw] = $this->smoke('--engine-only');

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertSame('mysql', $out['engine']);
        $this->assertSame('mysql', $out['connection']);
        $this->assertSame($server->uuid, $out['server_uuid']);
        $this->assertSame($server->version, $out['server_version']);
        $this->assertSame('repro_mysql_test', $out['database']);
        $this->assertArrayNotHasKey('password', $out['route_values']['database.connections']['mysql']);
        $password = (string) config('database.connections.mysql.password');
        if ($password !== '') {
            $this->assertStringNotContainsString($password, $raw);
        }
        $this->assertSame(0, $this->sessionReadOnly(DB::connection()), 'the engine probe left the session read only');
    }

    public function test_read_only_session_refuses_a_write_and_puts_the_session_back(): void
    {
        // The writer policy off on this connection, so only the session itself can refuse the write.
        config(['database.connections.cutover_smoke_rw' => ['repro_writer_policy' => false] + config('database.connections.mysql')]);
        $connection = DB::connection('cutover_smoke_rw');
        $row = ['key' => 'cutover-smoke-read-only', 'value' => 'v', 'expiration' => 0];
        try {
            try {
                CutoverEngineProbe::readOnly($connection, fn () => $connection->table('cache')->insert($row));
                $this->fail('a write inside the read-only session succeeded');
            } catch (QueryException $refused) {
                // ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION
                $this->assertSame(1792, $refused->errorInfo[1] ?? null, $refused->getMessage());
            }
            $this->assertSame(0, $this->sessionReadOnly($connection), 'READ WRITE was not restored');
            $this->assertSame(0, $connection->table('cache')->where('key', $row['key'])->count());

            $connection->statement('SET SESSION TRANSACTION READ ONLY');
            $this->assertSame(1, CutoverEngineProbe::readOnly($connection, fn () => (int) $connection->selectOne('SELECT 1 AS one')->one));
            $this->assertSame(1, $this->sessionReadOnly($connection), 'a session that was read only before must stay read only');
            $connection->statement('SET SESSION TRANSACTION READ WRITE');

            $connection->table('cache')->insert($row);
            $this->assertSame(1, $connection->table('cache')->where('key', $row['key'])->count());
        } finally {
            DB::purge('cutover_smoke_rw');
        }
    }

    public function test_read_only_as_a_select_only_account_writes_nothing_and_puts_the_session_back(): void
    {
        [$reader, $binlog] = $this->accounts();
        $fixture = $this->createFixtures();
        $from = $this->binlogCoordinate($binlog);

        $this->asDefaultAccount($reader, function () use ($reader, $fixture) {
            $this->assertStringStartsWith($reader['username'].'@', DB::selectOne('SELECT CURRENT_USER() AS who')->who);

            [$exit, $out, $raw] = $this->smoke('--read-only');

            $this->assertSame(0, $exit, $raw);
            $this->assertTrue($out['ok'], $raw);
            $this->assertSame([
                'client_id' => $fixture['client_id'], 'shoot_id' => $fixture['shoot_id'], 'client_read' => true, 'shoot_read' => true,
                'encrypted_value_decrypts' => true, 'smoke_queue' => 'database:cutover-smoke', 'smoke_queue_pending' => 0,
                'smoke_queue_paused' => false,
            ], $out['checks']);
            $this->assertSame(0, $this->sessionReadOnly(DB::connection()), 'READ WRITE was not restored');

            // The gate is closed for this account: the write smoke refuses and writes nothing.
            [$exit, $out, $raw] = $this->smoke('--write');

            $this->assertSame(1, $exit, $raw);
            $this->assertFalse($out['ok']);
            $this->assertArrayNotHasKey('job', $out['checks']);
            $this->assertSame(0, $this->sessionReadOnly(DB::connection()));
        });

        $this->assertSame([], $this->events($binlog, $from), 'the read-only smoke or the refused write left binary log events');
    }

    public function test_write_under_open_grants_changes_only_the_smoke_tables_in_the_binary_log(): void
    {
        [, $binlog] = $this->accounts();
        $fixture = $this->createFixtures();
        $from = $this->binlogCoordinate($binlog);

        [$exit, $out, $raw] = $this->smoke('--write');

        $this->assertSame(0, $exit, $raw);
        $this->assertTrue($out['ok'], $raw);
        $this->assertTrue($out['checks']['read_back']);
        $this->assertSame(['queued' => true, 'ran' => true, 'row_left' => false, 'failed' => false], $out['checks']['job']);
        $this->assertSame(CutoverSmokeFixture::SMOKE_TABLES, $out['checks']['smoke_tables']);
        $this->assertSame($out['checks']['value'], DB::table('users')->where('id', $fixture['client_id'])->value('company_notes'));
        $this->assertSame($out['checks']['value'], DB::table('shoots')->where('id', $fixture['shoot_id'])->value('company_notes'));
        $this->assertSame([0, 0], [DB::table('jobs')->count(), DB::table('failed_jobs')->count()]);

        $events = $this->events($binlog, $from);
        $tables = array_values(array_unique(array_column(array_filter($events, fn (array $event) => $event['type'] === 'Table_map'), 'table')));
        $app = array_values(array_filter($tables, fn (string $table) => str_starts_with($table, 'repro_mysql_test.')));
        sort($app);
        $expected = array_map(fn (string $table) => 'repro_mysql_test.'.$table, CutoverSmokeFixture::SMOKE_TABLES);
        sort($expected);
        $this->assertSame($expected, $app, 'the write smoke changed tables outside smoke_tables');
        $this->assertSame([], array_values(array_diff($tables, $app, ['repro_mysql_control.writer_token'])), 'a schema other than the app and the token');
        foreach ($events as $event) {
            if ($event['type'] === 'Query') {
                $this->assertSame('BEGIN', $event['info'], 'a statement-logged write or DDL');
            }
        }
    }

    /** @return array{client_id: int, shoot_id: int} */
    private function createFixtures(): array
    {
        $exit = Artisan::call('cutover:smoke-fixtures', ['--create' => true, '--json' => true]);
        $raw = Artisan::output();
        $this->assertSame(0, $exit, $raw);

        return array_intersect_key(json_decode($raw, true, flags: JSON_THROW_ON_ERROR), ['client_id' => 0, 'shoot_id' => 0]);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function smoke(string $mode): array
    {
        $exit = Artisan::call('cutover:smoke', [$mode => true, '--json' => true]);
        $raw = Artisan::output();

        return [$exit, json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $raw];
    }

    private function sessionReadOnly(Connection $connection): int
    {
        return (int) $connection->selectOne('SELECT @@SESSION.transaction_read_only AS read_only')->read_only;
    }

    /**
     * @return array{0: array{username: string, password: string}, 1: PDO} the reader account and a binary log reader
     */
    private function accounts(): array
    {
        $accounts = [];
        foreach (['READER', 'BINLOG'] as $role) {
            $username = getenv("REPRO_MYSQL_SMOKE_{$role}_USERNAME");
            $password = getenv("REPRO_MYSQL_SMOKE_{$role}_PASSWORD");
            if (! is_string($username) || $username === '' || ! is_string($password)) {
                $this->markTestSkipped("REPRO_MYSQL_SMOKE_{$role}_USERNAME and _PASSWORD are not configured (see this file's docblock).");
            }
            $accounts[$role] = ['username' => $username, 'password' => $password];
        }
        $config = config('database.connections.mysql');
        $dsn = ($config['unix_socket'] ?? '') !== '' ? 'mysql:unix_socket='.$config['unix_socket']
            : 'mysql:host='.$config['host'].';port='.$config['port'];
        $binlog = new PDO($dsn, $accounts['BINLOG']['username'], $accounts['BINLOG']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return [$accounts['READER'], $binlog];
    }

    /** Run $test with the app's default connection logged in as $account, then put repro_test back. */
    private function asDefaultAccount(array $account, Closure $test): void
    {
        $saved = config('database.connections.mysql');
        config(['database.connections.mysql' => ['username' => $account['username'], 'password' => $account['password']] + $saved]);
        DB::purge('mysql');
        try {
            $test();
        } finally {
            config(['database.connections.mysql' => $saved]);
            DB::purge('mysql');
        }
    }

    /** @return array{0: string, 1: int} binary log file and position */
    private function binlogCoordinate(PDO $binlog): array
    {
        $status = $binlog->query('SHOW BINARY LOG STATUS')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($status, 'binary logging is off on this server');

        return [$status['File'], (int) $status['Position']];
    }

    /**
     * Every data event after $from, across later files too.
     *
     * @param  array{0: string, 1: int}  $from
     * @return list<array{type: string, info: string, table: ?string}>
     */
    private function events(PDO $binlog, array $from): array
    {
        $files = array_column($binlog->query('SHOW BINARY LOGS')->fetchAll(PDO::FETCH_ASSOC), 'Log_name');
        $this->assertContains($from[0], $files);
        $events = [];
        foreach (array_slice($files, array_search($from[0], $files, true)) as $file) {
            $sql = 'SHOW BINLOG EVENTS IN '.$binlog->quote($file).($file === $from[0] ? ' FROM '.$from[1] : '');
            foreach ($binlog->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $event) {
                if (in_array($event['Event_type'], self::FRAMING_EVENTS, true)) {
                    continue;
                }
                $table = preg_match('/\Atable_id: \d+ \((.+)\)\z/', $event['Info'], $match) ? $match[1] : null;
                $events[] = ['type' => $event['Event_type'], 'info' => $event['Info'], 'table' => $table];
            }
        }

        return $events;
    }
}
