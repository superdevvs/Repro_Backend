<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PublicStorageDeploymentTest extends TestCase
{
    private string $appPath;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Production storage deployment requires Linux symlink semantics.');
        }

        $this->appPath = sys_get_temp_dir().'/repro-storage-test-'.bin2hex(random_bytes(8));
        mkdir($this->appPath.'/public', 0755, true);
        mkdir($this->appPath.'/storage/app/public', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        if (isset($this->appPath)) {
            $this->removeFixture($this->appPath);
        }
        parent::tearDown();
    }

    public function testMissingLinkIsCreatedAndCorrectLinkIsPreserved(): void
    {
        $first = $this->runHelper('ensure');
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame($this->appPath.'/storage/app/public', readlink($this->appPath.'/public/storage'));
        $inode = lstat($this->appPath.'/public/storage')['ino'];

        $second = $this->runHelper('ensure');
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        clearstatcache(true, $this->appPath.'/public/storage');
        $this->assertSame($inode, lstat($this->appPath.'/public/storage')['ino']);
    }

    public function testIncorrectAndDanglingLinksAreRepairedWithoutChangingTheirTargets(): void
    {
        mkdir($this->appPath.'/other');
        file_put_contents($this->appPath.'/other/preserve.txt', 'preserve');
        foreach ([$this->appPath.'/other', $this->appPath.'/missing'] as $target) {
            symlink($target, $this->appPath.'/public/storage');
            $result = $this->runHelper('ensure');
            $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
            $this->assertSame($this->appPath.'/storage/app/public', readlink($this->appPath.'/public/storage'));
            $this->assertSame('preserve', file_get_contents($this->appPath.'/other/preserve.txt'));
            unlink($this->appPath.'/public/storage');
        }
    }

    public function testRegularDirectoryAndFileAreNeverReplaced(): void
    {
        mkdir($this->appPath.'/public/storage');
        file_put_contents($this->appPath.'/public/storage/preserve.txt', 'directory data');
        $this->assertNotSame(0, $this->runHelper('ensure')->getExitCode());
        $this->assertSame('directory data', file_get_contents($this->appPath.'/public/storage/preserve.txt'));

        unlink($this->appPath.'/public/storage/preserve.txt');
        rmdir($this->appPath.'/public/storage');
        file_put_contents($this->appPath.'/public/storage', 'file data');
        $this->assertNotSame(0, $this->runHelper('ensure')->getExitCode());
        $this->assertSame('file data', file_get_contents($this->appPath.'/public/storage'));
    }

    public function testMissingStorageTargetFailsWithoutCreatingAnEmptyReplacement(): void
    {
        rmdir($this->appPath.'/storage/app/public');
        $this->assertNotSame(0, $this->runHelper('ensure')->getExitCode());
        $this->assertDirectoryDoesNotExist($this->appPath.'/storage/app/public');
        $this->assertFalse(is_link($this->appPath.'/public/storage'));
    }

    public function testVerifyRejectsMissingOrWrongLinkWithoutRepairingIt(): void
    {
        $this->assertNotSame(0, $this->runHelper('verify', 'http://127.0.0.1:1')->getExitCode());
        $this->assertFalse(is_link($this->appPath.'/public/storage'));
        symlink($this->appPath.'/missing', $this->appPath.'/public/storage');
        $this->assertNotSame(0, $this->runHelper('verify', 'http://127.0.0.1:1')->getExitCode());
        $this->assertSame($this->appPath.'/missing', readlink($this->appPath.'/public/storage'));
    }

    public function testHttpProbeVerifiesExactPublicBytesAndCleansUp(): void
    {
        $this->assertSame(0, $this->runHelper('ensure')->getExitCode());
        $url = $this->startServer();
        $result = $this->runHelper('verify', $url);
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertSame([], glob($this->appPath.'/storage/app/public/deploy-storage-probe-*'));
        $this->assertSame([], glob($this->appPath.'/public/.storage-link.*'));
    }

    public function testHttp200FallbackIsRejectedAndProbeIsRemoved(): void
    {
        $this->assertSame(0, $this->runHelper('ensure')->getExitCode());
        $router = $this->appPath.'/fallback.php';
        file_put_contents($router, '<?php echo "unexpected SPA fallback";');
        $url = $this->startServer($router);
        $result = $this->runHelper('verify', $url);
        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('unexpected content', $result->getErrorOutput());
        $this->assertSame([], glob($this->appPath.'/storage/app/public/deploy-storage-probe-*'));
    }

    private function runHelper(string $mode, string $url = ''): Process
    {
        $process = new Process(['bash', dirname(__DIR__, 2).'/scripts/deploy/provision-public-storage.sh', $mode, $this->appPath, $url]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function startServer(?string $router = null): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $command = [PHP_BINARY, '-S', $address, '-t', $this->appPath.'/public'];
        if ($router !== null) {
            $command[] = $router;
        }
        $this->server = new Process($command);
        $this->server->start();
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return 'http://'.$address;
            }
            usleep(20000);
        }
        $this->fail('Public storage HTTP fixture did not start: '.$this->server->getErrorOutput());
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
