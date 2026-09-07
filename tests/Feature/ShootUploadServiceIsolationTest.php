<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Upload identity has to be execution-specific, not shoot-wide.
 *
 * Raw ownership is now the shoot_service row, so two uploads that differ only by which
 * execution row they target are two different uploads. Byte-identical files are the
 * realistic case, not a contrived one: two photographers working one shoot both hand in
 * DSC_0001.jpg, and a fake/duplicated frame hashes the same as its twin.
 *
 * Deliberately runs the real ShootMediaStorageService rather than a mock. The mock used by
 * the other upload suites bypasses storage entirely, which is exactly where per-shoot
 * (rather than per-service) de-duplication lives.
 */
class ShootUploadServiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shoot $shoot;

    private ShootService $exterior;

    private ShootService $twilight;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        $this->shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 5,
        ]);

        $this->exterior = $this->item($this->bracketedService('Exterior HDR', 10), 5);
        $this->twilight = $this->item($this->bracketedService('Twilight HDR', 10), 3);
    }

    private function bracketedService(string $name, int $photoCount): Service
    {
        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Photos'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => $photoCount,
            'uses_hdr_brackets' => true,
            'upload_intake_type' => Service::INTAKE_PHOTO,
        ]);
    }

    private function item(Service $service, ?int $bracketMode = null): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
        ]);
    }

    /**
     * A file whose bytes are fully determined by its declared size, so two calls with
     * the same arguments produce byte-identical content and therefore an identical
     * content hash.
     */
    private function frame(string $filename, int $kilobytes = 10, string $mime = 'image/jpeg'): UploadedFile
    {
        return UploadedFile::fake()->create($filename, $kilobytes, $mime);
    }

    /** @param array<string, mixed> $extra */
    private function upload(
        ?int $shootServiceId,
        UploadedFile $file,
        string $idempotencyKey,
        array $extra = [],
    ) {
        $payload = array_merge([
            'files' => [$file],
            'upload_type' => 'raw',
            'idempotency_key' => $idempotencyKey,
        ], $extra);

        if ($shootServiceId !== null) {
            $payload['shoot_service_id'] = $shootServiceId;
        }

        return $this->post(
            '/api/shoots/'.$this->shoot->id.'/upload',
            $payload,
            ['Accept' => 'application/json']
        );
    }

    /** @return array<int, array{id:int, filename:string, pivot:?int}> */
    private function storedFiles(): array
    {
        return ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ShootFile $file) => [
                'id' => (int) $file->id,
                'filename' => (string) $file->filename,
                'pivot' => $file->shoot_service_id !== null ? (int) $file->shoot_service_id : null,
            ])
            ->all();
    }

    private function fileCountFor(ShootService $item): int
    {
        return ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->where('shoot_service_id', $item->id)
            ->count();
    }

    /**
     * 1. Same bytes, same execution row, same key: a genuine duplicate submission.
     */
    public function test_same_bytes_same_service_and_same_key_replays_without_duplicating(): void
    {
        $key = 'batch-exterior-0';

        $first = $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg'), $key);
        $first->assertOk()->assertJsonPath('success_count', 1);

        $second = $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg'), $key);
        $second->assertOk();

        // The replay hands back the stored result rather than doing the work twice.
        $this->assertSame(1, $this->fileCountFor($this->exterior));
        $this->assertSame(
            1,
            ShootUploadAttempt::query()->where('idempotency_key', $key)->count(),
            'one logical attempt, not two'
        );
    }

    /**
     * 2. The invariant this whole change exists for: byte-identical frames handed in for
     *    two different execution rows are two different uploads.
     */
    public function test_identical_bytes_for_different_services_never_replay_each_other(): void
    {
        // Same filename and same bytes: two photographers, two cameras, one shoot.
        $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg'), 'exterior-key-0')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $this->upload($this->twilight->id, $this->frame('DSC_0001.jpg'), 'twilight-key-0')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $files = $this->storedFiles();

        $this->assertCount(
            2,
            $files,
            'each execution row owes its own frame; one must not absorb the other: '.json_encode($files)
        );
        $this->assertSame(1, $this->fileCountFor($this->exterior), 'exterior kept its frame');
        $this->assertSame(1, $this->fileCountFor($this->twilight), 'twilight got its own frame');
    }

    /**
     * The same collision expressed through the fingerprint directly, so a regression is
     * caught even if the storage layer changes shape.
     */
    public function test_the_request_fingerprint_distinguishes_the_execution_row(): void
    {
        $service = app(\App\Services\Shoots\ShootUploadIdempotencyService::class);

        $makeRequest = function (?int $pivot, string $lane = 'photo') {
            return \Illuminate\Http\Request::create('/upload', 'POST', array_filter([
                'upload_type' => 'raw',
                'shoot_service_id' => $pivot,
                'upload_lane' => $lane,
            ], fn ($value) => $value !== null));
        };

        $file = $this->frame('DSC_0001.jpg');

        $forExterior = $service->fingerprint($makeRequest($this->exterior->id), [$file]);
        $forTwilight = $service->fingerprint($makeRequest($this->twilight->id), [$file]);
        $forNoService = $service->fingerprint($makeRequest(null), [$file]);

        $this->assertNotSame($forExterior, $forTwilight, 'the execution row must change the fingerprint');
        $this->assertNotSame($forExterior, $forNoService, 'an unassigned upload is not the same upload');

        // And the lane participates, because it now decides whether stacking applies.
        $this->assertNotSame(
            $service->fingerprint($makeRequest($this->exterior->id, 'photo'), [$file]),
            $service->fingerprint($makeRequest($this->exterior->id, 'video'), [$file]),
            'the upload lane must change the fingerprint'
        );
    }

    /**
     * Reusing one key for a different execution row is a client mistake, and it must be
     * reported rather than answered with the other row's result.
     */
    public function test_reusing_one_key_across_services_is_a_conflict_not_a_silent_replay(): void
    {
        $key = 'shared-key';

        $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg'), $key)
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $conflict = $this->upload($this->twilight->id, $this->frame('DSC_0001.jpg'), $key);

        $conflict->assertStatus(409)->assertJsonPath('error_type', 'idempotency_conflict');
        $this->assertSame(0, $this->fileCountFor($this->twilight), 'no file was attributed to the wrong row');
    }

    /**
     * 3. Same name, different contents, same service: a corrected frame, not a replay.
     */
    public function test_same_filename_with_different_contents_is_not_falsely_replayed(): void
    {
        $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg', 10), 'exterior-key-0')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $original = ShootFile::query()->where('shoot_id', $this->shoot->id)->sole();
        $originalSize = (int) $original->file_size;

        // Different bytes under the same name, submitted under its own key.
        $this->upload($this->exterior->id, $this->frame('DSC_0001.jpg', 40), 'exterior-key-1')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $refreshed = ShootFile::query()->where('shoot_id', $this->shoot->id)
            ->where('filename', 'DSC_0001.jpg')
            ->orderByDesc('id')
            ->first();

        $this->assertNotSame(
            $originalSize,
            (int) $refreshed->file_size,
            'the second submission must actually be processed, not answered from the first result'
        );
        $this->assertSame(2, ShootUploadAttempt::query()->count(), 'two distinct logical attempts');
    }

    /**
     * 4. A retry after failure keeps working on the same execution row.
     */
    public function test_a_retry_preserves_the_original_execution_row(): void
    {
        // The client rotates its key on a confirmed retry, so this is a new attempt for
        // the same service group rather than a replay.
        $this->upload($this->exterior->id, $this->frame('DSC_0007.jpg'), 'exterior-attempt-1')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $this->upload($this->exterior->id, $this->frame('DSC_0008.jpg'), 'exterior-attempt-1-retry')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $attempts = ShootUploadAttempt::query()->orderBy('id')->get();
        $this->assertCount(2, $attempts);

        foreach ($attempts as $attempt) {
            $this->assertSame(
                (int) $this->exterior->id,
                (int) $attempt->shoot_service_id,
                'every attempt in this group stays attributed to the same execution row'
            );
        }

        $this->assertSame(2, $this->fileCountFor($this->exterior));
        $this->assertSame(0, $this->fileCountFor($this->twilight));
    }

    /**
     * 5. One "Upload All" carrying two groups whose frames happen to be byte-identical.
     */
    public function test_two_groups_in_one_upload_all_each_land_on_their_own_service(): void
    {
        $groups = [
            ['batch' => 'group-exterior', 'pivot' => $this->exterior->id],
            ['batch' => 'group-twilight', 'pivot' => $this->twilight->id],
        ];

        foreach ($groups as $group) {
            foreach ([0, 1] as $index) {
                $this->upload(
                    $group['pivot'],
                    // Identical bytes and identical names across both groups.
                    $this->frame('IMG_000'.$index.'.jpg'),
                    $group['batch'].'-'.$index,
                    [
                        'upload_batch_id' => $group['batch'],
                        'upload_batch_index' => $index,
                        'upload_batch_total' => 2,
                    ]
                )->assertOk()->assertJsonPath('success_count', 1);
            }
        }

        $this->assertSame(2, $this->fileCountFor($this->exterior), 'exterior group: '.json_encode($this->storedFiles()));
        $this->assertSame(2, $this->fileCountFor($this->twilight), 'twilight group: '.json_encode($this->storedFiles()));
        $this->assertCount(4, $this->storedFiles());
    }

    /**
     * 6. A bundled service serves both lanes from one execution row, and a still must
     *    never be answered with a clip's result or the other way round.
     */
    public function test_a_photo_video_service_keeps_its_two_lanes_distinct(): void
    {
        $bundle = Service::query()->create([
            'name' => 'HDR Photos, Video & Premium iGuide',
            'description' => 'Bundle',
            'price' => 410,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Packages'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => 30,
            'uses_hdr_brackets' => true,
            'upload_intake_type' => Service::INTAKE_PHOTO_VIDEO,
        ]);
        $item = $this->item($bundle, 5);

        $this->upload($item->id, $this->frame('MEDIA_0001.jpg', 10, 'image/jpeg'), 'bundle-photo-0', [
            'upload_lane' => 'photo',
        ])->assertOk()->assertJsonPath('success_count', 1);

        $this->upload($item->id, $this->frame('MEDIA_0001.mp4', 10, 'video/mp4'), 'bundle-video-0', [
            'upload_lane' => 'video',
        ])->assertOk()->assertJsonPath('success_count', 1);

        $this->assertSame(2, $this->fileCountFor($item), 'both lanes landed: '.json_encode($this->storedFiles()));

        // Reusing the still's key for the clip is a conflict, never a replay.
        $this->upload($item->id, $this->frame('MEDIA_0002.mp4', 10, 'video/mp4'), 'bundle-photo-0', [
            'upload_lane' => 'video',
        ])->assertStatus(409)->assertJsonPath('error_type', 'idempotency_conflict');
    }
}
