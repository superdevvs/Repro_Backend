<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabAnalysis;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Services\IguideLab\LabAnalyses;
use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabImages;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabAnalysisTest extends TestCase
{
    use RefreshDatabase;
    private string $token;
    private string $key;
    private IguideLabProject $project;
    private IguideLabCase $case;
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = tempnam(sys_get_temp_dir(), 'analysis-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private'); Queue::fake(); Http::preventStrayRequests();
        $this->token = str_repeat('a', 64); $this->project = IguideLabProject::create(['name' => 'Analysis', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $this->png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $this->case = app(LabService::class)->addCase($this->project, UploadedFile::fake()->createWithContent('source.png', $this->png), ['label' => 'Kitchen', 'projection' => 'equirectangular', 'metadata' => []]);
        $this->mock(LabAnalysisViews::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('prepare')->andReturnUsing(function ($analysis, $case) {
                app(LabStorage::class)->prepareAnalysis($analysis->project_id, $analysis->id);
                $path = 'iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/source.png';
                $png = LabImages::prepared(Storage::disk(LabService::DISK)->get($case->source_path));
                Storage::disk(LabService::DISK)->put($path, $png, ['visibility' => 'private']);
                return ['preparedSourceSha256' => hash('sha256', $png), 'views' => [['id' => 'source', 'path' => $path, 'sha256' => hash('sha256', $png), 'width' => 2048, 'height' => 1024, 'projection' => 'equirectangular']], 'diagnosticOnly' => true];
            });
        });
    }

    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }

    public function test_analysis_reservation_is_scoped_idempotent_and_does_not_reserve_an_image(): void
    {
        $input = $this->input();
        $this->withHeader('Authorization', '')->postJson('/api/iguide-lab/analyses', $input)->assertUnauthorized();
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', [...$input, 'instructions' => 'Different'])->assertConflict();
        $this->assertSame(1, IguideLabAnalysis::count()); $this->assertSame(0, $this->project->fresh()->used_runs); $this->assertSame(0, IguideLabRun::count());
        Queue::assertPushed(ProcessIguideLabAnalysis::class, 1); Queue::assertNotPushed(ProcessIguideLabRun::class);
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->getJson('/api/iguide-lab/analyses/'.$first['id'])->assertNotFound();
        $this->withToken($other)->postJson('/api/iguide-lab/analyses', [...$input, 'requestId' => (string) Str::uuid()])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_primary_analysis_returns_only_compact_proposal_retains_private_response_and_never_generates_image(): void
    {
        $record = $this->reserve(); $response = $this->response();
        Http::fake(['api.openai.com/v1/responses' => Http::response($response, 200, ['x-request-id' => 'req-analysis'])]);
        app(LabAnalyses::class)->process($record); app(LabAnalyses::class)->process($record->fresh());
        $dto = $this->withToken($this->token)->getJson('/api/iguide-lab/analyses/'.$record->id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.automaticGeneration', false)->json('data');
        $this->assertSame($response['usage'], $dto['usage']); $this->assertNull($dto['estimatedCostUsd']);
        $body = json_encode($dto); foreach (['PRIVATE_REASONING', 'fixture-primary', $this->key, 'source_path', '/app/storage'] as $secret) $this->assertStringNotContainsString($secret, $body);
        $private = Storage::disk(LabService::DISK)->get('iguide-lab/'.$this->project->id.'/analyses/'.$record->id.'/response-0.json');
        $this->assertStringContainsString('PRIVATE_REASONING', $private);
        $this->withToken($this->token)->get($dto['views'][0]['assetPath'])->assertOk();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fixture-primary') && $request['model'] === 'gpt-6-astra' && $request['store'] === false && ! isset($request['tools']) && $request['text']['format']['strict'] === true);
        Http::assertSentCount(1); Queue::assertNotPushed(ProcessIguideLabRun::class); $this->assertSame(0, $this->project->fresh()->used_runs);
    }

    public function test_explicit_quota_rejection_uses_one_fallback_but_transient_or_ambiguous_errors_do_not(): void
    {
        $record = $this->reserve();
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429, ['x-request-id' => 'req-quota'])->push($this->response(), 200, ['x-request-id' => 'req-fallback']);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($record->provider_attempts, 'credentialSource'));
        $requests = Http::recorded(); $this->assertSame($requests[0][0]->data(), $requests[1][0]->data()); Http::assertSentCount(2);
        $next = $this->reserve(); $count = 0;
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function () use (&$count) { $count++; throw new \Illuminate\Http\Client\ConnectionException('lost response'); });
        app(LabAnalyses::class)->process($next); app(LabAnalyses::class)->process($next->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $next->fresh()->status); $this->assertSame('unknown', $next->fresh()->provider_attempts[0]['status']);
    }

    public function test_nonquota_rejections_and_incomplete_or_invalid_outputs_never_replay(): void
    {
        $incomplete = $this->response(); $incomplete['status'] = 'incomplete';
        $wrong = $this->response(); $proposal = $this->proposal(); $proposal['sourceSha256'] = str_repeat('f', 64); $wrong['output'][1]['content'][0]['text'] = json_encode($proposal);
        foreach ([[['error' => ['code' => 'rate_limit_exceeded', 'type' => 'insufficient_quota']], 429, 'failed'],
            [['error' => ['code' => 'invalid_api_key']], 401, 'failed'], [['error' => ['code' => 'server_error']], 500, 'needs_attention'],
            [$incomplete, 200, 'needs_attention'], [$wrong, 200, 'needs_attention']] as [$body, $status, $expected]) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            $record = $this->reserve(); Http::fake(['api.openai.com/v1/responses' => Http::response($body, $status)]);
            app(LabAnalyses::class)->process($record); app(LabAnalyses::class)->process($record->fresh());
            $this->assertSame($expected, $record->fresh()->status); $this->assertCount(1, $record->fresh()->provider_attempts); Http::assertSentCount(1);
            $this->assertTrue(Storage::disk(LabService::DISK)->exists('iguide-lab/'.$this->project->id.'/analyses/'.$record->id.'/response-0.json'));
        }
    }

    public function test_submitting_checkpoint_and_changed_source_stop_without_any_provider_request(): void
    {
        $record = $this->reserve(); $record->update(['status' => 'submitting']); app(LabAnalyses::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status);
        $next = $this->reserve(); $this->case->update(['source_sha256' => str_repeat('f', 64)]); app(LabAnalyses::class)->process($next);
        $this->assertSame('needs_attention', $next->fresh()->status); Http::assertNothingSent();
    }

    public function test_explicit_applied_draft_is_exact_and_stale_semantic_settings_are_rejected_before_image_charge(): void
    {
        $record = $this->reserve(); Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]); app(LabAnalyses::class)->process($record); $record->refresh();
        $input = ['requestId' => (string) Str::uuid(), 'caseId' => $this->case->id, 'modelId' => 'gpt-image-2.6-sunburst', 'preset' => 'declutter',
            'prompt' => $record->proposal['generationPrompt'], 'options' => ['analysisId' => $record->id, 'promptMode' => 'scene-plan']];
        $run = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
        $this->assertSame($input['prompt'], $run['prompt']); $this->assertStringNotContainsString('Remove loose clutter', $run['prompt']);
        $this->assertSame(4, $run['options']['recipeVersion']); $this->assertFalse($run['options']['sourceAnalysis']['editedFromProposal']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $run['id']);
        $changed = $input; $changed['requestId'] = (string) Str::uuid(); $changed['options']['preserveFixedFinishes'] = false;
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $changed)->assertUnprocessable(); $this->assertSame(1, $this->project->fresh()->used_runs);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]])]);
        app(LabService::class)->process(IguideLabRun::find($run['id']));
        Http::assertSent(fn ($request) => collect($request->data())->keyBy('name')['prompt']['contents'] === $input['prompt']);
        $next = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'requestId' => (string) Str::uuid()])->assertAccepted()->json('data');
        Storage::disk(LabService::DISK)->put($this->case->source_path, 'changed bytes');
        Http::fake(); app(LabService::class)->process(IguideLabRun::find($next['id']));
        $this->assertSame('needs_attention', IguideLabRun::find($next['id'])->status); Http::assertNothingSent();
    }

    public function test_analysis_safety_ceiling_is_independent_of_image_allowance(): void
    {
        $this->project->update(['max_runs' => 0]); $record = $this->reserve(); $this->assertSame('queued', $record->status);
        $rows = [];
        for ($i = 0; $i < 119; $i++) $rows[] = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'case_id' => $this->case->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'source_sha256' => $this->case->source_sha256,
            'settings' => '{}', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()];
        DB::table('iguide_lab_analyses')->insert($rows);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->assertStatus(429); Http::assertNothingSent();
    }

    public function test_controller_rejects_unknown_semantics_and_normalizes_default_staging_style(): void
    {
        $input = [...$this->input(), 'preset' => 'staging', 'options' => ['furnitureDensity' => 'light']];
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->json('data');
        $this->assertSame('modern', $first['settings']['style']); $this->assertSame('light', $first['settings']['options']['furnitureDensity']);
        $this->assertSame(true, $first['settings']['options']['preserveArchitecture']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', [...$input, 'style' => 'modern'])->assertAccepted()->assertJsonPath('data.id', $first['id']);
        foreach ([['unknownTopLevel' => true], ['options' => ['unknownControl' => true]], ['style' => 'coastal'], ['options' => ['declutterMode' => 'selected-object']],
            ['options' => ['allowFinishChanges' => true]], ['options' => ['preserveArchitecture' => 'maybe']]] as $invalid) {
            $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', array_replace($this->input(), $invalid))->assertUnprocessable();
        }
        $this->assertSame(1, IguideLabAnalysis::count()); Http::assertNothingSent();
    }

    public function test_actual_queued_source_file_hash_is_checked_before_any_preparation_or_paid_request(): void
    {
        $record = $this->reserve();
        $this->app->instance(LabAnalysisViews::class, new LabAnalysisViews());
        Storage::disk(LabService::DISK)->put($this->case->source_path, 'untrusted changed bytes');
        app(LabAnalyses::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); $this->assertNull($record->fresh()->provider_attempts);
        Http::assertNothingSent(); Queue::assertNotPushed(ProcessIguideLabRun::class);
    }

    public function test_reserved_recipe_and_idempotent_replay_survive_an_active_default_change(): void
    {
        $input = $this->input();
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->json('data.id');
        $record = IguideLabAnalysis::findOrFail($id); $snapshot = $record->data['executionRecipe']; $hash = $record->request_hash;
        $this->assertSame('f25a88708519f3f704ca957787d8b815358780be8c669f7bcb4a574fc822d514', $snapshot['instructionsSha256']);
        config(['iguide-lab.analysis_instructions_version' => 'future-default-not-installed']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', [...$input, 'instructions' => 'Different target'])->assertConflict();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame($snapshot, $record->data['executionRecipe']); $this->assertSame($hash, $record->request_hash);
        $request = Http::recorded()[0][0]->data();
        $this->assertSame($snapshot['recipe']['instructions'], $request['instructions']);
        $this->assertSame($snapshot['recipe']['schema'], $request['text']['format']['schema']);
        $this->assertSame(\App\Services\IguideLab\LabAnalysisRecipes::hash($request), $record->data['providerBodySha256']);
        $manifest = json_decode(Storage::disk(LabService::DISK)->get('iguide-lab/'.$this->project->id.'/analyses/'.$id.'/input-manifest.json'), true);
        $this->assertSame($record->data['providerBodySha256'], $manifest['providerBodySha256']);
        $this->assertSame(1, IguideLabAnalysis::count()); Queue::assertPushed(ProcessIguideLabAnalysis::class, 1); Http::assertSentCount(1);
    }

    public function test_changed_recipe_bytes_controls_or_schema_fail_before_a_paid_attempt(): void
    {
        foreach (['instructions', 'model', 'schema'] as $field) {
            $record = $this->reserve(); $data = $record->data;
            $data['executionRecipe']['recipe'][$field] = $field === 'schema' ? ['type' => 'string'] : 'modified';
            // Even a recomputed self-hash cannot make an unregistered recipe legitimate.
            $data['executionRecipe']['recipeSha256'] = \App\Services\IguideLab\LabAnalysisRecipes::hash($data['executionRecipe']['recipe']);
            $record->update(['data' => $data]); app(LabAnalyses::class)->process($record);
            $this->assertSame('needs_attention', $record->fresh()->status); $this->assertNull($record->fresh()->provider_attempts);
            $this->assertNull(app(LabAnalyses::class)->dto($record->fresh())['instructionProvenance']);
        }
        Http::assertNothingSent(); Queue::assertNotPushed(ProcessIguideLabRun::class);
    }

    public function test_legacy_recipe_resolution_is_explicit_and_keeps_original_request_hash(): void
    {
        $input = $this->input();
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->json('data.id');
        $record = IguideLabAnalysis::findOrFail($id); $hash = $record->request_hash;
        $record->update(['data' => ['originalSourceSha256' => null]]);
        $this->assertNull(app(LabAnalyses::class)->dto($record)['instructionProvenance']);
        config(['iguide-lab.analysis_instructions_version' => 'future-default-not-installed']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->assertJsonPath('data.id', $id);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame($hash, $record->request_hash);
        $this->assertSame('legacy_registry_resolution', app(LabAnalyses::class)->dto($record)['instructionProvenance']['captureMode']);
        $this->assertSame('scene-plan-v1', app(LabAnalyses::class)->dto($record)['protocolVersion']);
        Http::assertSentCount(1);
    }

    public function test_removed_marked_snapshot_is_not_silently_treated_as_legacy(): void
    {
        $record = $this->reserve(); $data = $record->data; unset($data['executionRecipe']);
        $record->update(['data' => $data]); app(LabAnalyses::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); $this->assertNull($record->fresh()->provider_attempts);
        Http::assertNothingSent();
    }

    private function input(): array { return ['requestId' => (string) Str::uuid(), 'caseId' => $this->case->id, 'preset' => 'declutter', 'instructions' => 'Remove the vase only.']; }
    private function reserve(): IguideLabAnalysis { return IguideLabAnalysis::find($this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->assertAccepted()->json('data.id')); }
    private function proposal(): array
    {
        return ['schemaVersion' => 'scene-plan-v1', 'sourceSha256' => $this->case->source_sha256, 'projection' => 'equirectangular', 'preset' => 'declutter', 'style' => null,
            'summary' => 'Remove the kitchen vase.', 'protectedFeatures' => [['id' => 'counter', 'description' => 'Existing counter and appliances', 'evidenceViewIds' => ['source'], 'confidence' => 'high']],
            'targets' => [['id' => 'vase', 'description' => 'Vase', 'action' => 'remove', 'objectCount' => 1, 'evidenceViewIds' => ['source']]], 'sourceArtifacts' => [], 'uncertainties' => [],
            'workflow' => ['mode' => 'whole_panorama', 'reason' => 'Keep exact full-source framing.', 'sections' => []],
            'generationPrompt' => 'Remove only the visible vase. Preserve the exact panorama, architecture, appliances and every other object.',
            'reviewChecks' => array_map(fn ($check) => ['check' => $check, 'instruction' => 'Inspect the matching source and output.'], ['edit', 'geometry', 'seam', 'appearance'])];
    }
    private function response(): array
    {
        return ['status' => 'completed', 'model' => 'gpt-6-astra', 'usage' => ['input_tokens' => 1234, 'output_tokens' => 567],
            'output' => [['type' => 'reasoning', 'summary' => [['text' => 'PRIVATE_REASONING']]],
                ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($this->proposal())]]]]];
    }
}
