<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 12:
 * Uploads are quarantined and a scan is enqueued.
 *
 * Validates: Requirements 14.1, 14.2
 *
 * For ANY valid upload batch — any mix of allowed file types, any per-file
 * size within the configured limit, and any file count >= 1 — the following
 * universal invariants must hold simultaneously:
 *
 *   (a) Every created ShootFile starts in scan_status = 'quarantined'
 *       (Req 14.1 — the file is quarantined on upload).
 *
 *   (b) Exactly one ScanShootFileJob is enqueued per created ShootFile, and
 *       each enqueued job targets a distinct created file id (Req 14.2 — a
 *       scan is enqueued for the file before downstream processing).
 *
 *   (c) No downstream processing job (ProcessImageJob /
 *       UploadShootMediaToDropboxJob) is dispatched directly from the upload
 *       path — downstream work is gated behind a recorded clean verdict.
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the spec's "deterministic generator" strategy
 * already used by the other property tests in this suite (see
 * CubiCasaPerShootIdempotencyPropertyTest, ShootEditingPayloadFilteringPropertyTest,
 * PaymentReminderCadencePropertyTest): a seeded PRNG produces 25 randomized
 * upload batches (random count 1..6, each file a random allowed type at a
 * random size within the limit) plus 5 deterministic edge cases (single image,
 * single non-image, all-images batch, mixed image+video+archive batch, and a
 * max-count single-type batch). The same invariants must hold for every input.
 *
 * Each generated batch is delivered through a data provider so every case runs
 * with a fresh application + database. This isolation matters: the upload path
 * enqueues the scan job via dispatch(...)->afterResponse(), whose terminating
 * callbacks would otherwise accumulate across a single test method and inflate
 * the per-case job counts.
 */
class UploadQuarantineEnqueuedPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 25;

    /** Fixed seed so failures reproduce; bump if a counterexample is fixed. */
    private const SEED = 14_14_12;

    /** Per-file ceiling used for the generated sizes (well under the configured cap). */
    private const MAX_FILE_KB = 1024; // 1 MiB

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin upload-validation config so generated sizes/types are deterministic
        // and independent of any env overrides.
        Config::set('uploads.max_bytes', 1048576 * 1024); // 1 GiB ceiling
        Config::set('uploads.allowed_types', ['jpg', 'jpeg', 'png', 'mp4', 'zip']);
        Config::set('services.dropbox.enabled', false);
        Config::set('services.dropbox.access_token', null);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'upload-quarantine-prop-admin@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Quarantine Property Service',
            'price' => 100,
        ]);
    }

    private function createShoot(): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'upload-quarantine-prop-client-' . uniqid() . '@test.com',
        ]);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'upload-quarantine-prop-photog-' . uniqid() . '@test.com',
        ]);

        return Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $this->service->id,
            'address' => '500 Quarantine Way',
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

    /**
     * Data provider: 25 randomized + 5 deterministic upload batches.
     *
     * Each provided case is a single argument: a list of [type, size]
     * descriptors for the files the case will upload in one request. Types
     * are drawn from the configured allow-list; sizes are random but always
     * within the limit. Must be static (PHPUnit data-provider contract).
     *
     * @return iterable<string, array{0: list<array{type:string,size:int}>}>
     */
    public static function uploadBatchProvider(): iterable
    {
        mt_srand(self::SEED);

        $allowedTypes = ['jpg', 'jpeg', 'png', 'mp4', 'zip'];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $count = mt_rand(1, 6);
            $batch = [];
            for ($j = 0; $j < $count; $j++) {
                $batch[] = [
                    'type' => $allowedTypes[mt_rand(0, count($allowedTypes) - 1)],
                    'size' => mt_rand(16, self::MAX_FILE_KB),
                ];
            }
            yield "random #{$i} ({$count} files)" => [$batch];
        }

        // Deterministic edge cases.
        yield 'edge: single image' => [[['type' => 'jpg', 'size' => 64]]];
        yield 'edge: single non-image (video)' => [[['type' => 'mp4', 'size' => 256]]];
        yield 'edge: all-images batch' => [[
            ['type' => 'jpg', 'size' => 100],
            ['type' => 'jpeg', 'size' => 100],
            ['type' => 'png', 'size' => 100],
        ]];
        yield 'edge: mixed image + video + archive' => [[
            ['type' => 'png', 'size' => 200],
            ['type' => 'mp4', 'size' => 300],
            ['type' => 'zip', 'size' => 150],
        ]];
        yield 'edge: max-count single-type batch' => [[
            ['type' => 'jpg', 'size' => 50],
            ['type' => 'jpg', 'size' => 50],
            ['type' => 'jpg', 'size' => 50],
            ['type' => 'jpg', 'size' => 50],
            ['type' => 'jpg', 'size' => 50],
            ['type' => 'jpg', 'size' => 50],
        ]];
    }

    /**
     * Build a fake UploadedFile of the requested allowed type and size.
     * Image types use ->image() (so they carry valid image dimensions);
     * non-image types use ->create() with an explicit MIME so the mimes
     * allow-list still accepts them.
     */
    private function fakeFile(string $type, int $sizeKb, int $ordinal): UploadedFile
    {
        return match ($type) {
            'jpg', 'jpeg', 'png' => UploadedFile::fake()->image("upload-{$ordinal}.{$type}", 640, 480),
            'mp4' => UploadedFile::fake()->create("upload-{$ordinal}.mp4", $sizeKb, 'video/mp4'),
            'zip' => UploadedFile::fake()->create("upload-{$ordinal}.zip", $sizeKb, 'application/zip'),
            default => UploadedFile::fake()->create("upload-{$ordinal}.{$type}", $sizeKb),
        };
    }

    /**
     * Property 12 — for every generated valid upload batch the three
     * invariants (a)/(b)/(c) hold simultaneously.
     *
     * @param list<array{type:string,size:int}> $batch
     *
     * Validates: Requirements 14.1, 14.2
     */
    #[DataProvider('uploadBatchProvider')]
    public function test_valid_uploads_are_quarantined_and_a_scan_job_is_enqueued_per_file(array $batch): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();

        $files = [];
        foreach ($batch as $ordinal => $descriptor) {
            $files[] = $this->fakeFile($descriptor['type'], $descriptor['size'], $ordinal);
        }
        $expectedCount = count($files);

        $context = sprintf('batch=%s, expectedCount=%d', json_encode($batch), $expectedCount);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'files' => $files,
            'upload_type' => 'raw',
        ]);

        $response->assertOk();

        $createdFiles = ShootFile::where('shoot_id', $shoot->id)->orderBy('id')->get();

        // The upload path must create exactly one row per uploaded file.
        $this->assertCount(
            $expectedCount,
            $createdFiles,
            "[setup] expected one ShootFile row per uploaded file for {$context}"
        );

        // (a) Every created ShootFile starts quarantined (Req 14.1).
        foreach ($createdFiles as $file) {
            $this->assertSame(
                ShootFile::SCAN_STATUS_QUARANTINED,
                $file->scan_status,
                "[a] every uploaded ShootFile must default to scan_status=quarantined for {$context} (file id {$file->id})"
            );
        }

        // (b) Exactly one ScanShootFileJob is enqueued per created file, and
        //     each created file id is targeted by exactly one job (Req 14.2).
        Queue::assertPushed(ScanShootFileJob::class, $expectedCount);

        foreach ($createdFiles->pluck('id')->all() as $fileId) {
            Queue::assertPushed(
                ScanShootFileJob::class,
                fn (ScanShootFileJob $job) => $job->shootFileId === $fileId
            );
        }

        // (c) No downstream processing job is dispatched directly from the
        //     upload path — those are gated behind a clean verdict.
        Queue::assertNotPushed(ProcessImageJob::class);
        Queue::assertNotPushed(UploadShootMediaToDropboxJob::class);
    }
}
