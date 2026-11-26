<?php

namespace Tests\MySql;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class PointInTimeBackupTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/mysql-pitr-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/backup', 0700);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('_pitr_backup_probe');
        foreach (new \DirectoryIterator($this->directory.'/backup') as $file) {
            if (! $file->isDot()) {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory.'/backup');
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (! $file->isDot()) {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function serverUuid(): string
    {
        return DB::selectOne('SELECT @@server_uuid AS uuid')->uuid;
    }

    private function fixtureCredentials(string $extra = ''): string
    {
        $config = DB::connection()->getConfig();
        $file = $this->directory.'/credentials.cnf';
        file_put_contents($file, "[client]\nuser=".$config['username']."\npassword=".$config['password']."\n".$extra);
        chmod($file, 0600);

        return $file;
    }

    private function backup(string $uuid, string|false $credentials, array $environment = []): Process
    {
        $process = new Process([
            PHP_BINARY, base_path('scripts/deploy/backup-database.php'), base_path(), $this->directory.'/backup',
            '--pitr', '--expected-server-uuid', $uuid,
        ], null, ['REPRO_MYSQL_BACKUP_DEFAULTS_FILE' => $credentials] + $environment);
        $process->setTimeout(90);
        $process->run();

        return $process;
    }

    public function test_real_dump_binds_the_coordinate_pair_to_its_own_snapshot_and_artifact(): void
    {
        $credentials = getenv('REPRO_MYSQL_PITR_TEST_DEFAULTS_FILE');
        if ($credentials === false || $credentials === '') {
            $this->markTestSkipped('The isolated MySQL backup account is not configured.');
        }
        Schema::create('_pitr_backup_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('payload');
        });
        $marker = 'pitr-snapshot-'.bin2hex(random_bytes(12));
        DB::table('_pitr_backup_probe')->insert(['id' => 17, 'payload' => $marker]);

        // Make application-account authentication unusable in the backup
        // process. Both its metadata checks and dump must use the supplied account.
        $process = $this->backup($this->serverUuid(), $credentials, [
            'DB_USERNAME' => 'invalid_pitr_application_account', 'DB_PASSWORD' => 'unused-application-password',
        ]);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $artifact = $this->directory.'/backup/database.sql.gz';
        $manifest = json_decode(file_get_contents($this->directory.'/backup/database-backup.json'), true, flags: JSON_THROW_ON_ERROR);
        $sql = gzdecode(file_get_contents($artifact));
        $this->assertSame($this->serverUuid(), $manifest['server_uuid']);
        $this->assertSame(hash_file('sha256', $artifact), $manifest['sha256']);
        $this->assertSame(0600, fileperms($artifact) & 0777);
        $this->assertSame(1, preg_match("/^-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='([^']+)', SOURCE_LOG_POS=(\d+);$/m", $sql, $match));
        $this->assertSame([
            'binlog_file' => $match[1], 'binlog_position' => (int) $match[2], 'coordinate_source' => 'mysqldump-source-data',
        ], $manifest['pitr']);
        $this->assertGreaterThanOrEqual(4, $manifest['pitr']['binlog_position']);
        $this->assertDoesNotMatchRegularExpression('/^CHANGE (?:REPLICATION SOURCE|MASTER) TO\b/m', $sql);
        $this->assertStringContainsString("(17,'".$marker."')", $sql);
        $this->assertSame([], glob($this->directory.'/backup/.mysql-*'));
    }

    public function test_pitr_refuses_missing_credentials_wrong_server_and_dump_filter_settings(): void
    {
        $uuid = $this->serverUuid();
        $missing = $this->backup($uuid, false);
        $this->assertFalse($missing->isSuccessful());
        $this->assertStringContainsString('"stage":"credentials"', $missing->getErrorOutput());

        $wrongUuid = ($uuid[0] === '0' ? '1' : '0').substr($uuid, 1);
        $wrong = $this->backup($wrongUuid, $this->fixtureCredentials());
        $this->assertFalse($wrong->isSuccessful());
        $this->assertStringContainsString('"stage":"connection"', $wrong->getErrorOutput());

        $filter = $this->backup($uuid, $this->fixtureCredentials("[mysqldump]\nignore-table=unapproved_table\n"));
        $this->assertFalse($filter->isSuccessful());
        $this->assertStringContainsString('"stage":"credentials"', $filter->getErrorOutput());
        $this->assertSame([], glob($this->directory.'/backup/*'));
        $this->assertSame([], glob($this->directory.'/backup/.mysql-*'));
    }

    public function test_success_exit_without_a_unique_safe_header_position_never_publishes_a_pitr_manifest(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $header = "-- MySQL dump 10.13\n--\n-- Position to start replication or point-in-time recovery from\n--\n\n";
        $valid = "-- CHANGE REPLICATION SOURCE TO SOURCE_LOG_FILE='binlog.000001', SOURCE_LOG_POS=42;\n";
        $body = "-- Table structure for table `_pitr_backup_probe`\nCREATE TABLE `_pitr_backup_probe` (id int);\n";
        $footer = "-- Dump completed on 2026-11-22 18:00:00\n";
        $fixtures = [
            $header.$body.$valid.$footer,
            $header.$valid.$valid.$body.$footer,
            $header.substr($valid, 3).$body.$footer,
            $header.str_replace('binlog.000001', '../binlog.000001', $valid).$body.$footer,
            $header.str_replace('POS=42', 'POS=2', $valid).$body.$footer,
            $header.$valid.$body,
        ];
        $fake = $this->directory.'/fake-dump';
        foreach ([...$fixtures, $header.$valid.$body.$footer] as $index => $fixture) {
            $script = "#!/usr/bin/env php\n<?php\n"
                ."if (in_array('--version', \$argv, true)) { echo ".var_export('mysqldump Ver '.$version, true)."; exit(0); }\n"
                ."if (! in_array('--source-data=2', \$argv, true) || ! in_array('--single-transaction', \$argv, true)) { exit(3); }\n"
                .'echo '.var_export($fixture, true).";\nfwrite(STDERR, 'PRIVATE-CLIENT-MARKER');\n";
            file_put_contents($fake, $script);
            chmod($fake, 0700);
            $process = $this->backup($this->serverUuid(), $this->fixtureCredentials(), ['REPRO_MYSQLDUMP' => $fake]);
            $this->assertFalse($process->isSuccessful());
            $expectedStage = $index < count($fixtures) ? 'pitr_coordinates' : 'pitr_diagnostics';
            $this->assertStringContainsString('"stage":"'.$expectedStage.'"', $process->getErrorOutput());
            $this->assertStringNotContainsString('PRIVATE-CLIENT-MARKER', $process->getOutput().$process->getErrorOutput());
            $this->assertFileDoesNotExist($this->directory.'/backup/database.sql.gz');
            $this->assertFileDoesNotExist($this->directory.'/backup/database-backup.json');
            $this->assertSame([], glob($this->directory.'/backup/.mysql-*'));
        }
    }
}
