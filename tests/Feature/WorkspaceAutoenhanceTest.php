<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\WorkspaceAutoenhance;
use App\Services\Studio\WorkspaceImageOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WorkspaceAutoenhanceTest extends TestCase
{
    use RefreshDatabase;

    private string $image;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.autoenhance.api_key' => 'test-key', 'services.autoenhance.webhook_secret' => 'test-secret', 'services.autoenhance.poll_timeout' => 0, 'services.autoenhance.base_url' => 'https://api.autoenhance.ai']);
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagejpeg($image);
        $this->image = ob_get_clean();
        imagedestroy($image);
    }

    public function test_native_photo_routes_override_old_settings_and_fotello_is_only_for_full_shoot(): void
    {
        $settings = app(StudioProviderSettings::class);
        foreach (StudioProviderSettings::AUTOENHANCE_SERVICES as $service) {
            $this->assertSame('autoenhance', $settings->route($service, ['routes' => [$service => ['provider' => 'fotello']]])['provider']);
            $this->assertSame(['autoenhance'], array_column($settings->providers($service), 'id'));
        }
        $this->assertSame('fotello', $settings->route('full-shoot')['provider']);
        $this->assertSame('virtualstagingai', $settings->route('virtual-staging')['provider']);
        foreach (['revision', 'twilight', 'outpaint'] as $service) {
            $this->assertNotContains('fotello', array_column($settings->providers($service), 'id'));
            $this->assertNotSame('fotello', $settings->route($service, ['routes' => [$service => ['provider' => 'fotello']]])['provider']);
        }
    }

    public function test_workspace_edit_uses_autoenhance_and_downloads_full_quality_only_once_per_saved_image(): void
    {
        $this->fakeCompleted();
        $workspace = $this->workspace();
        foreach ([1, 2] as $_) {
            $this->assertSame($this->image, app(WorkspaceImageOperations::class)->edit($workspace, 'op', ['id' => 'm1'], $this->image, 'Enhance'));
        }
        Http::assertSentCount(6); // create, upload, then two status/download pairs
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['enhance'] === true && $r->hasHeader('x-api-key', 'test-key'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->body() === $this->image && $r->hasHeader('Content-Type', 'application/octet-stream'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/enhanced') && $r['preview'] === 'false' && $r['quality'] === 90);
    }

    public function test_pending_job_resumes_without_another_create_or_upload(): void
    {
        Http::fake([
            '*/v3/images/' => Http::response(['image_id' => 'image-1', 'upload_url' => 'https://api.autoenhance.ai/upload/1']),
            '*/upload/1' => Http::response('', 200),
            '*/v3/images/image-1' => Http::sequence()->push(['status' => 'processing'])->push(['status' => 'processed']),
            '*/enhanced*' => Http::response($this->image, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $workspace = $this->workspace();
        try {
            $this->runEnhance($workspace);
            $this->fail('Pending response accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('existing job', $e->getMessage());
        }
        $this->assertSame($this->image, $this->runEnhance($workspace->fresh()));
        Http::assertSentCount(5);
    }

    public function test_ambiguous_create_is_not_resubmitted(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $workspace = $this->workspace();
        foreach ([1, 2] as $_) {
            try {
                $this->runEnhance($workspace);
                $this->fail('Ambiguous creation accepted.');
            } catch (StudioProviderException $e) {
                $this->assertTrue($e->ambiguous);
            }
        }
        Http::assertSentCount(1);
    }

    public function test_foreign_upload_url_never_receives_api_key(): void
    {
        Http::fake(['*/v3/images/' => Http::response(['image_id' => 'image-1', 'upload_url' => 'https://untrusted.test/upload'])]);
        try {
            $this->runEnhance($this->workspace());
            $this->fail('Foreign upload accepted.');
        } catch (StudioProviderException $e) {
            $this->assertStringContainsString('upload location', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_saved_account_change_cannot_resume_under_another_key(): void
    {
        $this->fakeCompleted();
        $workspace = $this->workspace();
        $this->runEnhance($workspace);
        config(['services.autoenhance.api_key' => 'another-key']);
        try {
            $this->runEnhance($workspace);
            $this->fail('Changed account accepted.');
        } catch (StudioProviderException $e) {
            $this->assertStringContainsString('account changed', $e->getMessage());
        }
        Http::assertSentCount(4);
    }

    public function test_signed_s3_upload_preserves_signed_content_type_without_forwarding_credentials(): void
    {
        Http::fake([
            '*/v3/images/' => Http::response(['image_id' => 'image-1', 'upload_url' => 'https://autoenhanceapi-prod.s3-accelerate.amazonaws.com/source?content-type=image%2Fjpeg']),
            '*.s3-accelerate.amazonaws.com/*' => Http::response('', 200),
            '*/v3/images/image-1' => Http::response(['status' => 'processed']),
            '*/enhanced*' => Http::response($this->image, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->assertSame($this->image, $this->runEnhance($this->workspace()));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && ! $r->hasHeader('x-api-key') && $r->hasHeader('Content-Type', 'image/jpeg'));
    }

    public function test_grass_and_upscale_use_documented_options(): void
    {
        $this->fakeCompleted();
        $this->runEnhance($this->workspace(), 'green-grass');
        $workspace = $this->workspace();
        $this->assertSame($this->image, app(WorkspaceImageOperations::class)->upscale($workspace, 'op', 'm1', $this->image));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['restage']['grass'] ?? null) === 'GREEN');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['upscale'] === true && $r['enhance'] === false);
    }

    public function test_sky_and_perspective_presets_override_disabled_recipe_toggles(): void
    {
        $this->fakeCompleted();
        foreach (['sky-replacement' => 'sky_replacement', 'perspective-correction' => 'vertical_correction'] as $service => $option) {
            $workspace = $this->workspace();
            $workspace->update(['preset_id' => $service, 'config' => ['prompt' => '', 'adjustments' => ['skyReplacement' => false, 'verticalCorrection' => false]]]);
            $this->assertSame($this->image, app(WorkspaceImageOperations::class)->edit($workspace, 'op', ['id' => 'm1'], $this->image, 'Apply the selected preset'));
            Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['metadata']['workspace_id'] ?? null) === $workspace->id && $r[$option] === true);
        }
    }

    public function test_credentials_are_encrypted_and_webhook_matches_only_the_saved_image(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $response = $this->putJson('/api/studio/provider-settings', ['credentials' => ['autoenhance' => ['apiKey' => 'secret-key', 'webhookSecret' => 'secret-hook']]])
            ->assertOk()->assertJsonPath('data.credentials.autoenhance.keyConfigured', true)->assertJsonPath('data.credentials.autoenhance.webhookConfigured', true);
        foreach (['secret-key', 'secret-hook'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('studio_provider_settings')->value('payload'));
        }
        $this->fakeCompleted();
        $workspace = $this->workspace();
        $this->runEnhance($workspace);
        $this->withHeader('Authentication', 'wrong')->postJson('/api/webhooks/autoenhance', ['event' => 'image_processed', 'image_id' => 'image-1'])->assertUnauthorized();
        $this->withHeader('Authentication', 'secret-hook')->postJson('/api/webhooks/autoenhance', ['event' => 'image_processed', 'image_id' => 'image-1'])->assertOk()->assertJsonPath('message', 'Workspace webhook received');
        $state = $workspace->fresh()->operation['providerState']['autoenhance-'.hash('sha256', 'm1')];
        $this->assertSame('image_processed', $state['webhook']['event']);
        $this->assertSame('generating', $workspace->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_missing_credentials_fail_before_network_requests(): void
    {
        config(['services.autoenhance.api_key' => null]);
        $this->assertFalse(app(StudioProviderSettings::class)->capabilities()['presets']['listing-ready']['ready']);
        $this->expectException(StudioProviderException::class);
        try {
            $this->runEnhance($this->workspace());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_legacy_photo_upload_uses_saved_key_and_does_not_leak_it_to_s3(): void
    {
        Http::fake([
            '*/v3/images/' => Http::response(['image_id' => 'legacy-1', 'upload_url' => 'https://autoenhanceapi-prod.s3-accelerate.amazonaws.com/source']),
            '*.s3-accelerate.amazonaws.com/*' => Http::response('', 200),
        ]);
        $result = app(\App\Services\AutoenhanceService::class)->submitEditingJobFromBuffer($this->image, 'source.jpg', 'image/jpeg', 'enhance');
        $this->assertSame('legacy-1', $result['image_id']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('x-api-key', 'test-key'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && ! $r->hasHeader('x-api-key'));
    }

    public function test_legacy_shoot_photo_edit_ignores_old_fal_selection_for_enhancement(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $shoot = \App\Models\Shoot::factory()->create();
        $file = \App\Models\ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg',
            'path' => "shoots/{$shoot->id}/photo.jpg", 'storage_path' => "shoots/{$shoot->id}/photo.jpg",
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 1234,
            'uploaded_by' => $user->id, 'media_type' => 'raw', 'workflow_stage' => \App\Models\ShootFile::STAGE_TODO,
        ]);
        config(['services.ai_editing.provider' => 'fal']);
        $this->postJson('/api/autoenhance/edit', ['shoot_id' => $shoot->id, 'file_ids' => [$file->id], 'editing_type' => 'enhance', 'provider' => 'fal'])->assertCreated();
        $this->assertSame('autoenhance', \App\Models\AiEditingJob::firstOrFail()->provider);
        Queue::assertPushed(\App\Jobs\ProcessAutoenhanceEditingJob::class);
        Http::assertNothingSent();
    }

    public function test_generate_endpoint_routes_all_authorized_roles_and_persists_the_result(): void
    {
        Storage::fake('public');
        config(['studio_uploads.disk' => 'public', 'studio.client_access_enabled' => false]);
        $this->fakeCompleted();
        foreach (['superadmin', 'admin', 'editing_manager', 'editor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);
            $ref = "studio/uploads/{$user->id}/{$user->id}/source.jpg";
            Storage::disk('public')->put($ref, $this->image);
            $created = $this->postJson('/api/studio/workspaces', ['name' => 'Single photo', 'presetId' => 'listing-ready', 'media' => [['id' => 'm1', 'mediaRef' => $ref]]])->assertCreated();
            $id = $created->json('data.id');
            $this->postJson('/api/studio/workspaces/'.$id.'/generate')->assertAccepted();
            $workspace = StudioWorkspace::findOrFail($id);
            $this->assertSame('autoenhance', $workspace->operation['routing']['listing-ready']['provider']);
            app(\App\Services\Studio\WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $workspace->refresh();
            $this->assertSame('completed', $workspace->status);
            $this->assertCount(1, $workspace->outputs);
            $this->assertNotFalse(getimagesizefromstring(Storage::disk('public')->get($workspace->outputs[0]['path'])));
            $body = $this->getJson('/api/studio/workspaces/'.$id)->assertOk()->getContent();
            $this->assertStringNotContainsString('test-key', $body);
            $this->assertStringNotContainsString('providerState', $body);
        }
    }

    private function fakeCompleted(): void
    {
        Http::fake([
            '*/v3/images/' => Http::response(['image_id' => 'image-1', 'upload_url' => 'https://api.autoenhance.ai/upload/1']),
            '*/upload/1' => Http::response('', 200),
            '*/v3/images/image-1' => Http::response(['status' => 'processed']),
            '*/enhanced*' => Http::response($this->image, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function runEnhance(StudioWorkspace $workspace, string $service = 'listing-ready'): string
    {
        return app(WorkspaceAutoenhance::class)->run($workspace, 'op', ['id' => 'm1'], $this->image, $service);
    }

    private function workspace(): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin']);

        return StudioWorkspace::create([
            'team_id' => $user->id, 'created_by' => $user->id, 'name' => 'Photo test', 'preset_id' => 'listing-ready',
            'media' => [['id' => 'm1', 'kind' => 'image']], 'config' => ['prompt' => '', 'adjustments' => []],
            'status' => 'generating', 'outputs' => [], 'prepared_frames' => [],
            'operation' => ['id' => 'op', 'type' => 'generate', 'routing' => []],
        ]);
    }
}
