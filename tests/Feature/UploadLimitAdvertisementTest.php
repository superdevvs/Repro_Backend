<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadLimitAdvertisementTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_payload_advertises_the_configured_one_gib_per_file_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($admin);

        $maxBytes = (int) config('uploads.max_bytes');
        $this->assertSame(1048576 * 1024, $maxBytes);

        $this->postJson("/api/shoots/{$shoot->id}/upload", ['upload_type' => 'raw'])
            ->assertStatus(422)
            ->assertJsonPath('upload_limits.per_file_bytes', $maxBytes)
            ->assertJsonPath('upload_limits.per_file', '1GB');
    }
}
