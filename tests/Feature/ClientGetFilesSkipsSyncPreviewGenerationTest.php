<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Shoots\ShootMediaReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ClientGetFilesSkipsSyncPreviewGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_get_files_payload_does_not_sync_generate_optimized_versions(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'payment_status' => 'paid',
            'bypass_paywall' => true,
            'status' => 'delivered',
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'media_type' => 'edited',
            'filename' => 'room-01.jpg',
            'stored_filename' => 'room-01.jpg',
            'path' => 'shoots/'.$shoot->id.'/edited/room-01.jpg',
            'web_path' => null,
            'thumbnail_path' => null,
            'is_hidden' => false,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'uploaded_by' => $client->id,
        ]);

        $access = Mockery::mock(ShootFileAccessService::class)->makePartial();
        $access->shouldReceive('generateOptimizedVersions')->never();
        $access->shouldReceive('resolveFileUrl')->andReturn('https://cdn.example/original.jpg');
        $access->shouldReceive('resolvePublicStorageUrl')->andReturnUsing(
            fn ($path) => $path ? 'https://cdn.example/'.$path : null
        );
        $this->app->instance(ShootFileAccessService::class, $access);

        $request = Request::create('/api/shoots/'.$shoot->id.'/files', 'GET', ['type' => 'edited']);
        $request->setUserResolver(fn () => $client);

        $payload = app(ShootMediaReadService::class)->getFilesPayload($shoot->fresh(), $request);

        $this->assertSame(1, $payload['count']);
        $this->assertCount(1, $payload['data']);
    }
}
