<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StudioAudioStorageDeploymentTest extends TestCase
{
    private string $appPath;

    private string $parentPath;

    private string $audioPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_getgrnam') || ! posix_getgrnam('www-data')) {
            $this->markTestSkipped('Requires Linux and the production shared service group.');
        }
        $this->appPath = sys_get_temp_dir().'/repro-audio-storage-test-'.bin2hex(random_bytes(8));
        $this->parentPath = $this->appPath.'/storage/app/private';
        $this->audioPath = $this->parentPath.'/studio-audio';
        mkdir($this->parentPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath)) {
            $this->removeFixture($this->appPath);
        }
        parent::tearDown();
    }

    public function test_creation_overrides_restrictive_umask_and_second_run_preserves_data_and_parent(): void
    {
        $parentMode = fileperms($this->parentPath) & 07777;
        $mask = umask(0077);
        try {
            $first = $this->runHelper();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        } finally {
            umask($mask);
        }
        clearstatcache();
        $this->assertSame(02770, fileperms($this->audioPath) & 07777);
        $this->assertSame(posix_getgrnam('www-data')['gid'], filegroup($this->audioPath));
        $inode = fileinode($this->audioPath);
        file_put_contents($this->audioPath.'/preserved.json', '{"fixture":true}');
        $second = $this->runHelper();
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        clearstatcache();
        $this->assertSame($inode, fileinode($this->audioPath));
        $this->assertSame('{"fixture":true}', file_get_contents($this->audioPath.'/preserved.json'));
        $this->assertSame($parentMode, fileperms($this->parentPath) & 07777);
    }

    public function test_symlinks_and_regular_files_are_refused_without_replacing_them(): void
    {
        foreach ([$this->appPath.'/missing', $this->parentPath] as $destination) {
            symlink($destination, $this->audioPath);
            $this->assertNotSame(0, $this->runHelper()->getExitCode());
            $this->assertSame($destination, readlink($this->audioPath));
            unlink($this->audioPath);
        }
        file_put_contents($this->audioPath, 'preserve this file');
        $this->assertNotSame(0, $this->runHelper()->getExitCode());
        $this->assertSame('preserve this file', file_get_contents($this->audioPath));
    }

    public function test_existing_wrong_permissions_are_refused_without_broadening_access(): void
    {
        mkdir($this->audioPath, 0700);
        chmod($this->audioPath, 0700);
        file_put_contents($this->audioPath.'/preserved', 'keep');
        $this->assertNotSame(0, $this->runHelper()->getExitCode());
        clearstatcache();
        $this->assertSame(0700, fileperms($this->audioPath) & 07777);
        $this->assertSame('keep', file_get_contents($this->audioPath.'/preserved'));
    }

    public function test_missing_or_symlinked_parent_is_refused_without_modifying_other_trees(): void
    {
        rmdir($this->parentPath);
        $this->assertNotSame(0, $this->runHelper()->getExitCode());
        $this->assertDirectoryDoesNotExist($this->parentPath);
        mkdir($this->appPath.'/alternate');
        symlink($this->appPath.'/alternate', $this->parentPath);
        $this->assertNotSame(0, $this->runHelper()->getExitCode());
        $this->assertDirectoryDoesNotExist($this->appPath.'/alternate/studio-audio');
        $this->assertTrue(is_link($this->parentPath));
    }

    public function test_shared_group_allows_web_and_worker_writes_but_excludes_unrelated_user(): void
    {
        $this->assertSame(0, $this->runHelper()->getExitCode());
        $code = <<<'PHP'
if (! posix_setgid((int) $argv[2]) || ! posix_setuid((int) $argv[1])) { exit(2); }
umask(0007);
if ($argv[3] === 'write') {
    exit(file_put_contents($argv[4], 'synthetic audio fixture') === false ? 1 : 0);
}
exit(@file_get_contents($argv[4]) === 'synthetic audio fixture' ? 0 : 1);
PHP;
        $group = (string) posix_getgrnam('www-data')['gid'];
        $path = $this->audioPath.'/identity-fixture';
        foreach ([[$group, $group, 'write', 0], ['1000', $group, 'read', 0], ['1000', $group, 'write', 0], [$group, $group, 'read', 0], ['65534', '65534', 'read', 1]] as [$uid, $gid, $operation, $expected]) {
            $command = [PHP_BINARY, '-r', $code, $uid, $gid, $operation, $path];
            if (posix_geteuid() !== 0) {
                $command = array_merge(['sudo', '-n', '--'], $command);
            }
            $process = new Process($command);
            $process->run();
            $this->assertSame($expected, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }
    }

    private function runHelper(): Process
    {
        $command = ['bash', dirname(__DIR__, 2).'/scripts/deploy/provision-studio-audio-storage.sh', $this->appPath];
        if (posix_geteuid() !== 0) {
            // Ubuntu CI grants passwordless sudo. Preserve fixture ownership while
            // using the same service group as the production deployment worker.
            $command = array_merge(['sudo', '-n', '-u', '#'.posix_geteuid(), '-g', 'www-data', '--'], $command);
        }
        $process = new Process($command);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeFixture($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
