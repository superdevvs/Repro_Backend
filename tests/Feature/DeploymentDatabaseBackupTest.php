<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeploymentDatabaseBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/database-backup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/app', 0700);
        mkdir($this->directory.'/backup', 0700);
        symlink(dirname(__DIR__, 2).'/vendor', $this->directory.'/app/vendor');
        file_put_contents($this->directory.'/app/.env', "APP_ENV=testing\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/backup/.*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->directory.'/backup/*') as $file) {
            unlink($file);
        }
        unlink($this->directory.'/app/vendor');
        unlink($this->directory.'/app/.env');
        rmdir($this->directory.'/app');
        rmdir($this->directory.'/backup');
        foreach (glob($this->directory.'/source.sqlite*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function backup(): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/deploy/backup-database.php',
            $this->directory.'/app', $this->directory.'/backup'], null,
            ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/source.sqlite', 'DB_URL' => false]);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    public function test_sqlite_backup_includes_committed_wal_and_never_overwrites_the_first_backup(): void
    {
        $source = new \SQLite3($this->directory.'/source.sqlite');
        try {
            $source->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0');
            $source->exec('CREATE TABLE history (id INTEGER PRIMARY KEY, payload BLOB NOT NULL)');
            $source->exec("INSERT INTO history VALUES (7,X'000102FF')");
            $this->assertFileExists($this->directory.'/source.sqlite-wal');
            $process = $this->backup();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $manifest = json_decode(file_get_contents($this->directory.'/backup/database-backup.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('sqlite', $manifest['driver']);
            $this->assertFalse($manifest['application_booted']);
            $copy = new \SQLite3($this->directory.'/backup/database.sqlite', SQLITE3_OPEN_READONLY);
            $this->assertSame('000102FF', $copy->querySingle('SELECT hex(payload) FROM history WHERE id=7'));
            $copy->close();
            $digest = hash_file('sha256', $this->directory.'/backup/database.sqlite');
            $this->assertSame($digest, $manifest['sha256']);
            $this->assertSame(0600, fileperms($this->directory.'/backup/database.sqlite') & 0777);
            $this->assertFalse($this->backup()->isSuccessful());
            $this->assertSame($digest, hash_file('sha256', $this->directory.'/backup/database.sqlite'));
            $this->assertSame(1, $source->querySingle('SELECT COUNT(*) FROM history'));
        } finally {
            $source->close();
        }
    }

    public function test_shared_backup_directory_is_rejected_before_database_access(): void
    {
        chmod($this->directory.'/backup', 0755);
        $process = $this->backup();
        $this->assertFalse($process->isSuccessful());
        $this->assertSame([], glob($this->directory.'/backup/*'));
        $this->assertFileDoesNotExist($this->directory.'/source.sqlite');
    }
}
