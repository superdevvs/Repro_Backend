<?php

namespace Tests\Feature;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabSection;
use App\Services\IguideLab\LabAnalyses;
use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabAnalysisSourceContext;
use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabImages;
use App\Services\IguideLab\LabSectionAnalysisViews;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class IguideLabSectionContextTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $visible;
    private IguideLabCase $native;
    private IguideLabCase $cut;
    private IguideLabSection $section;
    private string $token;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private'); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'section-context-'); file_put_contents($this->key, 'fixture-key');
        config(['iguide-lab.openai_primary_key_path' => $this->key, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->token = str_repeat('a', 64); $this->project = IguideLabProject::create(['name' => 'Context', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 120]);
        app(\App\Services\IguideLab\LabStorage::class)->prepareProject($this->project->id);
        $this->visible = $this->makeCase(2048, 1024); $this->native = $this->makeCase(4096, 2048); $this->cut = $this->makeCase(2048, 2048);
        $this->native->update(['metadata' => ['role' => 'native-source', 'parentCaseId' => $this->visible->id]]);
        $this->visible->update(['native_source' => ['caseId' => $this->native->id, 'requestId' => (string) Str::uuid(), 'sha256' => $this->native->source_sha256, 'width' => 4096, 'height' => 2048]]);
        $this->section = IguideLabSection::create(['project_id' => $this->project->id, 'case_id' => $this->native->id, 'derived_case_id' => $this->cut->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'source_sha256' => $this->native->source_sha256,
            'status' => 'completed', 'view' => ['yaw' => 0, 'pitch' => -10, 'hFov' => 80]]);
        $this->cut->update(['metadata' => ['role' => 'section-source', 'parentCaseId' => $this->native->id, 'sectionId' => $this->section->id]]);
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$this->project->id.'/sections/'.$this->section->id.'/geometry/cut/';
        $disk->put($base.'views/section.png', $disk->get($this->cut->source_path));
        $geometry = ['source' => ['path' => $disk->path($this->native->source_path), 'sha256' => $this->native->source_sha256, 'width' => 4096, 'height' => 2048],
            'views' => [['id' => 'section', 'path' => $disk->path($base.'views/section.png'), 'sha256' => $this->cut->source_sha256, 'width' => 2048, 'height' => 2048, 'yaw_degrees' => 0, 'pitch_degrees' => -10, 'hfov_degrees' => 80]]];
        $bytes = json_encode($geometry); $disk->put($base.'manifest.json', $bytes);
        $this->section->update(['data' => ['cutSha256' => $this->cut->source_sha256, 'geometryPath' => $base.'manifest.json', 'geometrySha256' => hash('sha256', $bytes)]]);
        $this->mock(LabAnalysisViews::class, fn ($mock) => $mock->shouldReceive('ready')->andReturn(true));
        $this->app->instance(LabSectionAnalysisViews::class, new class extends LabSectionAnalysisViews {
            protected function diagnostics(string $requestPath): array
            {
                $request = json_decode(file_get_contents($requestPath), true); $out = $request['outputDirectory']; @mkdir($out.'/views', 0770, true); $views = [];
                foreach (['front' => [0, 0], 'right' => [90, 0], 'back' => [180, 0], 'left' => [270, 0], 'zenith' => [0, 90], 'nadir' => [0, -90]] as $id => [$yaw, $pitch]) {
                    $bytes = (string) ImageManager::gd()->create(1024, 1024)->fill('dddddd')->toPng(); $path = $out.'/views/'.$id.'.png'; file_put_contents($path, $bytes);
                    $views[] = ['id' => $id, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'width' => 1024, 'height' => 1024, 'yaw_degrees' => $yaw, 'pitch_degrees' => $pitch, 'hfov_degrees' => 100];
                }
                return ['source' => $request['source'], 'views' => $views, 'adapterSha256' => hash_file('sha256', resource_path('iguide-lab/analysis_views.py')),
                    'geometrySha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py')), 'convention' => 'raw-image pixel-center coordinates'];
            }
        });
    }

    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }

    public function test_verified_cut_reserves_context_and_sends_six_ordered_bound_inputs_without_image_generation(): void
    {
        $input = $this->input(); $dto = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->json('data');
        $this->assertSame('queued', $dto['status']); $this->assertNull($dto['sourceContext']['manifestSha256']);
        $this->exportFixture('queued', $dto);
        $this->assertSame($this->native->id, $dto['sourceContext']['nativeCaseId']); $this->assertFalse($dto['sourceContext']['scopeExpansion']);
        $record = IguideLabAnalysis::findOrFail($dto['id']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(true), 200, ['x-request-id' => 'req-context'])]);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status, json_encode(['error' => $record->error, 'dataKeys' => array_keys($record->data), 'attempts' => $record->provider_attempts])); $this->assertSame(LabAnalysisSourceContext::IDS, array_column($record->data['views'], 'id'));
        $this->assertSame(hash('sha256', LabImages::prepared(Storage::disk(LabService::DISK)->get($this->cut->source_path))), $record->data['views'][0]['sha256']);
        Http::assertSent(function ($request) use ($record) {
            $content = $request['input'][0]['content']; $context = json_decode($content[0]['text'], true);
            return count(array_filter($content, fn ($item) => $item['type'] === 'input_image')) === 6
                && $context['sourceSha256'] === $this->cut->source_sha256 && $context['sourceContext']['binding']['nativeSourceSha256'] === $this->native->source_sha256
                && $context['sourceContext']['views'] === $record->data['sourceContextManifest']['views']
                && str_contains($content[3]['text'], 'context only') && str_contains($request['instructions'], 'choose manual_review');
        });
        $this->withToken($this->token)->getJson('/api/iguide-lab/analyses/'.$record->id)->assertOk()->assertJsonPath('data.sourceContext.manifestSha256', $record->data['sourceContextManifestSha256']);
        $this->withToken($this->token)->get('/api/iguide-lab/analyses/'.$record->id.'/views/parent_left')->assertOk();
        $this->assertSame(0, $this->project->fresh()->used_runs); Http::assertSentCount(1);
        Queue::assertNotPushed(\App\Jobs\ProcessIguideLabRun::class);
        $this->assertStringNotContainsString('/app/', json_encode(app(LabAnalyses::class)->dto($record)));
        $this->exportFixture('completed', app(LabAnalyses::class)->dto($record));
        $this->exportFixture('capabilities', $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->json('data.capabilities'));
        $this->exportFixture('wireSchema', app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::SECTION_CONTEXT)['recipe']['schema']);
    }

    public function test_invalid_parent_backlink_or_foreign_parent_stops_before_analysis_reservation(): void
    {
        $this->visible->update(['native_source' => null]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->assertUnprocessable();
        $this->assertSame(0, IguideLabAnalysis::count()); Http::assertNothingSent();
        $other = IguideLabProject::create(['name' => 'Other', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);
        $this->native->update(['project_id' => $other->id]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->assertNotFound();
        $this->assertSame(0, IguideLabAnalysis::count());
    }

    public function test_changed_native_cut_geometry_or_pose_stops_before_any_paid_request(): void
    {
        foreach (['native', 'cut', 'geometry', 'pose'] as $which) {
            $record = $this->reserve(); $disk = Storage::disk(LabService::DISK);
            $path = match ($which) { 'native' => $this->native->source_path, 'cut' => $this->cut->source_path, default => $this->section->data['geometryPath'] };
            $original = $disk->get($path); $view = $this->section->view;
            if ($which === 'pose') $this->section->update(['view' => ['yaw' => 5, 'pitch' => -10, 'hFov' => 80]]); else $disk->put($path, $original.'changed');
            app(LabAnalyses::class)->process($record);
            $this->assertSame('needs_attention', $record->fresh()->status); $this->assertEmpty($record->fresh()->provider_attempts);
            $disk->put($path, $original); $this->section->update(['view' => $view]);
        }
        Http::assertNothingSent();
    }

    public function test_generated_or_unattached_parent_metadata_does_not_create_wider_context(): void
    {
        $this->native->update(['metadata' => ['role' => 'generated-reference', 'parentCaseId' => $this->visible->id]]);
        $record = $this->reserve(); $this->assertArrayNotHasKey('sourceContext', $record->data);
        $this->assertSame(LabAnalysisRecipes::LEGACY_VIEW_RECIPE, $record->settings['viewRecipe']);
        $this->assertNull(app(LabAnalyses::class)->dto($record)['sourceContext']); Http::assertNothingSent();
    }

    public function test_existing_v1_queued_record_and_replay_remain_single_cut_after_upgrade(): void
    {
        $input = $this->input(); $settings = ['preset' => 'lifestyle', 'style' => null, 'instructions' => $input['instructions'], 'options' => LabAnalyses::EDIT_DEFAULTS,
            'protocolVersion' => LabAnalysisRecipes::LEGACY_PROTOCOL, 'viewRecipe' => LabAnalysisRecipes::LEGACY_VIEW_RECIPE];
        $old = IguideLabAnalysis::create(['project_id' => $this->project->id, 'case_id' => $this->cut->id, 'request_id' => $input['requestId'],
            'request_hash' => hash('sha256', json_encode([$this->cut->id, $this->cut->source_sha256, 'rectilinear', $settings], JSON_THROW_ON_ERROR)),
            'source_sha256' => $this->cut->source_sha256, 'settings' => $settings, 'status' => 'queued',
            'data' => ['executionRecipe' => app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::CANDIDATE), 'recipeCaptureMode' => 'reservation_snapshot']]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $input)->assertAccepted()->assertJsonPath('data.id', $old->id)->assertJsonPath('data.sourceContext', null);
        $this->app->forgetInstance(LabAnalysisViews::class); // Real v1 preparation only reads the rectilinear cut; no Python required.
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(false))]);
        app(LabAnalyses::class)->process($old);
        $this->assertSame('completed', $old->fresh()->status); $this->assertSame(['source'], array_column($old->fresh()->data['views'], 'id'));
        Http::assertSent(fn ($request) => count($request['input'][0]['content']) === 3 && ! str_contains($request['instructions'], 'Section context authority:'));
        Http::assertSentCount(1);
        $this->exportFixture('legacyCompleted', app(LabAnalyses::class)->dto($old->fresh()));
    }

    public function test_context_replay_and_applied_draft_remain_bound_to_same_source_and_manifest(): void
    {
        $input = $this->input(); $record = app(LabAnalyses::class)->start($this->project, $input); $requestHash = $record->request_hash;
        $replay = app(LabAnalyses::class)->start($this->project, $input); $this->assertSame($record->id, $replay->id); $this->assertSame($requestHash, $replay->request_hash);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(true))]); app(LabAnalyses::class)->process($record); $record->refresh();
        $draft = app(LabAnalyses::class)->appliedDraft($this->project->id, $record->id, $this->cut, 'lifestyle', null, [], $record->proposal['generationPrompt']);
        $this->assertSame($record->data['sourceContext']['bindingSha256'], $draft['sourceContextBindingSha256']);
        $this->assertSame($record->data['sourceContextManifestSha256'], $draft['sourceContextManifestSha256']);
        $this->exportFixture('sourceAnalysis', $draft);
        $data = $record->data; $data['sourceContextManifest']['views'][1]['sha256'] = str_repeat('f', 64); $record->update(['data' => $data]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(LabAnalyses::class)->appliedDraft($this->project->id, $record->id, $this->cut, 'lifestyle', null, [], $record->proposal['generationPrompt']);
    }

    public function test_apply_rejects_cached_context_file_drift_or_foreign_stored_path(): void
    {
        $record = $this->reserve(); Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(true))]);
        app(LabAnalyses::class)->process($record); $record->refresh(); $this->assertSame('completed', $record->status);
        $disk = Storage::disk(LabService::DISK); $saved = $record->data; $path = $saved['views'][2]['path']; $bytes = $disk->get($path);
        foreach (['bytes', 'path'] as $change) {
            if ($change === 'bytes') $disk->put($path, $bytes.'changed');
            else { $data = $saved; $data['views'][2]['path'] = 'iguide-lab/'.Str::uuid().'/analyses/'.$record->id.'/parent_front.png'; $record->update(['data' => $data]); }
            try { app(LabAnalyses::class)->appliedDraft($this->project->id, $record->id, $this->cut, 'lifestyle', null, [], $record->proposal['generationPrompt']); $this->fail('Changed context was applied.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
            $disk->put($path, $bytes); $record->update(['data' => $saved]);
        }
        Http::assertSentCount(1); $this->assertSame(0, $this->project->fresh()->used_runs);
    }

    public function test_context_views_remain_capability_private_and_cannot_read_foreign_paths(): void
    {
        $record = $this->reserve(); Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(true))]);
        app(LabAnalyses::class)->process($record); $record->refresh(); $this->assertSame('completed', $record->status);
        $url = '/api/iguide-lab/analyses/'.$record->id.'/views/parent_left';
        $this->withHeader('Authorization', '')->get($url)->assertUnauthorized();
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay()]);
        $this->withToken($other)->get($url)->assertNotFound(); $this->withToken($this->token)->get($url)->assertOk();
        $this->withToken($this->token)->get('/api/iguide-lab/analyses/'.$record->id.'/views/parent_zenith')->assertNotFound();
        $data = $record->data; $data['views'][5]['path'] = $this->native->source_path; $record->update(['data' => $data]);
        $this->withToken($this->token)->get($url)->assertNotFound();
    }

    public function test_completed_import_is_explicit_native_context_and_keeps_same_request_replay(): void
    {
        $import = $this->convertNativeToImport(); $input = $this->input();
        $record = app(LabAnalyses::class)->start($this->project, $input);
        $binding = $record->data['sourceContext']['binding'];
        $this->assertSame('completed_scan_import', $binding['sourceOrigin']);
        $this->assertSame($this->native->id, $binding['visibleParentCaseId']);
        $this->assertSame(['version' => LabAnalysisSourceContext::IMPORT_AUTHORITY, 'importId' => $import->id,
            'importSourceKey' => 'pano-fixture', 'mappingSha256' => LabAnalysisRecipes::hash($import->sources[0])], $binding['sourceAuthority']);
        $this->assertNull($this->native->native_source);
        $this->assertSame($record->id, app(LabAnalyses::class)->start($this->project, $input)->id);
        $this->exportFixture('importedQueued', app(LabAnalyses::class)->dto($record));
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(true))]);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status, $record->error ?? '');
        $this->assertSame(LabAnalysisSourceContext::IDS, array_column($record->data['views'], 'id'));
        Http::assertSent(fn ($request) => json_decode($request['input'][0]['content'][0]['text'], true)['sourceContext']['binding'] === $binding);
        $draft = app(LabAnalyses::class)->appliedDraft($this->project->id, $record->id, $this->cut, 'lifestyle', null, [], $record->proposal['generationPrompt']);
        $this->assertSame($record->data['sourceContext']['bindingSha256'], $draft['sourceContextBindingSha256']);
        $this->exportFixture('importedCompleted', app(LabAnalyses::class)->dto($record));
        $changed = $import->sources; $changed[0]['importSourceKey'] = 'different-source'; $import->update(['sources' => $changed]);
        try { app(LabAnalyses::class)->appliedDraft($this->project->id, $record->id, $this->cut, 'lifestyle', null, [], $record->proposal['generationPrompt']); $this->fail('Changed import authority was applied.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        Http::assertSentCount(1); $this->assertSame(0, $this->project->fresh()->used_runs);
    }

    public function test_missing_foreign_incomplete_or_mismatched_import_authority_is_not_a_metadata_claim(): void
    {
        $import = $this->convertNativeToImport(); $mapping = $import->sources;
        $foreign = IguideLabProject::create(['name' => 'Foreign', 'token_hash' => str_repeat('f', 64), 'expires_at' => now()->addDay()]);
        $mutations = [
            ['status' => 'importing'], ['project_id' => $foreign->id], ['sources' => []],
            ['sources' => [array_replace($mapping[0], ['sourceSha256' => str_repeat('e', 64)])]],
            ['sources' => [array_replace($mapping[0], ['width' => 2048])]], ['sources' => [$mapping[0], $mapping[0]]],
        ];
        foreach ($mutations as $change) {
            $import->update($change);
            $status = $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->status();
            $this->assertContains($status, [404, 422]);
            $import->update(['status' => 'completed', 'project_id' => $this->project->id, 'sources' => $mapping]);
        }
        $this->native->update(['metadata' => ['role' => 'imported-panorama', 'importId' => (string) Str::uuid()]]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/analyses', $this->input())->assertUnprocessable();
        $this->assertSame(0, IguideLabAnalysis::count()); Http::assertNothingSent();
    }

    public function test_import_mapping_drift_after_reservation_stops_before_provider(): void
    {
        $import = $this->convertNativeToImport(); $record = $this->reserve();
        $mapping = $import->sources; $mapping[0]['importSourceKey'] = 'changed-key'; $import->update(['sources' => $mapping]);
        app(LabAnalyses::class)->process($record); $record->refresh();
        $this->assertSame('needs_attention', $record->status); $this->assertEmpty($record->provider_attempts); Http::assertNothingSent();
    }

    private function convertNativeToImport(): IguideLabImport
    {
        $id = (string) Str::uuid(); $disk = Storage::disk(LabService::DISK);
        $path = 'iguide-lab/'.$this->project->id.'/imports/'.$id.'/'.$this->native->id.'.png';
        $disk->put($path, $disk->get($this->native->source_path));
        $this->native->update(['source_path' => $path, 'metadata' => ['role' => 'imported-panorama', 'importId' => $id]]);
        $geometryPath = $this->section->data['geometryPath']; $geometry = json_decode($disk->get($geometryPath), true);
        $geometry['source']['path'] = $disk->path($path); $bytes = json_encode($geometry); $disk->put($geometryPath, $bytes);
        $this->section->update(['data' => array_replace($this->section->data, ['geometrySha256' => hash('sha256', $bytes)])]);
        return IguideLabImport::create(['id' => $id, 'project_id' => $this->project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('d', 64), 'source_kind' => 'zip', 'private_input' => [], 'status' => 'completed',
            'sources' => [['caseId' => $this->native->id, 'sourceSha256' => $this->native->source_sha256, 'width' => 4096, 'height' => 2048, 'importSourceKey' => 'pano-fixture']]]);
    }

    private function exportFixture(string $name, array $value): void
    {
        $dir = getenv('IGUIDE_SECTION_CONTEXT_FIXTURE_DIRECTORY');
        if ($dir === false || $dir === '') return;
        if (! is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents($dir.'/'.$name.'.json', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function input(): array { return ['requestId' => (string) Str::uuid(), 'caseId' => $this->cut->id, 'preset' => 'lifestyle', 'style' => null, 'instructions' => 'Add one adult holding a closed book; preserve all existing furniture.']; }
    private function reserve(): IguideLabAnalysis { return app(LabAnalyses::class)->start($this->project, $this->input()); }
    private function makeCase(int $width, int $height): IguideLabCase
    {
        $id = (string) Str::uuid(); $path = 'iguide-lab/'.$this->project->id.'/cases/'.$id.'.png'; $png = (string) ImageManager::gd()->create($width, $height)->fill('888888')->toPng();
        Storage::disk(LabService::DISK)->put($path, $png);
        return IguideLabCase::create(['id' => $id, 'project_id' => $this->project->id, 'label' => 'Source', 'projection' => $width === $height ? 'rectilinear' : 'equirectangular',
            'width' => $width, 'height' => $height, 'source_sha256' => hash('sha256', $png), 'source_path' => $path, 'source_mime' => 'image/png', 'metadata' => []]);
    }
    private function response(bool $context): array
    {
        $proposal = ['schemaVersion' => 'scene-plan-v1', 'sourceSha256' => $this->cut->source_sha256, 'projection' => 'rectilinear', 'preset' => 'lifestyle', 'style' => null,
            'summary' => 'One adult beside the table; wider glazing needs a separate identity-bound review.',
            'protectedFeatures' => [['id' => 'glazing', 'description' => 'Preserve the glazing and its existing lights.', 'evidenceViewIds' => [$context ? 'parent_left' : 'source'], 'confidence' => 'medium']],
            'targets' => [['id' => 'person', 'description' => 'One fictional adult with a closed book.', 'action' => 'add', 'objectCount' => 1, 'evidenceViewIds' => ['source']]],
            'sourceArtifacts' => [], 'uncertainties' => ['A later reflection outside this cut requires manual review against the generated adult identity.'],
            'workflow' => ['mode' => 'manual_review', 'reason' => 'The wider glazing is outside the editing cut.', 'sections' => []],
            'generationPrompt' => 'Add one fictional adult holding a closed book within this exact square perspective source. Preserve all existing furniture, lights and camera framing.',
            'reviewChecks' => array_map(fn ($check) => ['check' => $check, 'instruction' => 'Review '.$check.' manually before accepting.'], ['edit', 'geometry', 'seam', 'appearance'])];
        return ['status' => 'completed', 'model' => 'gpt-6-astra', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($proposal)]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]];
    }
}
