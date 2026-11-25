<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLite3;
use Symfony\Component\Process\Process;

class SqliteNightlyCopyTest extends TestCase
{
    private const WRITER = <<<'PHP'
        [$source, $stop, $stats, $ready] = array_slice($argv, 1);
        $db = new SQLite3($source);
        $db->enableExceptions(true);
        $db->busyTimeout(2000);
        $max = 0.0;
        $failed = 0;
        $writes = 0;
        $started = microtime(true);
        touch($ready);
        while (! file_exists($stop) && microtime(true) - $started < 60) {
            $begin = microtime(true);
            try {
                $db->exec('BEGIN IMMEDIATE');
                $db->exec('INSERT INTO shoots (payload, written_at) VALUES (randomblob(512), '.microtime(true).')');
                $db->exec('INSERT INTO invoices (shoot_id) VALUES (last_insert_rowid())');
                $db->exec('COMMIT');
                $writes++;
            } catch (Throwable $error) {
                $failed++;
                try { $db->exec('ROLLBACK'); } catch (Throwable) {}
            }
            $max = max($max, microtime(true) - $begin);
            usleep(1000);
        }
        file_put_contents($stats, json_encode(['max_seconds' => $max, 'failed' => $failed, 'writes' => $writes]));
        PHP;

    private string $directory;

    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/repro-nightly-copy-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/app', 0700);
        mkdir($this->directory.'/copies', 0700);
        mkdir($this->directory.'/scratch', 0700);
        $this->source = $this->directory.'/app/database.sqlite';
        $this->destination = $this->directory.'/copies';
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            $path->isDir() && ! $path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_copy_is_consistent_and_never_blocks_an_active_writer(): void
    {
        $this->createSource(rows: 40000);
        $stop = $this->directory.'/stop';
        $ready = $this->directory.'/ready';
        $stats = $this->directory.'/stats.json';
        $writer = new Process([PHP_BINARY, '-r', self::WRITER, '--', $this->source, $stop, $stats, $ready]);
        $writer->setTimeout(90);
        $writer->start();
        $this->waitFor($ready);
        usleep(100000);

        $started = microtime(true);
        $copy = $this->runCopy();
        $finished = microtime(true);
        usleep(100000);
        touch($stop);
        $writer->wait();

        $this->assertSame(0, $copy->getExitCode(), $copy->getErrorOutput());
        $result = json_decode(file_get_contents($stats), true);
        $this->assertSame(0, $result['failed'], 'The writer hit a lock error during the copy.');
        $this->assertLessThan(1.0, $result['max_seconds'], 'A write waited too long behind the copy.');
        $live = new SQLite3($this->source, SQLITE3_OPEN_READONLY);
        $during = $live->querySingle("SELECT COUNT(*) FROM shoots WHERE written_at BETWEEN {$started} AND {$finished}");
        $liveRows = $live->querySingle('SELECT COUNT(*) FROM shoots');
        $live->close();
        $this->assertGreaterThan(0, $during, 'No write committed while the copy ran.');

        $copies = glob($this->destination.'/database-*.sqlite');
        $this->assertCount(1, $copies);
        $this->assertMatchesRegularExpression('/\/database-\d{8}-\d{6}\.sqlite$/', $copies[0]);
        $this->assertSame(
            hash_file('sha256', $copies[0]).'  '.basename($copies[0])."\n",
            file_get_contents($copies[0].'.sha256'),
        );
        $snapshot = new SQLite3($copies[0], SQLITE3_OPEN_READONLY);
        $this->assertSame('ok', $snapshot->querySingle('PRAGMA quick_check'));
        $this->assertSame('delete', $snapshot->querySingle('PRAGMA journal_mode'));
        $shoots = $snapshot->querySingle('SELECT COUNT(*) FROM shoots');
        // Each write adds one row to both tables in one transaction, so a
        // consistent snapshot always has matching counts.
        $this->assertSame($shoots, $snapshot->querySingle('SELECT COUNT(*) FROM invoices'));
        $this->assertGreaterThanOrEqual(40000, $shoots);
        $this->assertLessThanOrEqual($liveRows, $shoots);
        $snapshot->close();
        $this->assertFileDoesNotExist($copies[0].'-wal');
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($copies[0]) & 0777);
        }
        $status = json_decode(file_get_contents($this->destination.'/last-run.json'), true);
        $this->assertSame('ok', $status['status']);
        $this->assertSame(basename($copies[0]), $status['file']);
        $this->assertSame(hash_file('sha256', $copies[0]), $status['sha256']);
        $this->assertSame([], glob($this->destination.'/.partial-*'));
    }

    public function test_rotation_keeps_the_newest_copies_and_nothing_else_is_touched(): void
    {
        $this->createSource(rows: 10);
        foreach (['20260101-010000', '20260102-010000', '20260103-010000'] as $stamp) {
            file_put_contents($this->destination."/database-{$stamp}.sqlite", 'old copy');
            file_put_contents($this->destination."/database-{$stamp}.sqlite.sha256", 'old checksum');
        }
        file_put_contents($this->destination.'/notes.txt', 'operator notes');
        file_put_contents($this->destination.'/database-manual.sqlite', 'manual copy');
        file_put_contents($this->destination.'/.partial-20260101-010000-0123abcd.sqlite', 'crashed copy');

        $process = $this->runCopy(['--keep=2']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $names = array_map('basename', glob($this->destination.'/*'));
        $copies = preg_grep('/^database-\d{8}-\d{6}\.sqlite$/', $names);
        $this->assertCount(2, $copies);
        $this->assertContains('database-20260103-010000.sqlite', $copies);
        $this->assertNotContains('database-20260101-010000.sqlite.sha256', $names);
        $this->assertContains('notes.txt', $names);
        $this->assertContains('database-manual.sqlite', $names);
        $this->assertFileDoesNotExist($this->destination.'/.partial-20260101-010000-0123abcd.sqlite');
    }

    public function test_a_rollback_journal_source_is_refused_because_it_would_block_writers(): void
    {
        $this->createSource(rows: 10, journal: 'DELETE');

        $process = $this->runCopy();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('WAL', $process->getErrorOutput());
        $this->assertSame([], glob($this->destination.'/database-*'));
        $this->assertSame([], glob($this->destination.'/.partial-*'));
        $this->assertSame('failed', json_decode(file_get_contents($this->destination.'/last-run.json'), true)['status']);
    }

    public function test_a_copy_on_the_same_disk_as_the_live_file_needs_an_explicit_override(): void
    {
        $this->createSource(rows: 10);

        $process = $this->runCopy(allowSameDevice: false);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('same disk', $process->getErrorOutput());
        $this->assertSame([], glob($this->destination.'/database-*'));
    }

    public function test_a_second_run_waits_for_nothing_and_exits_while_the_first_holds_the_lock(): void
    {
        $this->createSource(rows: 10);
        $lock = fopen($this->destination.'/.sqlite-nightly-copy.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        try {
            $process = $this->runCopy();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(75, $process->getExitCode());
        $this->assertSame([], glob($this->destination.'/database-*'));
    }

    public function test_the_process_must_run_with_the_database_files_group(): void
    {
        $this->assertTrue(function_exists('posix_getegid'), 'The copy script relies on the posix extension.');
        // Root may use any group; anyone else may use one of their supplementary groups.
        $otherGroups = array_values(array_diff(posix_getgroups(), [posix_getegid()]));
        $foreignGroup = posix_geteuid() === 0 ? posix_getegid() + 4242 : ($otherGroups[0] ?? null);
        if ($foreignGroup === null) {
            $this->markTestIncomplete('This account has no second group to give the source file.');
        }
        $this->createSource(rows: 10);
        $this->assertTrue(chgrp($this->source, $foreignGroup));

        $process = $this->runCopy();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('group', $process->getErrorOutput());
        $this->assertSame([], glob($this->destination.'/database-*'));
    }

    public function test_the_copy_refuses_to_run_when_it_cannot_check_the_group(): void
    {
        $this->createSource(rows: 10);

        // Without posix the group and owner checks cannot run, so fail closed.
        $process = $this->runCopy(ini: ['disable_functions=posix_getegid,posix_geteuid']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('posix', $process->getErrorOutput());
        $this->assertSame([], glob($this->destination.'/database-*'));
    }

    public function test_restore_proof_verifies_checksum_and_opens_a_scratch_copy(): void
    {
        $this->createSource(rows: 25);
        $this->assertSame(0, $this->runCopy()->getExitCode());
        $copy = glob($this->destination.'/database-*.sqlite')[0];

        $proof = $this->runRestoreProof($copy);

        $this->assertSame(0, $proof->getExitCode(), $proof->getErrorOutput());
        $report = json_decode(trim($proof->getOutput()), true);
        $this->assertSame('passed', $report['restore_proof']);
        $this->assertSame('ok', $report['integrity_check']);
        $this->assertSame(hash_file('sha256', $copy), $report['sha256']);
        $this->assertSame(2, $report['tables']);
        $this->assertSame(50, $report['rows']);
        $this->assertSame(['invoices' => 25, 'shoots' => 25], $report['row_counts']);
        $this->assertSame([], glob($this->directory.'/scratch/*'), 'The scratch restore is removed afterwards.');
    }

    public function test_restore_proof_rejects_a_copy_whose_checksum_does_not_match(): void
    {
        $this->createSource(rows: 25);
        $this->assertSame(0, $this->runCopy()->getExitCode());
        $copy = glob($this->destination.'/database-*.sqlite')[0];
        $handle = fopen($copy, 'r+');
        fseek($handle, 200);
        fwrite($handle, 'X');
        fclose($handle);

        $proof = $this->runRestoreProof($copy);

        $this->assertNotSame(0, $proof->getExitCode());
        $this->assertStringContainsString('checksum', $proof->getErrorOutput());
        $this->assertSame([], glob($this->directory.'/scratch/*'));
    }

    public function test_restore_proof_requires_the_checksum_file(): void
    {
        $this->createSource(rows: 5);
        $this->assertSame(0, $this->runCopy()->getExitCode());
        $copy = glob($this->destination.'/database-*.sqlite')[0];
        unlink($copy.'.sha256');

        $proof = $this->runRestoreProof($copy);

        $this->assertNotSame(0, $proof->getExitCode());
        $this->assertSame([], glob($this->directory.'/scratch/*'));
    }

    public function test_schedule_templates_run_the_same_copy_with_the_web_group_on_the_second_disk(): void
    {
        $root = dirname(__DIR__, 2).'/scripts/backup';
        $command = '/usr/bin/php /var/www/backend/scripts/backup/sqlite-nightly-copy.php '
            .'/var/www/backend/database/database.sqlite /mnt/16tb/repro-db-backups --keep=7';
        $service = file_get_contents($root.'/systemd/repro-sqlite-nightly-copy.service');
        $timer = file_get_contents($root.'/systemd/repro-sqlite-nightly-copy.timer');
        $cron = array_values(array_filter(
            explode("\n", file_get_contents($root.'/sqlite-nightly-copy.cron')),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        $this->assertStringContainsString("\nExecStart={$command}\n", $service);
        $this->assertStringContainsString("\nUser=maverick\n", $service);
        $this->assertStringContainsString("\nGroup=www-data\n", $service);
        $this->assertStringContainsString("\nRequiresMountsFor=/mnt/16tb /var/www/backend/database\n", $service);
        $this->assertStringContainsString("\nReadWritePaths=/mnt/16tb/repro-db-backups /var/www/backend/database\n", $service);
        $this->assertStringContainsString("\nOnCalendar=*-*-* 01:40:00\n", $timer);
        $this->assertStringContainsString("\nPersistent=true\n", $timer);
        $this->assertCount(1, $cron);
        $this->assertStringStartsWith('40 1 * * * sg www-data -c "nice -n 10 ionice -c2 -n7 '.$command.'"', $cron[0]);
        $this->assertStringNotContainsString('--allow-same-device', $service.$cron[0]);
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/backup/sqlite-nightly-copy.php');
    }

    private function createSource(int $rows, string $journal = 'WAL'): void
    {
        $db = new SQLite3($this->source);
        $db->enableExceptions(true);
        $this->assertSame(strtolower($journal), $db->querySingle("PRAGMA journal_mode={$journal}"));
        $db->exec('CREATE TABLE shoots (id INTEGER PRIMARY KEY, payload BLOB, written_at REAL)');
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, shoot_id INTEGER)');
        $db->exec('BEGIN');
        $db->exec("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < {$rows})
            INSERT INTO shoots (payload, written_at) SELECT randomblob(1024), 0 FROM n");
        $db->exec('INSERT INTO invoices (shoot_id) SELECT id FROM shoots');
        $db->exec('COMMIT');
        $db->close();
    }

    private function runCopy(array $options = [], bool $allowSameDevice = true, array $ini = []): Process
    {
        $process = new Process([
            PHP_BINARY, ...array_map(static fn (string $setting): string => '-d'.$setting, $ini),
            dirname(__DIR__, 2).'/scripts/backup/sqlite-nightly-copy.php',
            $this->source, $this->destination, '--reserve-bytes=0',
            ...($allowSameDevice ? ['--allow-same-device'] : []), ...$options,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function runRestoreProof(string $copy): Process
    {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2).'/scripts/backup/sqlite-restore-proof.php', $copy, $this->directory.'/scratch',
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }
}
