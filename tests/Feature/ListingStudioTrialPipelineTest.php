<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioGuestTrial;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioGuestTrialService;
use App\Services\ListingStudio\ListingStudioTrialPipeline;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceMediaService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingStudioTrialPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        $private = Storage::fake('listing_studio_trials', config('filesystems.disks.listing_studio_trials'));
        $private->makeDirectory('');
        $private->setVisibility('', 'private');
        config(['studio.client_access_enabled' => false, 'listing-studio-trials.enabled' => true, 'services.autoenhance.api_key' => 'test-key',
            'listing-studio-trials.services.staging.enabled' => true, 'listing-studio-trials.services.decluttering.enabled' => true,
            'listing-studio-trials.services.twilight.enabled' => true,
            'services.fal.key' => 'test-key', 'studio_providers.virtualstagingai.api_key' => 'test-key']);
    }

    public function test_guest_edit_finishes_privately_while_general_client_studio_is_paused(): void
    {
        $trial = $this->trial('grass');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $this->assertNull($workspace->created_by);
        $this->assertNull($workspace->team_id);
        $this->assertSame('green-grass', $workspace->preset_id);
        $this->assertSame($workspace->id, app(ListingStudioTrialPipeline::class)->start($trial->fresh())->id);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);

        $this->mock(WorkspaceImageOperations::class)->shouldReceive('edit')->once()->andReturn($this->jpeg());
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once()->withArgs(fn ($record) => $record->status === 'completed');
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
        $this->assertNotEmpty($workspace->outputs);
        $output = $workspace->outputs[0];
        $this->assertNull($output['url']);
        Storage::disk('listing_studio_trials')->assertExists($output['path']);
        Storage::disk('public')->assertMissing($output['path']);
        Http::assertNothingSent();
    }

    public function test_decluttering_uses_a_fixed_revision_without_empty_room_staging(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('decluttering'));
        $this->assertSame('revision', $workspace->operation['type']);
        $this->assertSame('listing-ready', $workspace->preset_id);
        $this->assertArrayNotHasKey('outputId', $workspace->operation['payload']);
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('edit')->once()->withArgs(function ($record, $operationId, $item, $bytes, $prompt) {
            $this->assertStringContainsString('Preserve all furniture', $prompt);
            $this->assertStringContainsString('Do not empty the room', $prompt);
            $this->assertStringContainsString('leave the photo unchanged', $prompt);
            $this->assertStringContainsString('cushions, rugs, artwork, lamps', $prompt);

            return true;
        })->andReturn($this->jpeg());
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
    }

    public function test_trial_specialist_route_uses_one_nano_banana_output_without_changing_staff_route(): void
    {
        $settings = app(\App\Services\Studio\StudioProviderSettings::class);
        $staffRoute = $settings->route('revision');
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('decluttering'));
        $this->assertSame('fal-ai/nano-banana-pro/edit', $workspace->operation['routing']['revision']['model']);
        $this->assertSame($staffRoute, $settings->route('revision'));
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->once()->andReturn($this->jpeg());
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->assertSame('https://queue.fal.run/fal-ai/nano-banana-pro/edit', $request->url());
                $this->assertSame(1, $request['num_images']);
                $this->assertSame('2K', $request['resolution']);
                $this->assertTrue($request['limit_generations']);
                $this->assertCount(1, $request['image_urls']);
                $this->assertStringContainsString('Preserve all furniture', $request['prompt']);

                return Http::response(['request_id' => 'one-specialist-request']);
            }
            if (str_contains($request->url(), 'queue.fal.run/')) {
                return Http::response(['status' => 'COMPLETED', 'images' => [['url' => 'https://results.example.test/edited.jpg']]]);
            }

            return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        });
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_twilight_uses_fixed_bright_blue_hour_recipe_and_rejects_other_prompts(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('twilight'));
        $this->assertSame('fal-ai/nano-banana-pro/edit', $workspace->operation['routing']['twilight']['model']);
        $this->assertStringContainsString('bright early blue-hour', $workspace->config['prompt']);
        $this->assertStringContainsString('clearly readable', $workspace->config['prompt']);
        app(ListingStudioTrialPipeline::class)->authorize($workspace);
        $config = $workspace->config;
        $config['prompt'] = 'Replace this house';
        $workspace->update(['config' => $config]);
        $this->expectException(StudioProviderException::class);
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
    }

    public function test_accepted_legacy_route_is_not_rewritten_and_cannot_start_a_different_paid_step(): void
    {
        $trial = $this->trial('decluttering');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $operation = $workspace->operation;
        $operation['routing']['revision'] = ['provider' => 'fal', 'model' => 'fal-ai/flux-kontext/dev', 'fallback' => null];
        $operation['requests']['source'] = 'legacy-paid-request';
        $workspace->update(['operation' => $operation]);
        $same = app(ListingStudioTrialPipeline::class)->start($trial->fresh());
        $this->assertSame($operation, $same->operation);
        try {
            app(ListingStudioTrialPipeline::class)->assertMaySubmit($same);
            $this->fail('A legacy paid recipe must not start another step on a newly configured model.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_closed_service_can_retrieve_accepted_legacy_prompt_and_model_without_new_submission(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('decluttering'));
        $operation = $workspace->operation;
        $operation['routing']['revision'] = ['provider' => 'fal', 'model' => 'fal-ai/flux-kontext/dev', 'fallback' => null];
        $operation['payload']['prompt'] = 'Remove loose everyday clutter such as scattered clothing, papers, toys, trash and loose countertop items. Preserve all furniture, permanent fixtures, appliances, cabinetry, doors, windows, flooring, architectural details, room layout and camera perspective. Do not empty the room, add furniture or alter the property.';
        $operation['requests']['source'] = 'accepted-legacy-request';
        $workspace->update(['operation' => $operation]);
        config(['listing-studio-trials.services.decluttering.enabled' => false]);
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->once()->andReturn($this->jpeg());
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'queue.fal.run/')) {
                $this->assertStringContainsString('/accepted-legacy-request', $request->url());
                $this->assertStringNotContainsString('nano-banana', $request->url());

                return Http::response(['status' => 'COMPLETED', 'images' => [['url' => 'https://results.example.test/legacy.jpg']]]);
            }

            return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        });
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_staging_trial_saves_only_the_staged_photo_on_private_storage(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('staging'));
        config(['studio_providers.virtualstagingai.poll_interval' => 0]);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->assertSame(1, $request['variation_count']);
                $this->assertArrayNotHasKey('remove_furniture', $request['config']);
                $this->assertSame('living', $request['config']['add_furniture']['room_type']);

                return Http::response(['id' => 'render-one'], 201);
            }
            if (str_contains($request->url(), '/renders/')) {
                return Http::response(['id' => 'render-one', 'variations' => ['items' => [
                    ['id' => 'removed', 'type' => 'removal', 'status' => 'done', 'result' => ['url' => 'https://results.example.test/removed.jpg']],
                    ['id' => 'staged', 'type' => 'staging', 'status' => 'done', 'result' => ['url' => 'https://results.example.test/staged.jpg']],
                ], 'next_cursor' => null]]);
            }

            return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        });
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $outputs = $workspace->fresh()->outputs;
        $this->assertCount(1, $outputs);
        $this->assertSame('staging', $outputs[0]['vsai']['type']);
        $this->assertNull($outputs[0]['url']);
        Storage::disk('listing_studio_trials')->assertExists($outputs[0]['path']);
        Storage::disk('public')->assertMissing($outputs[0]['path']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/removed.jpg'));
    }

    public function test_disabled_trials_cannot_start_a_new_provider_submission(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        config(['listing-studio-trials.enabled' => false]);
        try {
            app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $this->fail('A disabled trial must not submit provider work.');
        } catch (StudioProviderException $exception) {
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public function test_service_gate_stops_a_queued_staging_submission_and_old_auto_removal_recipe(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('staging'));
        config(['listing-studio-trials.services.staging.enabled' => false]);
        try {
            app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $this->fail('Closed staging gate must prevent a paid submission.');
        } catch (StudioProviderException $exception) {
            $this->assertFalse($exception->ambiguous);
        }
        config(['listing-studio-trials.services.staging.enabled' => true]);
        $config = $workspace->config;
        $config['adjustments']['removal'] = 'auto';
        $workspace->update(['config' => $config]);
        try {
            app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $this->fail('Old automatic furniture removal must not start a paid trial submission.');
        } catch (StudioProviderException $exception) {
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public function test_closed_staging_gate_still_retrieves_an_already_accepted_render(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('staging'));
        $operation = $workspace->operation;
        $operation['providerState']['vsai-'.hash('sha256', 'source')] = [
            'renderId' => 'accepted-render', 'account' => hash('sha256', 'test-key'), 'awaiting' => true,
            'expected' => 1, 'knownIds' => [],
        ];
        $workspace->update(['operation' => $operation]);
        config(['listing-studio-trials.services.staging.enabled' => false, 'studio_providers.virtualstagingai.poll_interval' => 0]);
        Http::fake([
            'https://api.virtualstagingai.app/v2/renders/*' => Http::response(['id' => 'accepted-render', 'variations' => ['items' => [
                ['id' => 'staged', 'type' => 'staging', 'status' => 'done', 'result' => ['url' => 'https://results.example.test/staged.jpg']],
            ], 'next_cursor' => null]]),
            'https://results.example.test/staged.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_disabled_trials_can_retrieve_an_already_accepted_photo(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = [
            'imageId' => 'accepted-image', 'account' => hash('sha256', 'test-key'), 'uploaded' => true,
        ];
        $workspace->update(['operation' => $operation]);
        config(['listing-studio-trials.enabled' => false]);
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());

            return str_contains($request->url(), '/enhanced')
                ? Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])
                : Http::response(['status' => 'processed']);
        });
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
    }

    public function test_openai_trial_recovery_bytes_are_private_and_reused_without_another_edit(): void
    {
        $trial = $this->trial('decluttering');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $operation = $workspace->operation;
        $operation['routing']['revision'] = ['provider' => 'openai', 'model' => 'gpt-image-2'];
        config(['listing-studio-trials.services.decluttering.route' => $operation['routing']['revision']]);
        $workspace->update(['operation' => $operation]);
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->twice()->andReturn($this->jpeg());
        $this->mock(\App\Services\Studio\Providers\OpenAiImageProvider::class)->shouldReceive('edit')->once()->andReturn($this->jpeg());
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->twice();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $workspace->refresh();
        $cache = $workspace->operation['providerState']['openai-'.hash('sha256', 'source')]['path'];
        Storage::disk('listing_studio_trials')->assertExists($cache);
        Storage::disk('local')->assertMissing($cache);
        Storage::disk('public')->assertMissing($cache);

        $operation = $workspace->operation;
        $operation['completed'] = [];
        $workspace->update(['status' => 'failed', 'outputs' => [], 'operation' => $operation]);
        $trial->refresh()->update(['status' => 'needs_attention']);
        $workspace = app(ListingStudioTrialPipeline::class)->resume($trial);
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $this->assertSame('completed', $workspace->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_expiry_during_openai_processing_cannot_recreate_private_provider_files(): void
    {
        $trial = $this->trial('decluttering');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $operation = $workspace->operation;
        $operation['routing']['revision'] = ['provider' => 'openai', 'model' => 'gpt-image-2'];
        config(['listing-studio-trials.services.decluttering.route' => $operation['routing']['revision']]);
        $workspace->update(['operation' => $operation]);
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->once()->andReturn($this->jpeg());
        $this->mock(\App\Services\Studio\Providers\OpenAiImageProvider::class)->shouldReceive('edit')->once()->andReturnUsing(function () use ($trial) {
            $trial->update(['status' => 'expired']);

            return $this->jpeg();
        });
        try {
            app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $this->fail('An expired trial must not save a late provider response.');
        } catch (StudioProviderException) {
            $this->assertSame([], Storage::disk('listing_studio_trials')->allFiles('studio/workspaces/'.$workspace->id));
        }
    }

    public function test_guest_workspace_is_absent_from_staff_listing_and_cannot_use_generic_endpoints(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertNotFound();
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertNotFound();
    }

    public function test_worker_rejects_cross_trial_source_and_does_not_call_a_provider(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        $other = $this->trial('grass');
        $media = $workspace->media;
        $media[0]['mediaRef'] = $other->source_path;
        $workspace->update(['media' => $media]);
        $this->expectException(StudioProviderException::class);
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
    }

    public function test_expired_trial_does_not_authorize_reading_private_source(): void
    {
        $trial = $this->trial('grass');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $trial->update(['expires_at' => now()->subSecond()]);
        $this->expectException(StudioProviderException::class);
        app(WorkspaceMediaService::class)->bytes($workspace->media[0]);
    }

    public function test_ambiguous_failure_is_saved_for_trial_reconciliation(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('staging'));
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once()->withArgs(
            fn ($record) => $record->status === 'failed' && $record->operation['trialFailureAmbiguous'] === true
        );
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed(new StudioProviderException('Submission needs review.', true));
        $this->assertTrue($workspace->fresh()->operation['trialFailureAmbiguous']);
    }

    public function test_unknown_failure_after_accepted_submission_keeps_the_allowance_held(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = ['imageId' => 'already-paid'];
        $workspace->update(['operation' => $operation]);
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once()->withArgs(
            fn ($record) => $record->operation['trialFailureAmbiguous'] === true
        );
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed(new \RuntimeException('Timed out waiting for the accepted image.'));
    }

    public function test_changed_autoenhance_credentials_hold_the_existing_accepted_photo(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('grass'));
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = ['imageId' => 'already-paid', 'account' => hash('sha256', 'old-key')];
        $workspace->update(['operation' => $operation]);
        try {
            app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
            $this->fail('Restore the original account before resuming its accepted photo.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public function test_fal_access_rejection_does_not_release_an_existing_paid_request(): void
    {
        $workspace = app(ListingStudioTrialPipeline::class)->start($this->trial('decluttering'));
        $operation = $workspace->operation;
        $operation['requests']['source'] = 'accepted-fal-request';
        $workspace->update(['operation' => $operation]);
        $this->mock(ListingStudioGuestTrialService::class)->shouldReceive('syncWorkspace')->once()->withArgs(
            fn ($record) => $record->operation['trialFailureAmbiguous'] === true
        );
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed(new \App\Exceptions\FalTerminalException(401));
    }

    public function test_unconfirmed_queue_publication_holds_the_trial_instead_of_releasing_it(): void
    {
        $trial = $this->trial('grass');
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue response lost'));
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $this->assertSame('failed', $workspace->status);
        $this->assertTrue($workspace->operation['trialFailureAmbiguous']);
        $this->assertSame('needs_attention', $trial->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_recovery_reuses_the_same_accepted_operation_while_unconfirmed_submission_is_refused(): void
    {
        $trial = $this->trial('grass');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = ['imageId' => 'already-paid'];
        $workspace->update(['operation' => $operation, 'status' => 'failed']);
        $trial->update(['status' => 'needs_attention']);
        $resumed = app(ListingStudioTrialPipeline::class)->resume($trial->fresh());
        $this->assertSame($workspace->id, $resumed->id);
        $this->assertSame($operation['id'], $resumed->operation['id']);
        $this->assertSame('already-paid', $resumed->operation['providerState']['autoenhance-'.hash('sha256', 'source')]['imageId']);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);

        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = ['submitting' => true];
        $workspace->refresh();
        $trial->refresh();
        $workspace->update(['operation' => $operation, 'status' => 'failed']);
        $trial->update(['status' => 'needs_attention']);
        try {
            app(ListingStudioTrialPipeline::class)->resume($trial->fresh());
            $this->fail('Recovery must not repeat an unconfirmed paid submission.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
        Http::assertNothingSent();
    }

    public function test_stale_interrupted_worker_can_resume_only_after_its_execution_lock_is_free(): void
    {
        $trial = $this->trial('grass');
        $workspace = app(ListingStudioTrialPipeline::class)->start($trial);
        $operation = $workspace->operation;
        $operation['providerState']['autoenhance-'.hash('sha256', 'source')] = ['imageId' => 'already-paid'];
        $workspace->update(['operation' => $operation, 'updated_at' => now()->subHours(3)]);
        $trial->refresh()->update(['status' => 'needs_attention']);
        $job = new ProcessStudioWorkspace($workspace->id, $operation['id']);
        $lock = \Illuminate\Support\Facades\Cache::store('database')->lock($job->middleware()[0]->getLockKey($job), 60);
        $this->assertTrue($lock->get());
        try {
            app(ListingStudioTrialPipeline::class)->resume($trial);
            $this->fail('An active queue worker must retain exclusive execution.');
        } catch (StudioProviderException $exception) {
            $this->assertStringContainsString('still being processed', $exception->getMessage());
        } finally {
            $lock->release();
        }
        $resumed = app(ListingStudioTrialPipeline::class)->resume($trial);
        $this->assertSame($operation['id'], $resumed->operation['id']);
        $this->assertSame('generating', $resumed->status);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
    }

    private function trial(string $service): ListingStudioGuestTrial
    {
        $id = (string) Str::uuid();
        $path = 'trials/'.$id.'/source.jpg';
        Storage::disk('listing_studio_trials')->put($path, $this->jpeg());

        return ListingStudioGuestTrial::create(['id' => $id, 'session_hash' => hash('sha256', $id), 'ip_hash' => hash('sha256', 'ip'),
            'service' => $service, 'status' => 'queued', 'source_path' => $path, 'source_mime' => 'image/jpeg', 'source_name' => 'source.jpg',
            'options' => [], 'started_at' => now(), 'expires_at' => now()->addDay(), 'idempotency_key' => $id, 'request_hash' => hash('sha256', $id)]);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 45, 90, 130));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
