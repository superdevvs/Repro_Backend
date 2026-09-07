<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\Actions\ChangeServiceBracketModeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * One shoot, two photographers, two bracket sizes, uploaded in one action.
 *
 * This is the shape the whole per-service bracket model exists for:
 *
 *     Exterior HDR · Photographer A · 5x · 7 frames
 *     Interior HDR · Photographer B · 3x · 6 frames
 *     Aerial Drone · Photographer A · no brackets · 3 frames
 *
 * Every capture timeline below deliberately places the next service's first frame
 * within the clustering gap of the previous service's trailing partial stack, which
 * is precisely where an unscoped stacker joins two services into one stack.
 */
class ShootMultiPhotographerBracketUploadTest extends TestCase
{
    use RefreshDatabase;

    /** Comfortably larger than AutoStackRawFilesAction::INTRA_BRACKET_GAP_SECONDS. */
    private const NEW_STACK_GAP = 40;

    private User $admin;

    private Shoot $shoot;

    private Carbon $captureBase;

    /** @var array<string, int> filename => seconds after the capture base */
    private array $captureOffsets = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();

        $this->captureBase = Carbon::parse('2026-08-22 09:00:00');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            // No legacy shoot-wide size: every size below comes from a service item.
            'bracket_mode' => null,
        ]);
        Sanctum::actingAs($this->admin);

        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('uploadToTodo')->andReturnUsing(
            function (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null) {
                $name = $file->getClientOriginalName();
                $metadata = [];
                if (array_key_exists($name, $this->captureOffsets)) {
                    $metadata['captured_at'] = $this->captureBase
                        ->copy()
                        ->addSeconds($this->captureOffsets[$name])
                        ->format('Y-m-d H:i:s');
                }

                return ShootFile::create([
                    'shoot_id' => $target->id,
                    'filename' => $name,
                    'stored_filename' => $name,
                    'path' => 'shoots/'.$target->id.'/todo/'.$name,
                    'file_type' => 'image/jpeg',
                    'file_size' => $file->getSize(),
                    'media_type' => $mediaType ?: 'raw',
                    'uploaded_by' => $actorId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                    'metadata' => $metadata,
                ]);
            }
        );
        app()->instance(ShootMediaStorageService::class, $dropbox);
    }

    private function photographer(string $name, ?int $preference): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'photographer',
            'default_bracket_mode' => $preference,
        ]);
    }

    private function serviceItem(
        string $name,
        int $photoCount,
        bool $usesHdrBrackets,
        ?User $photographer,
    ): ShootService {
        $category = Category::query()->firstOrCreate(['name' => 'Photography']);
        $service = Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $category->id,
            'photo_count' => $photoCount,
            'uses_hdr_brackets' => $usesHdrBrackets,
            // These fixtures all model photo capture; capability is declared rather
            // than inferred, and the column default is deliberately "not selectable".
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'pricing_type' => 'fixed',
        ]);

        $item = ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
        ]);

        if ($photographer) {
            // Goes through the real assignment path, so the bracket size is
            // snapshotted from the photographer's preference exactly as it is in
            // production rather than being written directly by the test.
            $this->shoot->assignPhotographerToService($service->id, $photographer->id);
        }

        return $item->refresh();
    }

    /**
     * @param  array<string, int>  $filesWithCaptureOffsets  filename => seconds after base
     */
    private function uploadGroup(
        array $filesWithCaptureOffsets,
        int $shootServiceId,
        ?int $bracketMode,
        string $batchId,
        ?string $mediaType = null,
        ?int $failOnIndex = null,
    ): void {
        $this->captureOffsets = array_merge($this->captureOffsets, $filesWithCaptureOffsets);
        $filenames = array_keys($filesWithCaptureOffsets);

        foreach (array_values($filenames) as $index => $filename) {
            if ($failOnIndex !== null && $index === $failOnIndex) {
                continue;
            }

            $payload = [
                'files' => [UploadedFile::fake()->create($filename, 10, 'image/jpeg')],
                'upload_type' => 'raw',
                'idempotency_key' => $batchId.'-'.$index,
                'upload_batch_id' => $batchId,
                'upload_batch_total' => count($filenames),
                'upload_batch_index' => $index,
                'shoot_service_id' => $shootServiceId,
            ];
            if ($bracketMode !== null) {
                $payload['bracket_mode'] = $bracketMode;
            }
            if ($mediaType !== null) {
                $payload['media_type'] = $mediaType;
            }

            $this->post('/api/shoots/'.$this->shoot->id.'/upload', $payload, ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('success_count', 1);
        }
    }

    /**
     * @return array<string, string> filename => "stack/frame"
     *
     * Ordered by stack then frame rather than by row id, so a file that was
     * inserted later by a retry still reads in its stack position.
     */
    private function stacksFor(int $shootServiceId): array
    {
        return ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->where('shoot_service_id', $shootServiceId)
            ->orderBy('bracket_group')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ShootFile $file) => [
                $file->filename => $file->bracket_group.'/'.$file->sequence,
            ])
            ->all();
    }

    /**
     * The shoot's raw expectation.
     *
     * Read through the resolver, not the `shoots.expected_raw_count` column: that
     * column is legacy and deliberately no longer written, because one shoot-wide
     * multiplication cannot express services shot at different sizes.
     */
    private function expectedRawForShoot(): int
    {
        return app(\App\Services\Shoots\BracketModeResolver::class)
            ->expectedRawForShoot($this->shoot->fresh());
    }

    public function test_two_photographers_at_five_and_three_keep_their_own_stacks(): void
    {
        $photographerA = $this->photographer('Photographer A', 5);
        $photographerB = $this->photographer('Photographer B', 3);

        $exterior = $this->serviceItem('Exterior HDR', 30, true, $photographerA);
        $interior = $this->serviceItem('Interior HDR', 12, true, $photographerB);

        // The assignment recorded each photographer's own way of working.
        $this->assertSame(5, $exterior->bracket_mode);
        $this->assertSame(3, $interior->bracket_mode);

        // A: five frames, a pause, then two more, leaving stack 2 partial.
        $this->uploadGroup([
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
            'ext-3.jpg' => 2,
            'ext-4.jpg' => 3,
            'ext-5.jpg' => 4,
            'ext-6.jpg' => self::NEW_STACK_GAP,
            'ext-7.jpg' => self::NEW_STACK_GAP + 1,
        ], $exterior->id, 5, 'batch-exterior');

        // B starts two seconds after A's last frame — inside the clustering gap —
        // and shoots three, pauses, then three more.
        $this->uploadGroup([
            'int-1.jpg' => self::NEW_STACK_GAP + 3,
            'int-2.jpg' => self::NEW_STACK_GAP + 4,
            'int-3.jpg' => self::NEW_STACK_GAP + 5,
            'int-4.jpg' => self::NEW_STACK_GAP * 2,
            'int-5.jpg' => self::NEW_STACK_GAP * 2 + 1,
            'int-6.jpg' => self::NEW_STACK_GAP * 2 + 2,
        ], $interior->id, 3, 'batch-interior');

        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
            'ext-4.jpg' => '1/4',
            'ext-5.jpg' => '1/5',
            'ext-6.jpg' => '2/1',
            'ext-7.jpg' => '2/2',
        ], $this->stacksFor($exterior->id));

        // The regression this guards: int-1 opens Interior's own stack 1 rather
        // than continuing Exterior's stack 2 as frame 3.
        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
            'int-3.jpg' => '1/3',
            'int-4.jpg' => '2/1',
            'int-5.jpg' => '2/2',
            'int-6.jpg' => '2/3',
        ], $this->stacksFor($interior->id));

        // Nothing wrote the legacy shoot-wide value.
        $this->assertNull($this->shoot->fresh()->bracket_mode);
    }

    public function test_five_three_and_drone_upload_together_and_drone_takes_no_stack(): void
    {
        $photographerA = $this->photographer('Photographer A', 5);
        $photographerB = $this->photographer('Photographer B', 3);

        $exterior = $this->serviceItem('Exterior HDR', 30, true, $photographerA);
        $interior = $this->serviceItem('Interior HDR', 12, true, $photographerB);
        // Drone sits in the Photography category with a real photo count and does
        // not bracket. Under the old name/count heuristic it was treated as 5x.
        $drone = $this->serviceItem('Aerial Drone Photos', 10, false, $photographerA);

        $this->assertNull($drone->bracket_mode, 'a non-bracket service must not be stamped with a size');

        $this->uploadGroup([
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
            'ext-3.jpg' => 2,
        ], $exterior->id, 5, 'batch-exterior');

        $this->uploadGroup([
            'int-1.jpg' => 3,
            'int-2.jpg' => 4,
            'int-3.jpg' => 5,
        ], $interior->id, 3, 'batch-interior');

        // The frontend omits bracket_mode entirely for a group that does not bracket.
        $this->uploadGroup([
            'drone-1.jpg' => 6,
            'drone-2.jpg' => 7,
            'drone-3.jpg' => 8,
        ], $drone->id, null, 'batch-drone', 'drone');

        // Every frame here was captured inside one clustering window, so only the
        // service partitions keep these apart.
        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
        ], $this->stacksFor($exterior->id));

        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
            'int-3.jpg' => '1/3',
        ], $this->stacksFor($interior->id));

        // Drone files are not raw stacking material at all: no group, no sequence.
        $droneFiles = ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->where('shoot_service_id', $drone->id)
            ->get();
        $this->assertCount(3, $droneFiles);
        foreach ($droneFiles as $file) {
            $this->assertSame('drone', $file->media_type);
            $this->assertNull($file->bracket_group, $file->filename.' must not take a stack number');
            $this->assertNull($file->sequence);
        }

        // Drone still contributes to the raw expectation, just unmultiplied:
        // 30x5 + 12x3 + 10 = 196.
        $this->assertSame(196, $this->expectedRawForShoot());
    }

    public function test_a_retry_returns_each_file_to_its_own_service_and_size(): void
    {
        $photographerA = $this->photographer('Photographer A', 5);
        $photographerB = $this->photographer('Photographer B', 3);
        $exterior = $this->serviceItem('Exterior HDR', 30, true, $photographerA);
        $interior = $this->serviceItem('Interior HDR', 12, true, $photographerB);

        // Both groups lose their middle file.
        $this->uploadGroup([
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
            'ext-3.jpg' => 2,
        ], $exterior->id, 5, 'batch-exterior', null, 1);

        $this->uploadGroup([
            'int-1.jpg' => 3,
            'int-2.jpg' => 4,
            'int-3.jpg' => 5,
        ], $interior->id, 3, 'batch-interior', null, 1);

        $this->assertSame(['ext-1.jpg' => '1/1', 'ext-3.jpg' => '1/2'], $this->stacksFor($exterior->id));
        $this->assertSame(['int-1.jpg' => '1/1', 'int-3.jpg' => '1/2'], $this->stacksFor($interior->id));

        // Retry each failed file against its ORIGINAL service, as the frontend does
        // by reading the file's own group rather than the current picker.
        $this->uploadGroup(['ext-2.jpg' => 1], $exterior->id, 5, 'batch-exterior-retry');
        $this->uploadGroup(['int-2.jpg' => 4], $interior->id, 3, 'batch-interior-retry');

        $exteriorFiles = ShootFile::query()
            ->where('shoot_service_id', $exterior->id)->pluck('filename')->sort()->values()->all();
        $interiorFiles = ShootFile::query()
            ->where('shoot_service_id', $interior->id)->pluck('filename')->sort()->values()->all();

        $this->assertSame(['ext-1.jpg', 'ext-2.jpg', 'ext-3.jpg'], $exteriorFiles);
        $this->assertSame(['int-1.jpg', 'int-2.jpg', 'int-3.jpg'], $interiorFiles);

        // Each retried file rejoined its own service's stack in capture order, and
        // neither crossed into the other service.
        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
        ], $this->stacksFor($exterior->id));
        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
            'int-3.jpg' => '1/3',
        ], $this->stacksFor($interior->id));

        $this->assertSame(5, $exterior->refresh()->bracket_mode);
        $this->assertSame(3, $interior->refresh()->bracket_mode);
    }

    public function test_change_and_restack_recuts_one_service_and_leaves_the_other_untouched(): void
    {
        $photographerA = $this->photographer('Photographer A', 5);
        $photographerB = $this->photographer('Photographer B', 3);
        $exterior = $this->serviceItem('Exterior HDR', 30, true, $photographerA);
        $interior = $this->serviceItem('Interior HDR', 12, true, $photographerB);

        $this->uploadGroup([
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
            'ext-3.jpg' => 2,
        ], $exterior->id, 5, 'batch-exterior');

        $this->uploadGroup([
            'int-1.jpg' => self::NEW_STACK_GAP,
            'int-2.jpg' => self::NEW_STACK_GAP + 1,
            'int-3.jpg' => self::NEW_STACK_GAP + 2,
        ], $interior->id, 3, 'batch-interior');

        $interiorBefore = $this->stacksFor($interior->id);

        $result = app(ChangeServiceBracketModeAction::class)->execute($exterior->refresh(), 3);

        $this->assertTrue($result['had_raw_files'], 'the change happened with frames already on the service');
        $this->assertSame(5, $result['previous_bracket_mode']);
        $this->assertSame(3, $result['bracket_mode']);
        $this->assertTrue($result['restacked']);

        // Only Exterior moved; Interior's stacks are byte-for-byte what they were.
        $this->assertSame(3, $exterior->refresh()->bracket_mode);
        $this->assertSame($interiorBefore, $this->stacksFor($interior->id));
        $this->assertSame(3, $interior->refresh()->bracket_mode);
    }

    public function test_an_unassigned_service_still_resolves_a_size_from_the_default(): void
    {
        // No photographer, no recorded size, no legacy shoot value.
        $orphan = $this->serviceItem('Exterior HDR', 30, true, null);
        $this->assertNull($orphan->bracket_mode);

        $this->uploadGroup([
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
        ], $orphan->id, null, 'batch-orphan');

        // It stacks rather than being skipped, using the product default of 5.
        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
        ], $this->stacksFor($orphan->id));

        // 30 finals at the default 5x.
        $this->assertSame(150, $this->expectedRawForShoot());
    }
}
