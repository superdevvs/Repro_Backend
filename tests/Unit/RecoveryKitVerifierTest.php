<?php

namespace Tests\Unit;

use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RecoveryKitVerifierTest extends TestCase
{
    private string $directory;

    private string $appKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/repro-kit-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/artifacts', 0700, true);
        $this->appKey = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
        $env = "APP_NAME=Repro\nAPP_KEY={$this->appKey}\nDB_PASSWORD=fixture-only\n";
        file_put_contents($this->directory.'/artifacts/app.env', $env);
        file_put_contents($this->directory.'/artifacts/kit.json', json_encode([
            'schema' => 'repro.recovery-kit.v1',
            'files' => [['name' => 'app.env', 'bytes' => strlen($env), 'sha256' => hash('sha256', $env)]],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (['artifacts/app.env', 'artifacts/kit.json', 'cipher.txt'] as $file) {
            @unlink($this->directory.'/'.$file);
        }
        rmdir($this->directory.'/artifacts');
        rmdir($this->directory);
        parent::tearDown();
    }

    public static function laravelCiphers(): array
    {
        return [
            'string, CBC' => ['AES-256-CBC', false],
            'serialized, CBC' => ['AES-256-CBC', true],
            'string, GCM' => ['AES-256-GCM', false],
        ];
    }

    #[DataProvider('laravelCiphers')]
    public function test_verifier_decrypts_what_the_apps_encrypter_wrote(string $cipher, bool $serialize): void
    {
        $encrypter = new Encrypter(base64_decode(substr($this->appKey, 7)), $cipher);
        file_put_contents($this->directory.'/cipher.txt', $encrypter->encrypt('fixture stored secret', $serialize));
        $plain = $serialize ? serialize('fixture stored secret') : 'fixture stored secret';

        $process = $this->verify(hash('sha256', $plain));

        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertSame(['result' => 'stored-value-decrypted', 'plaintext_sha256' => hash('sha256', $plain),
            'kit_files_verified' => 1], json_decode($process->getOutput(), true));
        $this->assertStringNotContainsString(substr($this->appKey, 7), $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('fixture stored secret', $process->getOutput().$process->getErrorOutput());
    }

    public function test_value_from_another_key_is_refused(): void
    {
        $other = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
        file_put_contents($this->directory.'/cipher.txt', $other->encryptString('fixture stored secret'));

        $process = $this->verify(hash('sha256', 'fixture stored secret'));

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('stored value did not decrypt with the recovered key',
            json_decode($process->getOutput(), true)['reason']);
    }

    private function verify(string $expected): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/mysql/offsite/verify_recovery_kit.php',
            $this->directory, $this->directory.'/cipher.txt', $expected]);
        $process->run();

        return $process;
    }
}
