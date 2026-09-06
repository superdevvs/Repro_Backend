<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\SmsSendException;
use App\Http\Resources\ShootResource;
use App\Models\IguideOfflineUploadChunk;
use App\Models\IguideOfflineUploadSession;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\IguideDataVisibilityService;
use App\Services\Messaging\MessagingService;
use App\Services\Shoots\FinalizeProgressTracker;
use App\Services\Shoots\ShootPresenter;
use App\Services\Users\PhoneNumberChangedNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\IsolatedSecurityTestCase;

class StoredUploadProgressErrorSecurityTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    private const CANARY = 'SQLSTATE synthetic-diagnostic-canary /private/fixture.env Authorization: Bearer fixture';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_finalize_polling_redacts_historical_failure_fields_and_preserves_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $tracker = app(FinalizeProgressTracker::class);
        $tracker->start($shoot->id);
        $tracker->fail($shoot->id, self::CANARY, FinalizeProgressTracker::STAGE_COMMIT);
        $response = $this->getJson("/api/shoots/{$shoot->id}/finalize-progress")->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.stages.1.status', 'failed')
            ->assertJsonCount(5, 'data.stages');
        $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        $this->assertSame(self::CANARY, Cache::get('shoot:finalize:progress:'.$shoot->id)['error']);

        $tracker->start($shoot->id);
        $tracker->stageCompleted($shoot->id, FinalizeProgressTracker::STAGE_COMMIT);
        $tracker->stageRunning($shoot->id, FinalizeProgressTracker::STAGE_LOCAL_CACHE, 'Caching delivered files', 2);
        $tracker->stageAdvanced($shoot->id, FinalizeProgressTracker::STAGE_LOCAL_CACHE, 2);
        $tracker->stageSkipped($shoot->id, FinalizeProgressTracker::STAGE_MLS_PUBLISH);
        $tracker->stageFailed($shoot->id, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, self::CANARY);
        $response = $this->getJson("/api/shoots/{$shoot->id}/finalize-progress")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.message', 'Finalize complete with warnings')
            ->assertJsonPath('data.stages.2.processed', 2)
            ->assertJsonPath('data.stages.2.total', 2)
            ->assertJsonPath('data.failures.0.stage', 'delivery_email');
        $this->assertStringNotContainsString(self::CANARY, $response->getContent());
    }

    public function test_resumable_payload_redacts_old_errors_but_preserves_chunks_retry_and_incomplete_guidance(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $id = (string) Str::uuid();
        $package = [
            'id' => $id, 'upload_id' => $id, 'status' => 'queued', 'error' => self::CANARY,
            'previous_ready' => ['status' => 'ready', 'file_id' => 42, 'download_url' => '/api/fixture/download',
                'last_replacement_failure' => ['error' => self::CANARY, 'upload_id' => 'old-upload']],
        ];
        $shoot->update(['iguide_data' => ['manual_offline_package' => $package, 'provider_fixture' => 'preserved']]);
        $session = IguideOfflineUploadSession::create([
            'id' => $id, 'shoot_id' => $shoot->id, 'user_id' => $admin->id,
            'idempotency_key' => (string) Str::uuid(), 'original_filename' => 'fixture.zip',
            'size_bytes' => 20, 'chunk_size_bytes' => 10, 'total_chunks' => 2, 'received_bytes' => 10,
            'status' => 'uploading', 'retryable' => true, 'error' => self::CANARY,
            'last_activity_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        IguideOfflineUploadChunk::create([
            'upload_session_id' => $id, 'chunk_index' => 0, 'offset_bytes' => 0, 'size_bytes' => 10,
            'sha256' => str_repeat('a', 64), 'storage_path' => 'synthetic/no-file-created',
        ]);
        Sanctum::actingAs($admin);
        $url = "/api/integrations/shoots/{$shoot->id}/iguide/offline-package/uploads/{$id}";
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('upload.status', 'uploading')->assertJsonPath('upload.retryable', true)
            ->assertJsonPath('upload.received_chunk_indexes', [0])->assertJsonPath('upload.received_bytes', 10)
            ->assertJsonPath('manual_offline_package.previous_ready.download_url', '/api/fixture/download')
            ->assertJsonPath('iguide_data.provider_fixture', 'preserved');
        $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        $response = $this->postJson($url.'/complete')->assertConflict()
            ->assertJsonPath('error_type', 'upload_incomplete')->assertJsonPath('missing_chunk_indexes', [1])
            ->assertJsonPath('upload.retryable', true);
        $this->assertStringNotContainsString(self::CANARY, $response->getContent());
        $this->assertSame(self::CANARY, $session->fresh()->error);
        $this->assertSame(self::CANARY, $shoot->fresh()->iguide_data['manual_offline_package']['error']);
    }

    public function test_shoot_details_cover_both_offline_aliases_without_hiding_operator_state(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $shoot = Shoot::factory()->create(['iguide_data' => ['manual_offline_package' => [
            'id' => 'fixture', 'status' => 'ready', 'file_id' => 42, 'download_url' => '/api/fixture/download',
            'last_replacement_failure' => ['error' => self::CANARY, 'upload_id' => 'failed-fixture'],
        ]]]);
        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $admin);
        $resource = (new ShootResource($shoot->fresh()))->toArray($request);
        $presented = app(ShootPresenter::class)->transformShoot($shoot->fresh())->toArray();
        foreach ([$resource, $presented] as $payload) {
            $this->assertStringNotContainsString(self::CANARY, json_encode($payload));
            $this->assertSame('ready', $payload['iguide_manual_offline_package']['status']);
            $this->assertSame('/api/fixture/download', $payload['iguide_manual_offline_package']['download_url']);
            $this->assertSame('failed-fixture', $payload['iguide_data']['manual_offline_package']['last_replacement_failure']['upload_id']);
        }
        $visible = app(IguideDataVisibilityService::class)->forUser($shoot->iguide_data, new User(['role' => 'client']));
        $this->assertSame(['status' => 'ready', 'view_only' => true], $visible['manual_offline_package']);
    }

    public function test_phone_change_result_hides_provider_failure_and_preserves_partial_success(): void
    {
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->once()->ordered()->andThrow(new \RuntimeException(self::CANARY));
        $messaging->shouldReceive('sendSms')->once()->ordered()->andReturn(new Message());
        $user = new User(['name' => 'Fixture', 'email' => 'fixture@example.test', 'role' => 'client']);
        $user->id = 912009;
        $result = (new PhoneNumberChangedNotificationService($messaging))->dispatch($user, '+12025550101', '+12025550102');
        $this->assertStringNotContainsString(self::CANARY, json_encode($result));
        $this->assertTrue($result['attempted']);
        $this->assertFalse($result['previous']['sent']);
        $this->assertSame('The phone change notification could not be sent. Please contact support.', $result['previous']['error']);
        $this->assertTrue($result['new']['sent']);
        $this->assertNull($result['new']['error']);
    }

    public function test_phone_change_result_retains_reviewed_sms_guidance_only(): void
    {
        $guidance = 'SMS could not be sent: the Telnyx sending number is not verified.';
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->twice()->andThrow(new SmsSendException($guidance));
        $result = (new PhoneNumberChangedNotificationService($messaging))->dispatch(
            new User(['role' => 'client']), '+12025550101', '+12025550102',
        );
        $this->assertSame($guidance, $result['previous']['error']);
        $this->assertSame($guidance, $result['new']['error']);
    }
}
