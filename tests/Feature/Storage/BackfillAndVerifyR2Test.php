<?php

namespace Tests\Feature\Storage;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillAndVerifyR2Test extends TestCase
{
    use RefreshDatabase;

    private function seedMedia(): ShootFile
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
        ]);

        $original = "shoots/{$shoot->id}/todo/orig.jpg";
        $web = "shoots/{$shoot->id}/web/orig.jpg";
        Storage::disk('public')->put($original, 'ORIGINAL');
        Storage::disk('public')->put($web, 'WEB');

        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'orig.jpg',
            'stored_filename' => 'orig.jpg',
            'path' => $original,
            'storage_path' => $original,
            'web_path' => $web,
            'file_type' => 'image/jpeg',
            'file_size' => 8,
            'media_type' => 'raw',
            'uploaded_by' => $photographer->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
    }

    public function test_backfill_copies_local_media_to_r2_and_verify_passes(): void
    {
        Storage::fake('public');
        Storage::fake('media');

        $file = $this->seedMedia();

        $this->artisan('media:backfill-r2')->assertExitCode(0);

        Storage::disk('media')->assertExists($file->path);
        Storage::disk('media')->assertExists($file->web_path);

        $this->artisan('media:verify-r2')->assertExitCode(0);
    }

    public function test_dry_run_does_not_write_to_r2(): void
    {
        Storage::fake('public');
        Storage::fake('media');

        $file = $this->seedMedia();

        $this->artisan('media:backfill-r2', ['--dry-run' => true])->assertExitCode(0);

        Storage::disk('media')->assertMissing($file->path);
    }

    public function test_verify_reports_gap_when_object_missing_on_r2(): void
    {
        Storage::fake('public');
        Storage::fake('media');

        $file = $this->seedMedia();
        $this->artisan('media:backfill-r2')->assertExitCode(0);

        // Simulate a gap.
        Storage::disk('media')->delete($file->web_path);

        $this->artisan('media:verify-r2')->assertExitCode(1);
    }
}
