<?php

namespace Tests\MySql;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class DatabaseBackupTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/mysql-backup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('_backup_contract_probe');
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->directory.'/.*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function backup(array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('scripts/deploy/backup-database.php'), base_path(), $this->directory], null, $environment);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    public function test_backup_restores_rows_binary_values_triggers_and_constraints_to_the_scoped_test_database(): void
    {
        Schema::create('_backup_contract_probe', function (Blueprint $table): void {
            $table->id();
            $table->binary('payload');
        });
        DB::table('_backup_contract_probe')->insert(['payload' => "\x00\x01\x02\xff"]);
        $process = $this->backup();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $manifest = json_decode(file_get_contents($this->directory.'/database-backup.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mysql', $manifest['driver']);
        $this->assertTrue($manifest['consistent_transaction']);
        $this->assertTrue($manifest['includes_triggers_routines_events']);
        $this->assertFalse($manifest['application_booted']);
        $this->assertSame('repro_mysql_test', $manifest['database']);
        $this->assertSame(hash_file('sha256', $this->directory.'/database.sql.gz'), $manifest['sha256']);
        $this->assertSame([], glob($this->directory.'/.mysql-*'));
        $this->assertFileDoesNotExist($this->directory.'/.database-backup.lock');
        $config = DB::connection()->getConfig();
        $defaults = $this->directory.'/restore.cnf';
        file_put_contents($defaults, "[client]\nuser=repro_test\npassword=".$config['password']."\nhost=".$config['host']."\n");
        chmod($defaults, 0600);
        DB::table('_backup_contract_probe')->delete();
        $restore = new Process([getenv('REPRO_MYSQL_CLIENT') ?: '/opt/mysql84/bin/mysql', '--defaults-file='.$defaults, '--no-login-paths', '--database=repro_mysql_test']);
        $restore->setInput(gzdecode(file_get_contents($this->directory.'/database.sql.gz')));
        $restore->setTimeout(60);
        $restore->run();
        $this->assertTrue($restore->isSuccessful(), $restore->getErrorOutput());
        $this->assertSame("\x00\x01\x02\xff", DB::table('_backup_contract_probe')->value('payload'));
        $this->assertSame(35, (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', 'repro_mysql_test')->count());
        $this->assertSame($manifest['foreign_keys'], (int) DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', 'repro_mysql_test')->where('CONSTRAINT_TYPE', 'FOREIGN KEY')->count());
        $this->assertSame($manifest['server_uuid'], DB::selectOne('SELECT @@server_uuid AS uuid')->uuid);
    }

    public function test_incomplete_dump_is_removed_and_errors_never_expose_private_client_output(): void
    {
        $fake = $this->directory.'/failed-dump';
        file_put_contents($fake, "#!/usr/bin/env php\n<?php\nif (in_array('--version', \$argv, true)) { echo 'mysqldump Ver 8.4.13'; exit(0); }\necho '-- incomplete SQL'; fwrite(STDERR, 'ERROR 1234: PRIVATE-CLIENT-MARKER'); exit(2);\n");
        chmod($fake, 0700);
        $process = $this->backup(['REPRO_MYSQLDUMP' => $fake]);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('PRIVATE-CLIENT-MARKER', $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('"stage":"dump"', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/database.sql.gz');
        $this->assertFileDoesNotExist($this->directory.'/database-backup.json');
        $this->assertSame([], glob($this->directory.'/.mysql-*'));
        $this->assertFileDoesNotExist($this->directory.'/.database-backup.lock');
    }
}
