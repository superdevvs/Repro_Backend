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
}
