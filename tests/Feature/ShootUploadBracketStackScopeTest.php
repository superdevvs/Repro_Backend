<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Bracket stacks must never straddle two service items.
 *
 * A shoot can book several photo services to the same photographer, and stacks are
 * derived two ways: an upload-time (batch_offset + batch_index) calculation, and the
 * EXIF captured_at clustering in AutoStackRawFilesAction that runs after every raw
 * upload and has the final say. Both used to look at every raw file on the shoot.
 *
 * The failure that produced: a photographer finishing Exterior with a partial stack and
 * starting Interior moments later. Interior's first frame landed in Exterior's stack 2
 * as frame 3 — one stack, two services. Time proximity across a service boundary is not
 * evidence of a shared bracket.
 *
 * Isolation must not break legitimate continuation: a later upload to the SAME service
 * still has to continue its own trailing partial stack. Every capture timeline below is
 * deliberately built so the frames sit within the clustering gap ACROSS the service
 * boundary, which is exactly where the unscoped version cross-wires.
 */
class ShootUploadBracketStackScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Bigger than AutoStackRawFilesAction::INTRA_BRACKET_GAP_SECONDS. */
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
            'bracket_mode' => 5,
        ]);
        Sanctum::actingAs($this->admin);

        // The real EXIF read lives in ShootMediaStorageService, so mocking it means the
        // test owns captured_at outright.
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

    /**
     * Book a service onto the shoot.
     *
     * `$usesHdrBrackets` is the catalogue flag and `$bracketMode` the execution
     * value recorded on the assignment. Both are explicit: whether a deliverable
     * stacks exposures is catalogue data, and how many exposures is per assignment,
     * so a test that wants bracketed behaviour has to say so.
     */
    private function serviceItem(
        string $name,
        int $photoCount = 10,
        bool $usesHdrBrackets = true,
        ?int $bracketMode = 5,
        ?int $photographerId = null,
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

        return ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'photographer_id' => $photographerId,
            'bracket_mode' => $usesHdrBrackets ? $bracketMode : null,
        ]);
    }

    /**
     * Upload a group one request at a time, exactly as the frontend does: one batch id
     * per group, one index per file inside it.
     *
     * @param  array<string, int>  $filesWithCaptureOffsets  filename => seconds after base
     */
    private function uploadGroup(
        array $filesWithCaptureOffsets,
        ?int $shootServiceId,
        ?int $bracketMode,
        string $batchId,
        ?string $mediaType = null,
    ): void {
        $this->captureOffsets = array_merge($this->captureOffsets, $filesWithCaptureOffsets);
        $filenames = array_keys($filesWithCaptureOffsets);

        foreach (array_values($filenames) as $index => $filename) {
            $payload = [
                'files' => [UploadedFile::fake()->create($filename, 10, 'image/jpeg')],
                'upload_type' => 'raw',
                'idempotency_key' => $batchId.'-'.$index,
                'upload_batch_id' => $batchId,
                'upload_batch_total' => count($filenames),
                'upload_batch_index' => $index,
            ];
            if ($shootServiceId !== null) {
                $payload['shoot_service_id'] = $shootServiceId;
            }
            // Sent to mirror the frontend and to take part in the idempotency
            // fingerprint. The server does not trust it: the divisor comes from the
            // service item. Non-bracket groups omit the field entirely.
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

    /** @return array<string, string> filename => "stack/frame" */
    private function stacksFor(?int $shootServiceId): array
    {
        $query = ShootFile::query()->where('shoot_id', $this->shoot->id);
        $query = $shootServiceId === null
            ? $query->whereNull('shoot_service_id')
            : $query->where('shoot_service_id', $shootServiceId);

        return $query->orderBy('id')->get()
            ->mapWithKeys(fn (ShootFile $file) => [
                $file->filename => $file->bracket_group.'/'.$file->sequence,
            ])
            ->all();
    }

    /**
     * Seven frames at 5x: one full stack, then a stack holding only frames 1-2.
     *
     * @return array<string, int>
     */
    private function exteriorSevenFrames(): array
    {
        return [
            'ext-1.jpg' => 0,
            'ext-2.jpg' => 1,
            'ext-3.jpg' => 2,
            'ext-4.jpg' => 3,
            'ext-5.jpg' => 4,
            'ext-6.jpg' => self::NEW_STACK_GAP,
            'ext-7.jpg' => self::NEW_STACK_GAP + 1,
        ];
    }

    /** @return array<string, string> */
    private function exteriorSevenFrameStacks(): array
    {
        return [
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
            'ext-4.jpg' => '1/4',
            'ext-5.jpg' => '1/5',
            'ext-6.jpg' => '2/1',
            'ext-7.jpg' => '2/2',
        ];
    }

    public function test_a_second_service_starts_its_own_stack_instead_of_continuing_an_incomplete_one(): void
    {
        $exterior = $this->serviceItem('Exterior HDR');
        $interior = $this->serviceItem('Interior HDR');

        $this->uploadGroup($this->exteriorSevenFrames(), $exterior->id, 5, 'batch-exterior');
        // Interior starts two seconds after Exterior's last frame: inside the clustering
        // gap, so an unscoped stacker absorbs these into Exterior's partial stack 2.
        $this->uploadGroup([
            'int-1.jpg' => self::NEW_STACK_GAP + 3,
            'int-2.jpg' => self::NEW_STACK_GAP + 4,
            'int-3.jpg' => self::NEW_STACK_GAP + 5,
            'int-4.jpg' => self::NEW_STACK_GAP + 6,
            'int-5.jpg' => self::NEW_STACK_GAP + 7,
        ], $interior->id, 5, 'batch-interior');

        $this->assertSame($this->exteriorSevenFrameStacks(), $this->stacksFor($exterior->id));

        // The regression this guards: int-1 must not be stack 2 frame 3.
        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
            'int-3.jpg' => '1/3',
            'int-4.jpg' => '1/4',
            'int-5.jpg' => '1/5',
        ], $this->stacksFor($interior->id));
    }

    public function test_a_later_upload_to_the_same_service_continues_its_own_partial_stack(): void
    {
        $exterior = $this->serviceItem('Exterior HDR');
        $interior = $this->serviceItem('Interior HDR');

        $this->uploadGroup($this->exteriorSevenFrames(), $exterior->id, 5, 'batch-exterior-1');
        // A different service in between must not shift Exterior's numbering.
        $this->uploadGroup(['int-1.jpg' => self::NEW_STACK_GAP + 3], $interior->id, 5, 'batch-interior');
        // Exterior resumes. Its stack 2 already holds frames 1-2, so the next three
        // frames complete that stack before a new one opens.
        $this->uploadGroup([
            'ext-8.jpg' => self::NEW_STACK_GAP + 2,
            'ext-9.jpg' => self::NEW_STACK_GAP + 3,
            'ext-10.jpg' => self::NEW_STACK_GAP + 4,
            'ext-11.jpg' => self::NEW_STACK_GAP * 2,
        ], $exterior->id, 5, 'batch-exterior-2');

        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
            'ext-4.jpg' => '1/4',
            'ext-5.jpg' => '1/5',
            'ext-6.jpg' => '2/1',
            'ext-7.jpg' => '2/2',
            'ext-8.jpg' => '2/3',
            'ext-9.jpg' => '2/4',
            'ext-10.jpg' => '2/5',
            'ext-11.jpg' => '3/1',
        ], $this->stacksFor($exterior->id));

        $this->assertSame(['int-1.jpg' => '1/1'], $this->stacksFor($interior->id));
    }

    public function test_services_captured_at_different_bracket_sizes_keep_independent_stacks(): void
    {
        // The real multi-photographer shape: each service carries its own recorded
        // size, 5x on Exterior and 3x on Interior.
        $exterior = $this->serviceItem('Exterior HDR', 10, true, 5);
        $interior = $this->serviceItem('Interior HDR', 10, true, 3);

        $this->uploadGroup($this->exteriorSevenFrames(), $exterior->id, 5, 'batch-exterior');
        $this->uploadGroup([
            'int-1.jpg' => self::NEW_STACK_GAP + 3,
            'int-2.jpg' => self::NEW_STACK_GAP + 4,
            'int-3.jpg' => self::NEW_STACK_GAP + 5,
            'int-4.jpg' => self::NEW_STACK_GAP * 2,
        ], $interior->id, 3, 'batch-interior');

        $this->assertSame($this->exteriorSevenFrameStacks(), $this->stacksFor($exterior->id));

        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
            'int-3.jpg' => '1/3',
            'int-4.jpg' => '2/1',
        ], $this->stacksFor($interior->id));
    }

    public function test_a_non_bracket_group_neither_takes_stack_numbers_nor_rewrites_the_shoot_bracket_mode(): void
    {
        $exterior = $this->serviceItem('Exterior HDR');
        $floorPlan = $this->serviceItem('2D Floor Plan', 0, false, null);

        $this->uploadGroup(['ext-1.jpg' => 0, 'ext-2.jpg' => 1], $exterior->id, 5, 'batch-exterior');
        // Non-bracket group: no bracket_mode field at all, and its own media type.
        $this->uploadGroup(['fp-1.jpg' => 2], $floorPlan->id, null, 'batch-floorplan', 'floorplan');

        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
        ], $this->stacksFor($exterior->id));

        $floorPlanFile = ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->where('shoot_service_id', $floorPlan->id)
            ->sole();
        $this->assertSame('floorplan', $floorPlanFile->media_type);
        $this->assertNull($floorPlanFile->bracket_group);
        $this->assertNull($floorPlanFile->sequence);

        // The shoot-wide capture setting must survive a group that does not bracket.
        $this->assertSame(5, (int) $this->shoot->fresh()->bracket_mode);
    }

    public function test_unassigned_uploads_are_numbered_against_each_other_not_against_a_service(): void
    {
        $exterior = $this->serviceItem('Exterior HDR');

        $this->uploadGroup($this->exteriorSevenFrames(), $exterior->id, 5, 'batch-exterior');
        $this->uploadGroup([
            'loose-1.jpg' => self::NEW_STACK_GAP + 3,
            'loose-2.jpg' => self::NEW_STACK_GAP + 4,
        ], null, 5, 'batch-unassigned');

        $this->assertSame($this->exteriorSevenFrameStacks(), $this->stacksFor($exterior->id));
        $this->assertSame([
            'loose-1.jpg' => '1/1',
            'loose-2.jpg' => '1/2',
        ], $this->stacksFor(null));
    }

    public function test_each_group_is_recorded_under_its_own_upload_batch_and_service(): void
    {
        $exterior = $this->serviceItem('Exterior HDR');
        $interior = $this->serviceItem('Interior HDR');

        $this->uploadGroup(['ext-1.jpg' => 0, 'ext-2.jpg' => 1, 'ext-3.jpg' => 2], $exterior->id, 5, 'batch-a');
        $this->uploadGroup([
            'int-1.jpg' => 3,
            'int-2.jpg' => 4,
        ], $interior->id, 5, 'batch-b');

        // One batch id per group, each carrying its own service, so neither group's
        // cached offset can be read by the other.
        $batches = \App\Models\ShootUploadAttempt::query()
            ->where('shoot_id', $this->shoot->id)
            ->get()
            ->groupBy('upload_batch_id')
            ->map(fn ($attempts) => $attempts->pluck('shoot_service_id')->unique()->values()->all());

        $this->assertSame(['batch-a', 'batch-b'], $batches->keys()->sort()->values()->all());
        $this->assertSame([$exterior->id], $batches['batch-a']);
        $this->assertSame([$interior->id], $batches['batch-b']);

        // Both groups start at frame 1 of their own first stack even though every frame
        // here was captured inside a single clustering window.
        $this->assertSame([
            'ext-1.jpg' => '1/1',
            'ext-2.jpg' => '1/2',
            'ext-3.jpg' => '1/3',
        ], $this->stacksFor($exterior->id));
        $this->assertSame([
            'int-1.jpg' => '1/1',
            'int-2.jpg' => '1/2',
        ], $this->stacksFor($interior->id));
    }
}
