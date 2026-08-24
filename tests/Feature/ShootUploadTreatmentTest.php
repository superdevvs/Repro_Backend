<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A per-file treatment must never cost a frame its identity.
 *
 * Virtual staging, green grass and twilight are asked for on one individual image.
 * They used to be written into `media_type`, which is a single scalar, so requesting
 * one overwrote `raw` — and every raw-scoped predicate keys on that value. A frame
 * marked for virtual staging silently left its service's bracket stacks, the Photos
 * tab and the delivery whitelist.
 *
 * These tests pin the separation: `treatment` records what was asked for,
 * `media_type` keeps the capture identity, and `shoot_service_id` keeps ownership.
 */
class ShootUploadTreatmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shoot $shoot;

    private ShootService $exteriorHdr;

    private ShootService $drone;

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

        $this->exteriorHdr = $this->item($this->service('10 Exterior HDR Photos', 10, true), 5);
        // Drone is photo work that does not bracket, so it is the case that proves a
        // treatment cannot introduce stacking where none belongs.
        $this->drone = $this->item($this->service('10-12 Drone Photos Package', 10, false), null);
    }

    private function service(string $name, int $photoCount, bool $brackets): Service
    {
        return Service::query()->create([
            'name' => $name,
            'description' => $name,
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => Category::query()->firstOrCreate(['name' => 'Photos'])->id,
            'pricing_type' => 'fixed',
            'photo_count' => $photoCount,
            'uses_hdr_brackets' => $brackets,
            'upload_intake_type' => Service::INTAKE_PHOTO,
        ]);
    }

    private function item(Service $service, ?int $bracketMode): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $this->shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function upload(ShootService $item, string $filename, array $extra = [])
    {
        return $this->post(
            '/api/shoots/'.$this->shoot->id.'/upload',
            array_merge([
                'files' => [UploadedFile::fake()->create($filename, 12, 'image/jpeg')],
                'upload_type' => 'raw',
                'shoot_service_id' => $item->id,
                'idempotency_key' => 'key-'.$filename,
                'upload_batch_id' => 'batch-'.$item->id,
                'upload_batch_total' => 1,
                'upload_batch_index' => 0,
            ], $extra),
            ['Accept' => 'application/json']
        );
    }

    private function storedFile(string $filename): ShootFile
    {
        return ShootFile::query()
            ->where('shoot_id', $this->shoot->id)
            ->where('filename', 'like', '%'.pathinfo($filename, PATHINFO_FILENAME).'%')
            ->firstOrFail();
    }

    /**
     * 1. Exterior HDR + VS keeps the execution row and the raw capture identity.
     */
    public function test_virtual_staging_keeps_service_ownership_and_raw_identity(): void
    {
        $this->upload($this->exteriorHdr, 'IMG_001.jpg', ['treatment' => 'virtual_staging'])
            ->assertSuccessful();

        $file = $this->storedFile('IMG_001');

        $this->assertSame($this->exteriorHdr->id, (int) $file->shoot_service_id, 'ownership must stay on the Exterior HDR execution row');
        $this->assertSame('raw', $file->media_type, 'a treatment must not overwrite the raw capture identity');
        $this->assertSame('virtual_staging', $file->treatment, 'the treatment must survive the upload');
    }

    /**
     * 2. Exterior HDR + TW leaves bracket grouping exactly as an untreated frame.
     *
     * This is the regression the old media_type approach caused: a treated frame fell
     * out of the raw-scoped stacking query and was never numbered.
     */
    public function test_twilight_leaves_bracket_grouping_unchanged(): void
    {
        $this->upload($this->exteriorHdr, 'IMG_010.jpg', ['treatment' => 'twilight'])
            ->assertSuccessful();

        $treated = $this->storedFile('IMG_010');

        $this->assertSame($this->exteriorHdr->id, (int) $treated->shoot_service_id);
        $this->assertSame('raw', $treated->media_type);
        $this->assertSame('twilight', $treated->treatment);
        $this->assertNotNull($treated->bracket_group, 'a treated frame must still be stacked');
        $this->assertNotNull($treated->sequence, 'a treated frame must still be numbered');

        // Same position an untreated first frame of a 5x service would receive.
        $this->assertSame(1, (int) $treated->bracket_group);
        $this->assertSame(1, (int) $treated->sequence);
    }

    /**
     * 3. Drone + GG stays on the drone row and stays non-bracketed.
     */
    public function test_green_grass_on_drone_keeps_drone_ownership_and_no_brackets(): void
    {
        $this->upload($this->drone, 'DJI_0001.jpg', ['treatment' => 'green_grass'])
            ->assertSuccessful();

        $file = $this->storedFile('DJI_0001');

        $this->assertSame($this->drone->id, (int) $file->shoot_service_id, 'drone work stays owned by the drone execution row');
        $this->assertSame('raw', $file->media_type);
        $this->assertSame('green_grass', $file->treatment);
        $this->assertNull($file->bracket_group, 'drone work does not bracket, treated or not');
        $this->assertNull($file->sequence, 'drone work does not bracket, treated or not');
    }

    /**
     * 4. Extra keeps its existing exception semantics and is not a treatment.
     */
    public function test_extra_keeps_its_existing_media_type_semantics(): void
    {
        $this->upload($this->exteriorHdr, 'IMG_020.jpg', ['media_type' => 'extra', 'is_extra' => '1'])
            ->assertSuccessful();

        $file = $this->storedFile('IMG_020');

        $this->assertSame('extra', $file->media_type, 'Extra remains a media_type, not a treatment');
        $this->assertTrue((bool) $file->is_extra);
        $this->assertNull($file->treatment, 'Extra must not populate the treatment column');
    }

    /**
     * 5. The treatment reaches the client on the file payload.
     */
    public function test_treatment_is_exposed_on_the_files_payload(): void
    {
        $this->upload($this->exteriorHdr, 'IMG_030.jpg', ['treatment' => 'virtual_staging'])
            ->assertSuccessful();

        $response = $this->getJson('/api/shoots/'.$this->shoot->id.'/files');
        $response->assertSuccessful();

        $payload = $response->json();
        $flat = json_encode($payload);

        $this->assertStringContainsString('virtual_staging', (string) $flat, 'the treatment must be readable by the client');
        $this->assertStringNotContainsString('"media_type":"virtual_staging"', (string) $flat, 'the treatment must not appear as a media type');
    }

    /**
     * 6. Floor plan and drone are not accepted as treatments.
     *
     * They are capture/service classifications owned by the booked service group and
     * must not compete with service ownership at the file level.
     */
    public function test_floorplan_and_drone_are_rejected_as_treatments(): void
    {
        $this->assertNull(ShootFile::normalizeTreatment('floorplan'));
        $this->assertNull(ShootFile::normalizeTreatment('drone'));
        $this->assertNull(ShootFile::normalizeTreatment('extra'));
        $this->assertNull(ShootFile::normalizeTreatment(''));
        $this->assertNull(ShootFile::normalizeTreatment(null));
        $this->assertNull(ShootFile::normalizeTreatment('anything-else'));

        $this->assertSame('virtual_staging', ShootFile::normalizeTreatment('virtual_staging'));
        $this->assertSame('green_grass', ShootFile::normalizeTreatment('  GREEN_GRASS '));
        $this->assertSame('twilight', ShootFile::normalizeTreatment('Twilight'));

        // And an upload asking for one of them as a treatment stores no treatment.
        $this->upload($this->exteriorHdr, 'IMG_040.jpg', ['treatment' => 'floorplan'])
            ->assertSuccessful();

        $file = $this->storedFile('IMG_040');
        $this->assertNull($file->treatment);
        $this->assertSame('raw', $file->media_type, 'a rejected treatment must not alter the capture identity either');
    }
}
