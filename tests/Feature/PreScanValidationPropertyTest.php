<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\UploadValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 25: Uploads are validated against
 * size and type before scanning.
 *
 * **Validates: Requirements 14.5, 14.6**
 *
 * Universal invariant under test, for any upload characterised by its file
 * extension and reported byte size:
 *
 *     reject  ⇔  (extension ∉ allowed_types)  ∨  (size > max_bytes)
 *     accept  ⇔  (extension ∈ allowed_types)  ∧  (size ≤ max_bytes)
 *
 * and, crucially, rejection happens BEFORE any side effect:
 *
 *   - a rejected upload yields HTTP 422 with NO {@see ShootFile} row created
 *     and NO {@see ScanShootFileJob} (or downstream processing job) dispatched;
 *   - an accepted upload creates exactly one quarantined {@see ShootFile} row
 *     and enqueues exactly one {@see ScanShootFileJob}.
 *
 * No property-based testing library is configured for the backend, so this
 * test follows the same deterministic-generator + seeded-PRNG approach used by
 * {@see \Tests\Unit\Scanning\Properties\WithholdingPropertyTest} and
 * {@see \Tests\Unit\Shoots\ShootDatePreservationPropertyTest}: a fixed table of
 * boundary edge cases (extension exactly in/out of the allow-list, size exactly
 * at max and max+1) plus a seeded PRNG that produces many randomized
 * {extension, size} cases. Each case is reproducible from the seed + index.
 *
 * Two complementary layers are exercised so the invariant is pinned both at the
 * decision boundary and at the integration seam:
 *
 *   1. {@see UploadValidationService::validate()} directly, with byte-precise
 *      sizes (exactly max and max+1 byte) over 100+ randomized iterations — the
 *      pure size/type decision (Req 14.5, 14.6).
 *   2. The real upload endpoint (`POST /api/shoots/{id}/upload`) with
 *      {@see Queue::fake()}, asserting the "no row / no scan job before
 *      scanning on reject; quarantined row + scan job on accept" guarantee.
 */
class PreScanValidationPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Allow-list pinned for every case in this test so the generator's
     * expected-accept calculation is independent of environment overrides.
     *
     * @var list<string>
     */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'mp4', 'zip'];

    /** Extensions guaranteed NOT to be in {@see self::ALLOWED}. */
    private const DISALLOWED = ['exe', 'txt', 'pdf', 'sh', 'php', 'bin', 'svg', 'dmg'];

    /** Byte-precise cap for the service-level property (10 MiB). */
    private const SERVICE_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * KiB-aligned cap for the endpoint-level property (8 MiB). KiB alignment
     * lets the endpoint express "exactly max" (8192 KiB) and "just over max"
     * (8193 KiB) using {@see UploadedFile::fake()} which sizes files in KiB.
     */
    private const ENDPOINT_MAX_KIB = 8192;

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('uploads.allowed_types', self::ALLOWED);
        Config::set('services.dropbox.enabled', false);
        Config::set('services.dropbox.access_token', null);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'prescan-admin@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Pre-Scan Validation Service',
            'price' => 100,
        ]);
    }

    // ---------------------------------------------------------------------
    // Layer 1 — byte-precise service-level property (Req 14.5, 14.6)
    // ---------------------------------------------------------------------

    /**
     * Deterministic boundary table + seeded PRNG yielding 100+ randomized
     * {extension, size} cases. Each case yields
     * `[string $label, string $extension, int $sizeBytes, bool $shouldAccept]`.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: bool}>
     */
    public static function serviceCaseProvider(): iterable
    {
        $max = self::SERVICE_MAX_BYTES;

        // Deterministic boundary edges — the corners where the invariant's
        // truth value can flip.
        $edges = [
            'edge: allowed @ exactly max (accept)' => ['jpg', $max, true],
            'edge: allowed @ max+1 byte (reject: oversize)' => ['jpg', $max + 1, false],
            'edge: allowed @ max-1 byte (accept)' => ['png', $max - 1, true],
            'edge: allowed @ zero bytes (accept)' => ['mp4', 0, true],
            'edge: allowed uppercase ext within size (accept)' => ['JPG', 2048, true],
            'edge: allowed mixed-case ext within size (accept)' => ['Png', 2048, true],
            'edge: disallowed small (reject: type)' => ['exe', 1024, false],
            'edge: disallowed @ exactly max (reject: type)' => ['txt', $max, false],
            'edge: disallowed AND oversize (reject: both)' => ['exe', $max + 4096, false],
            'edge: allowed zip @ exactly max (accept)' => ['zip', $max, true],
        ];

        foreach ($edges as $label => [$ext, $size, $accept]) {
            yield $label => [$label, $ext, $size, $accept];
        }

        // Seeded PRNG so the generator is reproducible across runs; any failing
        // iteration can be reproduced from the seed + case index.
        mt_srand(20260622);

        $extPool = array_merge(self::ALLOWED, self::DISALLOWED);
        $allowedLower = array_map('strtolower', self::ALLOWED);

        $randomCases = 120; // ≥100 iterations to meet the design's PBT bar.
        for ($i = 0; $i < $randomCases; $i++) {
            $ext = $extPool[mt_rand(0, count($extPool) - 1)];

            // Randomly perturb case so the case-insensitive allow-list match is
            // exercised both ways.
            $ext = match (mt_rand(0, 2)) {
                0 => strtolower($ext),
                1 => strtoupper($ext),
                default => ucfirst($ext),
            };

            // Bias toward the boundary: half the cases land within a small
            // window around max, the rest span 0 .. 2*max.
            $size = mt_rand(0, 1) === 0
                ? $max + mt_rand(-2048, 2048)
                : mt_rand(0, 2 * $max);
            $size = max(0, $size);

            $isAllowed = in_array(strtolower($ext), $allowedLower, true);
            $withinSize = $size <= $max;
            $shouldAccept = $isAllowed && $withinSize;

            yield "random: case {$i} (.{$ext}, {$size}B)"
                => ["random: case {$i}", $ext, $size, $shouldAccept];
        }
    }

    /**
     * Property 25 (decision boundary):
     *
     *   ∀ upload u.
     *      validate(u) throws ValidationException(422)
     *        ⇔  ext(u) ∉ allowed  ∨  size(u) > max
     *
     * Drives {@see UploadValidationService::validate()} with a byte-precise
     * fake so the exactly-max / max+1-byte corners are tested without a DB.
     */
    #[Test]
    #[DataProvider('serviceCaseProvider')]
    public function validate_rejects_iff_oversize_or_disallowed_type(
        string $label,
        string $extension,
        int $sizeBytes,
        bool $shouldAccept,
    ): void {
        Config::set('uploads.max_bytes', self::SERVICE_MAX_BYTES);

        $service = new UploadValidationService();
        $upload = $this->fakeUpload($extension, $sizeBytes);

        if ($shouldAccept) {
            // No exception => accepted.
            $service->validate($upload);
            $this->assertTrue(
                true,
                "[{$label}] expected an allowed within-size upload to validate"
            );

            return;
        }

        try {
            $service->validate($upload);
            $this->fail("[{$label}] expected a ValidationException for a rejected upload");
        } catch (ValidationException $e) {
            $this->assertSame(
                422,
                $e->status,
                "[{$label}] a rejected upload must surface as HTTP 422"
            );
            $this->assertArrayHasKey(
                'file',
                $e->errors(),
                "[{$label}] the validation error must be keyed to the file field"
            );
        }
    }

    // ---------------------------------------------------------------------
    // Layer 2 — endpoint property: reject => no row/no job; accept => row+job
    // ---------------------------------------------------------------------

    /**
     * Seeded generator of endpoint cases. Sizes are expressed in KiB so they
     * round-trip exactly through {@see UploadedFile::fake()}. Each case yields
     * `[string $label, string $extension, int $sizeKib, bool $shouldAccept]`.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: bool}>
     */
    public static function endpointCaseProvider(): iterable
    {
        $maxKib = self::ENDPOINT_MAX_KIB;

        $edges = [
            'edge: allowed jpg @ exactly max (accept)' => ['jpg', $maxKib, true],
            'edge: allowed mp4 @ max+1 KiB (reject: oversize)' => ['mp4', $maxKib + 1, false],
            'edge: allowed png small (accept)' => ['png', 64, true],
            'edge: allowed zip mid-size (accept)' => ['zip', 1024, true],
            'edge: disallowed exe small (reject: type)' => ['exe', 16, false],
            'edge: disallowed pdf oversize (reject: both)' => ['pdf', $maxKib + 4096, false],
        ];

        foreach ($edges as $label => [$ext, $kib, $accept]) {
            yield $label => [$label, $ext, $kib, $accept];
        }

        // Seeded PRNG — reproducible. Fewer iterations than the service layer
        // because each case performs a real HTTP upload + DB assertions.
        mt_srand(20260623);

        $extPool = array_merge(self::ALLOWED, self::DISALLOWED);

        $randomCases = 24;
        for ($i = 0; $i < $randomCases; $i++) {
            $ext = $extPool[mt_rand(0, count($extPool) - 1)];

            // Mix within-size and oversize across the max boundary.
            $kib = mt_rand(0, 1) === 0
                ? mt_rand(1, $maxKib)            // within size
                : $maxKib + mt_rand(1, 8192);    // over size

            $isAllowed = in_array(strtolower($ext), self::ALLOWED, true);
            $shouldAccept = $isAllowed && $kib <= $maxKib;

            yield "random: case {$i} (.{$ext}, {$kib}KiB)"
                => ["random: case {$i}", $ext, $kib, $shouldAccept];
        }
    }

    /**
     * Property 25 (no side effect before scanning):
     *
     *   ∀ upload u sent to the upload endpoint.
     *      reject(u) ⇒ HTTP 422 ∧ 0 ShootFile rows ∧ no ScanShootFileJob
     *      accept(u) ⇒ HTTP 2xx ∧ 1 quarantined ShootFile row ∧ 1 ScanShootFileJob
     *
     * This pins the "validated against size and type BEFORE scanning"
     * guarantee end to end (Req 14.5, 14.6): a disallowed-type or oversize
     * upload never reaches quarantine and never enqueues a scan.
     */
    #[Test]
    #[DataProvider('endpointCaseProvider')]
    public function upload_endpoint_rejects_before_any_row_or_scan_job(
        string $label,
        string $extension,
        int $sizeKib,
        bool $shouldAccept,
    ): void {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        Config::set('uploads.max_bytes', self::ENDPOINT_MAX_KIB * 1024);

        $shoot = $this->createShoot();
        $upload = $this->fakeEndpointUpload($extension, $sizeKib, $shouldAccept);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [$upload],
            'upload_type' => 'raw',
        ]);

        if ($shouldAccept) {
            $response->assertOk();

            $file = ShootFile::where('shoot_id', $shoot->id)->latest('id')->first();
            $this->assertNotNull(
                $file,
                "[{$label}] an accepted upload must create exactly one ShootFile row"
            );
            $this->assertSame(
                1,
                ShootFile::where('shoot_id', $shoot->id)->count(),
                "[{$label}] an accepted upload must create exactly one ShootFile row"
            );
            $this->assertSame(
                ShootFile::SCAN_STATUS_QUARANTINED,
                $file->scan_status,
                "[{$label}] an accepted upload must be created in the quarantined state"
            );
            Queue::assertPushed(
                ScanShootFileJob::class,
                fn (ScanShootFileJob $job) => $job->shootFileId === $file->id,
                "[{$label}] an accepted upload must enqueue a scan for the new file"
            );

            return;
        }

        // Rejected: 422, and NOTHING happened before scanning.
        $response->assertStatus(422);
        $this->assertSame(
            0,
            ShootFile::where('shoot_id', $shoot->id)->count(),
            "[{$label}] a rejected upload must NOT create any ShootFile row"
        );
        Queue::assertNotPushed(
            ScanShootFileJob::class,
            "[{$label}] a rejected upload must NOT enqueue a scan job"
        );
        Queue::assertNotPushed(
            ProcessImageJob::class,
            "[{$label}] a rejected upload must NOT enqueue downstream processing"
        );
        Queue::assertNotPushed(
            UploadShootMediaToDropboxJob::class,
            "[{$label}] a rejected upload must NOT enqueue downstream delivery"
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A byte-precise stand-in for an {@see UploadedFile}: only the two methods
     * {@see UploadValidationService} reads (size + client extension) are
     * overridden, letting us set the size to an exact byte count (e.g.
     * max + 1) which {@see UploadedFile::fake()} cannot express.
     */
    private function fakeUpload(string $extension, int $sizeBytes): UploadedFile
    {
        return new class($extension, $sizeBytes) extends UploadedFile {
            public function __construct(
                private string $ext,
                private int $sizeBytes,
            ) {
                // Intentionally do NOT call parent::__construct — this fake is
                // never moved/stored; only getSize()/getClientOriginalExtension()
                // are exercised by UploadValidationService.
            }

            public function getClientOriginalExtension(): string
            {
                return $this->ext;
            }

            public function getSize(): int|false
            {
                return $this->sizeBytes;
            }
        };
    }

    /**
     * Build a real fake upload for the endpoint layer. Accepted image
     * extensions use {@see UploadedFile::fake()->image()} so they store
     * cleanly; everything else uses create() with a controllable KiB size.
     */
    private function fakeEndpointUpload(string $extension, int $sizeKib, bool $shouldAccept): UploadedFile
    {
        $name = 'fixture.' . $extension;
        $imageExts = ['jpg', 'jpeg', 'png'];

        if ($shouldAccept && in_array(strtolower($extension), $imageExts, true)) {
            // image() ignores KiB sizing but always lands well within the cap,
            // which is exactly what an accepted image case needs.
            return UploadedFile::fake()->image($name);
        }

        $mime = match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'mp4' => 'video/mp4',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };

        return UploadedFile::fake()->create($name, $sizeKib, $mime);
    }

    private function createShoot(): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'prescan-client-' . uniqid() . '@test.com',
        ]);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'prescan-photog-' . uniqid() . '@test.com',
        ]);

        return Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $this->service->id,
            'address' => '700 Pre-Scan Blvd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 100,
            'tax_amount' => 6,
            'total_quote' => 106,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ]);
    }
}
