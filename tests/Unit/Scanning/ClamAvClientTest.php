<?php

namespace Tests\Unit\Scanning;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClamAvClientTest extends TestCase
{
    #[Test]
    public function a_large_file_timeout_is_scoped_to_one_scan(): void
    {
        $serverCode = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (! $server) { exit(2); }
        echo stream_socket_get_name($server, false)."\n"; flush();
        for ($i = 0; $i < 2; $i++) {
            $client = stream_socket_accept($server, 10);
            if (! $client) { exit(3); }
            stream_set_timeout($client, 5);
            $command = '';
            while (! str_ends_with($command, "\0")) { $command .= fread($client, 1); }
            while (true) {
                $header = fread($client, 4);
                if (strlen($header) !== 4) { exit(4); }
                $length = unpack('Nsize', $header)['size'];
                if ($length === 0) { break; }
                while ($length > 0) { $part = fread($client, $length); $length -= strlen($part); }
            }
            usleep(2000000);
            @fwrite($client, "stream: OK\0");
            fclose($client);
        }
        fclose($server);
        PHP;
        $process = proc_open([PHP_BINARY, '-r', $serverCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $path = tempnam(sys_get_temp_dir(), 'clam-timeout-');
        file_put_contents($path, 'harmless scan fixture');
        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim(fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^127\\.0\\.0\\.1:[0-9]+$/', $address);
            $client = new ClamAvClient(['host' => '127.0.0.1', 'port' => (int) substr(strrchr($address, ':'), 1), 'connect_timeout' => 1, 'read_timeout' => 1]);
            $this->assertTrue($client->scan($path, 5)->isClean());
            $this->expectException(ClamAvUnavailable::class);
            $client->scan($path);
        } finally {
            unlink($path);
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_terminate($process);
            proc_close($process);
        }
    }

    /**
     * Point the client at a TCP port that is (almost certainly) not listening so
     * connect() fails fast and surfaces as ClamAvUnavailable (Req 15.2).
     */
    private function unreachableClient(): ClamAvClient
    {
        return new ClamAvClient([
            'socket' => null,
            'host' => '127.0.0.1',
            'port' => 1, // reserved/unused; connect refuses
            'connect_timeout' => 1,
            'read_timeout' => 1,
            'chunk_size' => 8192,
        ]);
    }

    #[Test]
    public function scan_throws_clamav_unavailable_when_daemon_cannot_be_reached(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clamav_test_');
        file_put_contents($tmp, 'harmless contents');

        try {
            $this->expectException(ClamAvUnavailable::class);
            $this->unreachableClient()->scan($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function scan_rejects_a_missing_file_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->unreachableClient()->scan('/path/that/does/not/exist/at/all.bin');
    }

    #[Test]
    public function scan_rejects_a_non_path_non_stream_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // @phpstan-ignore-next-line intentionally invalid argument type
        $this->unreachableClient()->scan(12345);
    }

    #[Test]
    public function clean_result_reports_clean_and_no_signature(): void
    {
        $result = ClamAvScanResult::clean();

        $this->assertTrue($result->isClean());
        $this->assertFalse($result->isInfected());
        $this->assertNull($result->signature());
    }

    #[Test]
    public function infected_result_carries_the_signature_name(): void
    {
        $result = ClamAvScanResult::infected('Eicar-Test-Signature');

        $this->assertTrue($result->isInfected());
        $this->assertFalse($result->isClean());
        $this->assertSame('Eicar-Test-Signature', $result->signature());
        $this->assertStringContainsString('FOUND', $result->raw());
    }

    #[Test]
    public function local_fallback_marks_a_large_file_clean_after_an_instream_limit(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clamav_local_clean_');
        file_put_contents($tmp, 'harmless large raw fixture');

        $client = new class extends ClamAvClient {
            public function __construct()
            {
                parent::__construct(['local_max_file_bytes' => 1024]);
            }

            public function local(string $path): ClamAvScanResult
            {
                return $this->scanWithLocalCli($path);
            }

            protected function runLocalScan(array $command): array
            {
                return [0, $command[array_key_last($command)].': OK', ''];
            }
        };

        try {
            $this->assertTrue($client->local($tmp)->isClean());
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function local_fallback_preserves_an_infected_verdict_and_signature(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clamav_local_infected_');
        file_put_contents($tmp, 'fixture');

        $client = new class extends ClamAvClient {
            public function __construct()
            {
                parent::__construct(['local_max_file_bytes' => 1024]);
            }

            public function local(string $path): ClamAvScanResult
            {
                return $this->scanWithLocalCli($path);
            }

            protected function runLocalScan(array $command): array
            {
                return [1, $command[array_key_last($command)].': Eicar-Test-Signature FOUND', ''];
            }
        };

        try {
            $result = $client->local($tmp);
            $this->assertTrue($result->isInfected());
            $this->assertSame('Eicar-Test-Signature', $result->signature());
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function local_fallback_never_assumes_an_oversize_or_scanner_error_is_clean(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clamav_local_error_');
        file_put_contents($tmp, str_repeat('x', 32));

        $oversize = new class extends ClamAvClient {
            public function __construct()
            {
                parent::__construct(['local_max_file_bytes' => 8]);
            }

            public function local(string $path): ClamAvScanResult
            {
                return $this->scanWithLocalCli($path);
            }
        };

        try {
            $this->expectException(ClamAvUnavailable::class);
            $oversize->local($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
