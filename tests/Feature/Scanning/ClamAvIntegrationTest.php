<?php

namespace Tests\Feature\Scanning;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration test that exercises the REAL {@see ClamAvClient} against a LIVE
 * clamd daemon configured via config/clamav.php (Req 14.2, 14.4, 15.1).
 *
 * Unlike {@see \Tests\Unit\Scanning\ScanShootFileJobTest} (which mocks the
 * client), this test submits actual bytes over the INSTREAM protocol and
 * asserts the engine's real verdict:
 *
 *   - a clean fixture  -> {@see ClamAvScanResult::isClean()} and is released;
 *   - the EICAR standard anti-virus test string -> infected, with a signature,
 *     and is flagged + withheld from downstream processing.
 *
 * ClamAV is NOT mocked here on purpose — the whole point is to validate the
 * real scan path. Because a live clamd is frequently absent (e.g. CI without
 * the daemon, or this sandbox), every test first probes reachability and calls
 * {@see markTestSkipped()} when clamd cannot be reached, keeping CI green while
 * a real environment runs the assertions for real.
 *
 * The EICAR test string is assembled from fragments at runtime so that this
 * source file is never itself quarantined by an on-access scanner.
 */
#[Group('clamav-integration')]
class ClamAvIntegrationTest extends TestCase
{
    /** @var array<int, string> Temp fixture paths to clean up after each test. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * The canonical EICAR anti-virus test string, built from concatenated
     * fragments so the literal infected payload never appears as a single
     * contiguous token in this repository (which would risk the file itself
     * being flagged/quarantined).
     */
    private function eicarString(): string
    {
        $parts = [
            'X5O!P%@AP[4\\PZX54(P^)7CC)7}',
            '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!',
            '$H+H*',
        ];

        return implode('', $parts);
    }

    /** Write a fixture to a temp file scheduled for cleanup and return its path. */
    private function writeFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clamav-it-');
        if ($path === false) {
            $this->fail('Unable to allocate a temp fixture file.');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Build the real client and confirm a live clamd is reachable by scanning a
     * tiny clean probe. When clamd is unavailable the test is skipped (not
     * failed) so CI without the daemon stays green.
     */
    private function liveClientOrSkip(): ClamAvClient
    {
        $client = new ClamAvClient();

        try {
            $client->scan($this->writeFixture('clamav-reachability-probe'));
        } catch (ClamAvUnavailable $e) {
            $this->markTestSkipped(
                'Live clamd is not reachable via config/clamav.php; skipping ClamAV integration test. ('
                . $e->getMessage() . ')'
            );
        }

        return $client;
    }

    #[Test]
    public function clean_fixture_scans_clean_against_live_clamd(): void
    {
        $client = $this->liveClientOrSkip();

        // A few harmless bytes — nothing that resembles a known signature.
        $cleanPath = $this->writeFixture("This is a perfectly clean test fixture.\n\x00\x01\x02harmless");

        $result = $client->scan($cleanPath);

        $this->assertInstanceOf(ClamAvScanResult::class, $result);
        $this->assertTrue(
            $result->isClean(),
            'A harmless fixture must scan clean. Raw clamd response: ' . $result->raw()
        );
        $this->assertNull($result->signature());
    }

    #[Test]
    public function eicar_fixture_scans_infected_with_a_signature_against_live_clamd(): void
    {
        $client = $this->liveClientOrSkip();

        $eicarPath = $this->writeFixture($this->eicarString());

        $result = $client->scan($eicarPath);

        $this->assertInstanceOf(ClamAvScanResult::class, $result);
        $this->assertTrue(
            $result->isInfected(),
            'The EICAR test string must be detected as infected. Raw clamd response: ' . $result->raw()
        );
        $this->assertNotNull($result->signature(), 'An infected verdict must carry a signature name.');
        $this->assertNotSame('', (string) $result->signature());
        // clamd reports EICAR using a signature whose name contains "Eicar".
        $this->assertStringContainsStringIgnoringCase(
            'eicar',
            (string) $result->signature(),
            'Expected an EICAR signature; got: ' . $result->signature()
        );
    }

    #[Test]
    public function clean_verdict_releases_and_infected_verdict_is_withheld_end_to_end(): void
    {
        $client = $this->liveClientOrSkip();
        Queue::fake();

        $service = app(FileScanService::class);

        // Clean path: a clean verdict is recorded and the file is released for
        // downstream processing (Req 14.4).
        $cleanResult = $client->scan($this->writeFixture('end-to-end clean fixture bytes'));
        $this->assertTrue($cleanResult->isClean(), 'Expected a clean verdict for the clean fixture.');

        $cleanFile = $this->inMemoryQuarantinedFile(1001);
        $service->recordResult($cleanFile, $cleanResult);
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $cleanFile->scan_status);
        $this->assertTrue($service->release($cleanFile), 'A clean file must be released.');
        Queue::assertPushed(ProcessImageJob::class);

        // Infected path: an EICAR verdict is flagged and never released (Req 15.1).
        $infectedResult = $client->scan($this->writeFixture($this->eicarString()));
        $this->assertTrue($infectedResult->isInfected(), 'Expected an infected verdict for EICAR.');

        $infectedFile = $this->inMemoryQuarantinedFile(1002);
        $service->flagInfected($infectedFile, $infectedResult);
        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $infectedFile->scan_status);
        $this->assertNotSame('', (string) $infectedFile->scan_result);
        $this->assertFalse(
            $service->release($infectedFile),
            'An infected file must be withheld and never released.'
        );
    }

    /**
     * An in-memory {@see ShootFile} whose save() is a no-op so the end-to-end
     * verdict transitions can be exercised without a database round trip,
     * mirroring the pattern used by the scanning unit/property tests.
     */
    private function inMemoryQuarantinedFile(int $id): ShootFile
    {
        $file = new class extends ShootFile {
            public function save(array $options = []): bool
            {
                return true;
            }
        };

        $file->id = $id;
        $file->shoot_id = 7;
        $file->filename = 'integration.jpg';
        $file->scan_status = ShootFile::SCAN_STATUS_QUARANTINED;

        return $file;
    }
}
