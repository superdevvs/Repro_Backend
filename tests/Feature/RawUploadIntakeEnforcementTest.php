<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The API must enforce upload eligibility itself.
 *
 * Client-side filtering is presentation only. This endpoint is reachable directly, so
 * every rule the selector applies has to hold here too: the execution row must belong
 * to the shoot, the actor must be allowed to use it, its catalogue service must declare
 * the lane the files actually need, and bracket logic must only run for photo capture.
 *
 * The concrete failure being closed: a shoot whose only booked service was a Matterport
 * tour auto-selected that tour and silently attached camera files to it.
 */
class RawUploadIntakeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();

        $this->shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 5,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToTodo')->andReturnUsing(
            function (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null) {
                return ShootFile::create([
                    'shoot_id' => $target->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $file->getClientOriginalName(),
                    'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                    'file_type' => $file->getMimeType() ?: 'image/jpeg',
                    'file_size' => $file->getSize(),
                    'media_type' => $mediaType ?: 'raw',
                    'uploaded_by' => $actorId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                ]);
            }
        );
        app()->instance(DropboxWorkflowService::class, $dropbox);
    }

    private function service(string $name, string $category, string $intake, array $extra = []): Service
    {
        return Service::query()->create(array_merge([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => $category])->id,
            'pricing_type' => 'fixed',
            'upload_intake_type' => $intake,
        ], $extra));
    }

    private function item(Service $service, ?int $photographerId = null, ?int $bracketMode = null): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'photographer_id' => $photographerId,
            'bracket_mode' => $bracketMode,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function upload(array $overrides = [], string $filename = 'frame-1.jpg', string $mime = 'image/jpeg')
    {
        $payload = array_merge([
            'files' => [UploadedFile::fake()->create($filename, 10, $mime)],
            'upload_type' => 'raw',
            'idempotency_key' => 'test-'.$filename.'-'.bin2hex(random_bytes(4)),
        ], $overrides);

        return $this->post(
            '/api/shoots/'.$this->shoot->id.'/upload',
            $payload,
            ['Accept' => 'application/json']
        );
    }

    public function test_a_matterport_only_shoot_never_receives_raw_files_on_the_tour(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $tour = $this->service('3D Matterport w/ 2D Floor plans', '3D/360 Tours', Service::INTAKE_NONE);
        $tourItem = $this->item($tour);

        $this->upload(['shoot_service_id' => $tourItem->id])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'service_item_not_uploadable')
            ->assertJsonPath('success_count', 0);

        $this->assertSame(0, ShootFile::query()->where('shoot_service_id', $tourItem->id)->count());
    }

    public function test_a_photographer_with_only_a_tour_assigned_is_not_auto_selected_onto_it(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->shoot->update(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $tour = $this->service('Premium iGuide with Floor plans', '3D/360 Tours', Service::INTAKE_NONE);
        $tourItem = $this->item($tour, $photographer->id);

        // The sole-assigned-item shortcut must not reach a service that cannot accept
        // the media. Without the lane filter this silently attached files to the tour.
        $response = $this->upload();
        $this->assertContains($response->status(), [403, 422], 'a tour-only shoot must refuse raw capture');

        $this->assertSame(0, ShootFile::query()->where('shoot_service_id', $tourItem->id)->count());
    }

    public function test_frontend_filtering_cannot_be_bypassed_for_an_enhancement_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // A caller crafting the request by hand, ignoring whatever the UI offered.
        $staging = $this->service('Virtual Staging (per image)', 'Virtual Staging', Service::INTAKE_NONE, [
            'quantity' => 1,
        ]);
        $stagingItem = $this->item($staging);

        $this->upload(['shoot_service_id' => $stagingItem->id])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'service_item_not_uploadable');
    }

    public function test_a_catalogue_service_id_supplied_instead_of_an_execution_row_id_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $hdr = $this->service('25 HDR Photos', 'Photos', Service::INTAKE_PHOTO, [
            'photo_count' => 25,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($hdr, null, 5);

        // Make the two ids differ so the mistake is detectable at all.
        $this->assertNotSame((int) $hdr->id, (int) $item->id);

        $this->upload(['shoot_service_id' => $hdr->id])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_service_item');

        $this->assertSame(0, ShootFile::query()->whereNotNull('shoot_service_id')->count());
    }

    public function test_a_pivot_from_another_shoot_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $other = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $hdr = $this->service('35 HDR Photos', 'Photos', Service::INTAKE_PHOTO, [
            'photo_count' => 35,
            'uses_hdr_brackets' => true,
        ]);
        $foreignItem = ShootService::query()->create([
            'shoot_id' => $other->id,
            'service_id' => $hdr->id,
            'price' => 100,
            'quantity' => 1,
        ]);

        $this->upload(['shoot_service_id' => $foreignItem->id])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_service_item');
    }

    public function test_a_video_file_is_refused_by_a_photo_only_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $hdr = $this->service('25 HDR Photos', 'Photos', Service::INTAKE_PHOTO, [
            'photo_count' => 25,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($hdr, null, 5);

        // The lane comes from the file, so a declared "photo" does not launder a video.
        $this->upload(
            ['shoot_service_id' => $item->id, 'upload_lane' => 'photo'],
            'walkthrough.mp4',
            'video/mp4'
        )
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'service_item_not_uploadable');
    }

    public function test_a_bundled_service_accepts_both_lanes_but_only_brackets_the_photo_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $bundle = $this->service('HDR Photos, Video & Premium iGuide', 'Packages', Service::INTAKE_PHOTO_VIDEO, [
            'photo_count' => 30,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($bundle, null, 5);

        $this->upload(['shoot_service_id' => $item->id], 'still-1.jpg', 'image/jpeg')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $this->upload(['shoot_service_id' => $item->id], 'clip-1.mp4', 'video/mp4')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $photo = ShootFile::query()->where('filename', 'still-1.jpg')->firstOrFail();
        $video = ShootFile::query()->where('filename', 'clip-1.mp4')->firstOrFail();

        // Both belong to the same execution row; media type is what separates them.
        $this->assertSame((int) $item->id, (int) $photo->shoot_service_id);
        $this->assertSame((int) $item->id, (int) $video->shoot_service_id);

        // Only the photo lane is stacked. A video raw must never take a stack number.
        $this->assertNotNull($photo->bracket_group);
        $this->assertNull($video->bracket_group);
    }

    public function test_an_eligible_photo_service_still_uploads_normally(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $hdr = $this->service('45 HDR Photos', 'Photos', Service::INTAKE_PHOTO, [
            'photo_count' => 45,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($hdr, null, 3);

        $this->upload(['shoot_service_id' => $item->id])
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $file = ShootFile::query()->where('shoot_service_id', $item->id)->firstOrFail();
        $this->assertSame('raw', $file->media_type);
        $this->assertNotNull($file->bracket_group);
    }

    public function test_drone_photo_capture_is_accepted_without_being_stacked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $drone = $this->service('10-12 Drone Photos Package', 'Drone', Service::INTAKE_PHOTO, [
            'photo_count' => 10,
            'uses_hdr_brackets' => false,
            'quantity' => 10,
        ]);
        $item = $this->item($drone);

        $this->upload(['shoot_service_id' => $item->id], 'aerial-1.jpg')
            ->assertOk()
            ->assertJsonPath('success_count', 1);

        $file = ShootFile::query()->where('shoot_service_id', $item->id)->firstOrFail();
        $this->assertNull($file->bracket_group, 'drone frames are not exposure stacks');
    }

    public function test_upload_from_source_refuses_a_non_intake_service_before_fetching(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $tour = $this->service('Zillow 3D Home Tour', '3D/360 Tours', Service::INTAKE_NONE);
        $tourItem = $this->item($tour);

        // A URL that would fail loudly if it were ever fetched.
        $this->postJson('/api/shoots/'.$this->shoot->id.'/upload-from-source', [
            'upload_type' => 'raw',
            'source_type' => 'url',
            'urls' => ['https://invalid.invalid/never-fetched.jpg'],
            'shoot_service_id' => $tourItem->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'service_item_not_uploadable');

        $this->assertSame(0, ShootFile::query()->where('shoot_service_id', $tourItem->id)->count());
    }
}
