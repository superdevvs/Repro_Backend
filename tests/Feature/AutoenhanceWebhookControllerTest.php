<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoenhanceWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'autoenhance-test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
    }

    private function createAutoenhanceJob(string $imageId): AiEditingJob
    {
        $owner = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();

        return AiEditingJob::create([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'autoenhance',
            'status' => AiEditingJob::STATUS_PROCESSING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/media/source.jpg',
            'autoenhance_image_id' => $imageId,
        ]);
    }

    public function test_missing_secret_rejects_webhook_and_does_not_process(): void
    {
        config()->set('services.autoenhance.webhook_secret', '');

        $job = $this->createAutoenhanceJob('img-unconfigured');

        $this->postJson('/api/webhooks/autoenhance', [
            'event' => 'image_processed',
            'image_id' => 'img-unconfigured',
        ])->assertStatus(503);

        $this->assertNull($job->fresh()->provider_result);
    }

    public function test_missing_token_is_rejected_when_secret_is_configured(): void
    {
        config()->set('services.autoenhance.webhook_secret', self::SECRET);

        $job = $this->createAutoenhanceJob('img-missing-token');

        $this->postJson('/api/webhooks/autoenhance', [
            'event' => 'image_processed',
            'image_id' => 'img-missing-token',
        ])->assertStatus(401);

        $this->assertNull($job->fresh()->provider_result);
    }

    public function test_invalid_token_is_rejected(): void
    {
        config()->set('services.autoenhance.webhook_secret', self::SECRET);

        $job = $this->createAutoenhanceJob('img-bad-token');

        $this->withHeader('x-autoenhance-webhook-token', 'wrong-token')
            ->postJson('/api/webhooks/autoenhance', [
                'event' => 'image_processed',
                'image_id' => 'img-bad-token',
            ])
            ->assertStatus(401);

        $this->assertNull($job->fresh()->provider_result);
    }

    public function test_valid_token_processes_the_webhook(): void
    {
        config()->set('services.autoenhance.webhook_secret', self::SECRET);

        $job = $this->createAutoenhanceJob('img-valid');

        $this->withHeader('x-autoenhance-webhook-token', self::SECRET)
            ->postJson('/api/webhooks/autoenhance', [
                'event' => 'ping',
                'image_id' => 'img-valid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertIsArray($job->fresh()->provider_result);
        $this->assertArrayHasKey('webhook', $job->fresh()->provider_result);
    }

    public function test_authentication_header_token_is_accepted(): void
    {
        config()->set('services.autoenhance.webhook_secret', self::SECRET);

        $this->withHeader('Authentication', self::SECRET)
            ->postJson('/api/webhooks/autoenhance', [
                'event' => 'ping',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Webhook received');
    }
}
