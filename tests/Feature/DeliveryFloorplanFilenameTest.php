<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\DeliveryFilenameFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Delivered floor plan names must fit MLS upload limits. CubiCasa names its
 * files after the full geocoded address (county, country, zip), which pushed
 * delivered copies past 110 characters and forced clients to rename each one.
 */
class DeliveryFloorplanFilenameTest extends TestCase
{
    use RefreshDatabase;

    private const CUBICASA_PDF = '0_1148-old-manchester-road-hillside-westminster-carroll-county-maryland-united-states-21157-business-merged.pdf';
    private const CUBICASA_JPG = '1_1148-old-manchester-road-hillside-westminster-carroll-county-maryland-united-states-21157-business_0.jpg';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    #[Test]
    public function long_provider_floorplan_names_are_shortened_to_the_street_address(): void
    {
        $shoot = $this->shoot(['address' => '1148 Old Manchester Road', 'city' => 'Westminster']);
        $pdf = $this->floorplan($shoot, self::CUBICASA_PDF, 'application/pdf');
        $jpg = $this->floorplan($shoot, self::CUBICASA_JPG, 'image/jpeg');
        $formatter = app(DeliveryFilenameFormatter::class);

        $this->assertSame('007_1148-old-manchester-road_floorplan.pdf', $formatter->formatForFile($pdf, 7, 40));
        $this->assertSame('008_1148-old-manchester-road_floorplan.jpg', $formatter->formatForFile($jpg, 8, 40));
    }

    #[Test]
    public function shortened_names_stay_within_the_mls_limit_with_room_for_a_page_suffix(): void
    {
        $shoot = $this->shoot(['address' => '16306 Trenton Road Basement Level Apartment Entrance Rear Of Building Upperco']);
        $file = $this->floorplan($shoot, self::CUBICASA_PDF, 'application/pdf');
        $formatter = app(DeliveryFilenameFormatter::class);

        $name = $formatter->formatForFile($file, 1234, 1500);
        $this->assertStringStartsWith('1234_16306-trenton-road', $name);
        $this->assertStringEndsWith('_floorplan.pdf', $name);
        $this->assertLessThanOrEqual(
            DeliveryFilenameFormatter::FLOORPLAN_MAX_LENGTH,
            strlen(pathinfo($name, PATHINFO_FILENAME).'-page-12.jpg'),
        );
    }

    #[Test]
    public function metric_and_imperial_variants_keep_their_unit_hint(): void
    {
        $shoot = $this->shoot(['address' => '1211 Ripple Court Rock Creek Landing Pasadena Anne Arundel County']);
        $metric = $this->floorplan($shoot, str_repeat('x', 70).'_floorplan_metric_en.pdf', 'application/pdf');
        $formatter = app(DeliveryFilenameFormatter::class);

        $this->assertStringEndsWith('_floorplan-metric.pdf', $formatter->formatForFile($metric, 1, 5));
    }

    #[Test]
    public function short_floorplan_names_and_other_media_are_left_alone(): void
    {
        $shoot = $this->shoot();
        $formatter = app(DeliveryFilenameFormatter::class);

        $iguide = $this->floorplan($shoot, 'floorplan_imperial_en.pdf', 'application/pdf');
        $this->assertSame('003_floorplan_imperial_en.pdf', $formatter->formatForFile($iguide, 3, 20));

        $basement = $this->floorplan($shoot, 'Basement.jpg', 'image/jpeg');
        $this->assertSame('004_Basement.jpg', $formatter->formatForFile($basement, 4, 20));

        $photo = ShootFile::create($this->fileAttributes($shoot, self::CUBICASA_JPG, 'image/jpeg', 'edited'));
        $this->assertSame('005_'.self::CUBICASA_JPG, $formatter->formatForFile($photo, 5, 20));
    }

    #[Test]
    public function a_whole_delivered_set_keeps_unique_floorplan_names(): void
    {
        $shoot = $this->shoot(['address' => '1148 Old Manchester Road']);
        $a = $this->floorplan($shoot, self::CUBICASA_PDF, 'application/pdf');
        $b = $this->floorplan($shoot, self::CUBICASA_PDF, 'application/pdf');

        $names = app(DeliveryFilenameFormatter::class)->mapForSet([$a, $b]);

        $this->assertSame([
            $a->id => '001_1148-old-manchester-road_floorplan.pdf',
            $b->id => '002_1148-old-manchester-road_floorplan.pdf',
        ], $names);
    }

    #[Test]
    public function a_shoot_without_an_address_falls_back_to_the_shoot_id(): void
    {
        $shoot = $this->shoot(['address' => '']);
        $file = $this->floorplan($shoot, self::CUBICASA_PDF, 'application/pdf');

        $this->assertSame(
            '001_shoot-'.$shoot->id.'_floorplan.pdf',
            app(DeliveryFilenameFormatter::class)->formatForFile($file, 1, 3),
        );
    }

    private function shoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'service_id' => Service::factory()->create()->id,
            'address' => '412 Delivery Row',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ], $overrides));
    }

    private function floorplan(Shoot $shoot, string $filename, string $mime): ShootFile
    {
        return ShootFile::create($this->fileAttributes($shoot, $filename, $mime, 'floorplan'));
    }

    private function fileAttributes(Shoot $shoot, string $filename, string $mime, string $mediaType): array
    {
        return [
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => 'shoots/'.$shoot->id.'/floorplans/'.$filename,
            'file_type' => $mime,
            'mime_type' => $mime,
            'file_size' => 1024,
            'media_type' => $mediaType,
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ];
    }
}
