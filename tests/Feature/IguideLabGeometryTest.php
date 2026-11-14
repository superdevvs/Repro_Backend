<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabComposition;
use App\Jobs\ProcessIguideLabRun;
use App\Jobs\ProcessIguideLabSection;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposition;
use App\Models\IguideLabMask;
use App\Models\IguideLabPipeline;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabSection;
use App\Services\IguideLab\LabCompositions;
use App\Services\IguideLab\LabSectionGeometry;
use App\Services\IguideLab\LabSections;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabGeometryTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $source;
    private string $token;
    private string $key;
    private string $panorama;
    private string $cut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = tempnam(sys_get_temp_dir(), 'geometry-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'iguide-lab.openai_primary_key_path' => $this->key]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private'); Queue::fake(); Http::preventStrayRequests();
        $this->token = str_repeat('a', 64); $this->project = IguideLabProject::create(['name' => 'Geometry', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 50]);
        $this->panorama = $this->png(2048, 1024); $this->cut = $this->png(2048, 2048);
        $this->source = app(LabService::class)->addCase($this->project, UploadedFile::fake()->createWithContent('source.png', $this->panorama), ['label' => 'Native source', 'projection' => 'equirectangular']);
        $this->mock(LabSectionGeometry::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('run')->andReturnUsing(fn ($path) => $this->geometryFixture($path));
        });
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }

    public function test_native_attachment_binds_actual_file_and_replay_to_known_original(): void
    {
        $native = $this->png(4096, 2048); $hash = hash('sha256', $native);
        $this->source->update(['metadata' => ['originalSha256' => $hash, 'originalDimensions' => [4096, 2048]]]);
        $url = '/api/iguide-lab/cases/'.$this->source->id.'/native-source'; $id = (string) Str::uuid();
        $wrong = ['requestId' => $id, 'image' => UploadedFile::fake()->createWithContent('wrong.png', $this->panorama)];
        $this->withToken($this->token)->post($url, $wrong, ['Accept' => 'application/json'])->assertUnprocessable();
        $first = $this->withToken($this->token)->post($url, ['requestId' => $id, 'image' => UploadedFile::fake()->createWithContent('native.png', $native)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame($hash, $first['case']['sourceSha256']); $this->assertSame(4096, $first['case']['width']);
        $this->withToken($this->token)->post($url, ['requestId' => $id, 'image' => UploadedFile::fake()->createWithContent('native.png', $native)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.case.id', $first['case']['id']);
        $this->withToken($this->token)->post($url, ['requestId' => (string) Str::uuid(), 'image' => UploadedFile::fake()->createWithContent('native.png', $native)], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame(2, IguideLabCase::count()); $this->assertSame(hash('sha256', $this->panorama), $this->source->fresh()->source_sha256);
        $this->assertSame($first['case']['id'], $this->source->fresh()->native_source['caseId']); Http::assertNothingSent();
    }

    public function test_extraction_is_scoped_idempotent_and_recovers_the_same_derived_case(): void
    {
        $input = $this->sectionInput();
        $this->withHeader('Authorization', '')->postJson('/api/iguide-lab/sections', $input)->assertUnauthorized();
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/sections', $input)->assertAccepted()->json('data');
        $this->assertNull($first['derivedCaseId']); $this->assertNull($first['sourceDimensions']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/sections', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/sections', [...$input, 'view' => ['yaw' => 1, 'pitch' => -10, 'hFov' => 90]])->assertConflict();
        foreach ([['bogus' => true], ['view' => ['yaw' => 0, 'pitch' => -10, 'hFov' => 140]], ['view' => ['yaw' => 0, 'pitch' => -10, 'hFov' => 90, 'unknown' => 1]]] as $invalid)
            $this->withToken($this->token)->postJson('/api/iguide-lab/sections', array_replace($input, $invalid))->assertUnprocessable();
        $section = IguideLabSection::find($first['id']); app(LabSections::class)->process($section); $caseId = $section->fresh()->derived_case_id;
        $section->update(['status' => 'extracting', 'derived_case_id' => null, 'data' => null]); app(LabSections::class)->process($section);
        $this->assertSame($caseId, $section->fresh()->derived_case_id); $this->assertSame(2, IguideLabCase::count());
        $this->otherToken(); $this->getJson('/api/iguide-lab/sections/'.$section->id)->assertNotFound();
        Queue::assertPushed(ProcessIguideLabSection::class, 1); Queue::assertNotPushed(ProcessIguideLabRun::class); Http::assertNothingSent();
    }

    public function test_derived_metadata_cannot_substitute_for_native_pixels_and_changed_source_stops_extraction(): void
    {
        $this->source->update(['metadata' => ['originalSha256' => str_repeat('f', 64), 'originalDimensions' => [11008, 5504]]]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/sections', $this->sectionInput())->assertUnprocessable();
        $this->source->update(['metadata' => []]); $record = $this->reserveSection();
        Storage::disk(LabService::DISK)->put($this->source->source_path, 'changed'); app(LabSections::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); $this->assertNull($record->fresh()->derived_case_id); Http::assertNothingSent();
    }

    public function test_reviewed_mask_replay_and_project_scope_preserve_support_identity(): void
    {
        $section = $this->completedSection(); $id = (string) Str::uuid(); $url = '/api/iguide-lab/sections/'.$section->id.'/masks';
        $body = fn ($reviewed = true) => ['requestId' => $id, 'reviewed' => $reviewed, 'image' => UploadedFile::fake()->createWithContent('support.png', $this->maskPng(2048))];
        $this->withToken($this->token)->post($url, $body(false), ['Accept' => 'application/json'])->assertUnprocessable();
        $first = $this->withToken($this->token)->post($url, $body(), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->withToken($this->token)->post($url, $body(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->assertSame('user_confirmed_support', $first['reviewStatus']); $this->assertSame('soft_guidance_only', $first['providerMaskGuarantee']);
        $this->withToken($this->token)->get($first['assetPath'])->assertOk();
        $this->otherToken(); $this->get($first['assetPath'])->assertNotFound();
        $this->assertSame(1, IguideLabMask::count()); $this->assertSame(0, $this->project->fresh()->used_runs); Http::assertNothingSent();
    }

    public function test_masked_run_checks_binding_before_admission_and_sends_a_distinct_mask_field(): void
    {
        $section = $this->completedSection(); $mask = $this->sectionMask($section);
        $input = ['requestId' => (string) Str::uuid(), 'caseId' => $section->derived_case_id, 'modelId' => 'gpt-image-2.6-sunburst', 'preset' => 'declutter',
            'options' => ['sectionId' => $section->id, 'sectionMaskId' => $mask->id, 'complexity' => 'low', 'declutterMode' => 'selected-object', 'selectedObjects' => ['vase']]];
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'modelId' => 'gpt-image-2.5-sunburst'])->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'caseId' => $this->source->id])->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'options' => []])->assertUnprocessable();
        $this->assertSame(0, $this->project->fresh()->used_runs);
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->cut)]]], 200, ['x-request-id' => 'req-mask'])]);
        app(LabService::class)->process(IguideLabRun::find($first['id']));
        Http::assertSent(function ($request) use ($mask) {
            $parts = collect($request->data()); $images = $parts->where('name', 'image[]'); $masks = $parts->where('name', 'mask');
            return $images->count() === 1 && $masks->count() === 1 && hash('sha256', $masks->first()['contents']) === $mask->provider_sha256;
        });
        $this->assertSame('completed', IguideLabRun::find($first['id'])->status); $this->assertSame(1, $this->project->fresh()->used_runs); Http::assertSentCount(1);
        $next = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'requestId' => (string) Str::uuid()])->assertAccepted()->json('data');
        Storage::disk(LabService::DISK)->put($mask->provider_path, 'changed'); Http::fake(); app(LabService::class)->process(IguideLabRun::find($next['id']));
        $this->assertSame('needs_attention', IguideLabRun::find($next['id'])->status); Http::assertNothingSent();
    }

    public function test_section_composition_is_local_idempotent_unreviewed_and_source_owned(): void
    {
        $section = $this->completedSection(); $mask = $this->sectionMask($section); $run = $this->completedRun($section->derived_case_id, 2048);
        $input = ['requestId' => (string) Str::uuid(), 'runId' => $run->id, 'maskId' => $mask->id]; $url = '/api/iguide-lab/sections/'.$section->id.'/compositions';
        $first = $this->withToken($this->token)->postJson($url, $input)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson($url, $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $record = IguideLabComposition::find($first['id']); app(LabCompositions::class)->process($record); $record->refresh();
        $this->assertSame('completed', $record->status);
        $dto = $this->withToken($this->token)->getJson('/api/iguide-lab/compositions/'.$record->id)->assertOk()->json('data');
        $this->withToken($this->token)->get($dto['alphaAssetPath'])->assertOk();
        $this->withToken($this->token)->getJson('/api/iguide-lab/composites/'.$dto['compositeId'])->assertOk()->assertJsonPath('data.latestReview', null);
        $this->assertSame(0, $this->project->fresh()->used_runs); Queue::assertPushed(ProcessIguideLabComposition::class, 1); Queue::assertNotPushed(ProcessIguideLabRun::class); Http::assertNothingSent();
        $this->otherToken(); $this->getJson('/api/iguide-lab/compositions/'.$record->id)->assertNotFound(); $this->get($dto['alphaAssetPath'])->assertNotFound();
    }

    public function test_grass_support_revision_reuses_completed_image_without_any_new_paid_call(): void
    {
        $disk = Storage::disk(LabService::DISK); $run = $this->completedRun($this->source->id, 1024);
        $pipeline = IguideLabPipeline::create(['project_id' => $this->project->id, 'case_id' => $this->source->id, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'status' => 'completed',
            'child_run_id' => $run->id, 'support_request_id' => (string) Str::uuid(), 'support_status' => 'completed',
            'data' => ['sourcePreparedPath' => $this->source->source_path, 'sourcePreparedSha256' => $this->source->source_sha256]]);
        $mask = $this->withToken($this->token)->post('/api/iguide-lab/pipelines/'.$pipeline->id.'/masks', ['requestId' => (string) Str::uuid(), 'reviewed' => true,
            'image' => UploadedFile::fake()->createWithContent('support.png', $this->maskPng(1024))], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertNull($mask['providerMaskSha256']);
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines/'.$pipeline->id.'/compositions', ['requestId' => (string) Str::uuid(), 'runId' => $run->id, 'maskId' => $mask['id']])->assertAccepted()->json('data.id');
        $record = IguideLabComposition::find($id); app(LabCompositions::class)->process($record);
        $this->assertSame('completed', $record->fresh()->status); $this->assertSame(1, IguideLabRun::count()); $this->assertSame(0, $this->project->fresh()->used_runs); Http::assertNothingSent();
    }

    public function test_failed_alpha_proof_storage_never_publishes_a_completed_composite(): void
    {
        $section = $this->completedSection(); $mask = $this->sectionMask($section); $run = $this->completedRun($section->derived_case_id, 2048);
        $record = app(LabCompositions::class)->start($this->project, 'section', $section->id,
            ['requestId' => (string) Str::uuid(), 'runId' => $run->id, 'maskId' => $mask->id]);
        $disk = Storage::disk(LabService::DISK); $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(fn ($path, ...$args) => str_ends_with($path, '/alpha.png') ? false : $disk->put($path, ...$args));
        Storage::shouldReceive('disk')->with(LabService::DISK)->andReturn($proxy);
        app(LabCompositions::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); $this->assertNull($record->fresh()->composite_id);
        $this->assertSame(0, \App\Models\IguideLabComposite::count());
        $this->assertNull(app(LabCompositions::class)->dto($record->fresh())['alphaAssetPath']); Http::assertNothingSent();
    }

    private function sectionInput(): array { return ['requestId' => (string) Str::uuid(), 'caseId' => $this->source->id, 'view' => ['yaw' => 0, 'pitch' => -10, 'hFov' => 90]]; }
    private function reserveSection(): IguideLabSection { return IguideLabSection::find($this->withToken($this->token)->postJson('/api/iguide-lab/sections', $this->sectionInput())->assertAccepted()->json('data.id')); }
    private function completedSection(): IguideLabSection { $record = $this->reserveSection(); app(LabSections::class)->process($record); $this->assertSame('completed', $record->fresh()->status); return $record->fresh(); }
    private function sectionMask(IguideLabSection $section): IguideLabMask
    {
        return IguideLabMask::find($this->withToken($this->token)->post('/api/iguide-lab/sections/'.$section->id.'/masks', ['requestId' => (string) Str::uuid(), 'reviewed' => true,
            'image' => UploadedFile::fake()->createWithContent('mask.png', $this->maskPng(2048))], ['Accept' => 'application/json'])->assertCreated()->json('data.id'));
    }
    private function completedRun(string $caseId, int $height): IguideLabRun
    {
        $id = (string) Str::uuid(); $path = 'iguide-lab/'.$this->project->id.'/runs/'.$id.'.png'; Storage::disk(LabService::DISK)->put($path, $height === 2048 ? $this->cut : $this->panorama, ['visibility' => 'private']);
        return IguideLabRun::create(['id' => $id, 'project_id' => $this->project->id, 'case_id' => $caseId, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'model_id' => 'gpt-image-2.6-sunburst', 'provider' => 'openai', 'preset' => 'grass', 'prompt' => 'fixture', 'options' => [], 'status' => 'completed', 'output_path' => $path, 'output_width' => 2048, 'output_height' => $height]);
    }
    private function otherToken(): void { $token = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'max_runs' => 1]); $this->withToken($token); }
    private function png(int $width, int $height): string { return (string) \Intervention\Image\ImageManager::gd()->create($width, $height)->fill('888888')->toPng(); }
    private function maskPng(int $height): string
    {
        $row = str_repeat("\0", 2048); $rows = '';
        for ($y = 0; $y < $height; $y++) { $line = $row; if ($y >= 500 && $y < 600) $line = substr_replace($line, str_repeat(chr(127), 100), 700, 100); $rows .= "\0".$line; }
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 2048, $height, 8, 0, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');
    }
    private function geometryFixture(string $requestPath): array
    {
        $request = json_decode(file_get_contents($requestPath), true); $out = $request['outputDirectory'];
        if (! is_dir($out)) mkdir($out, 0770, true);
        if ($request['operation'] === 'extract') {
            if (! is_dir($out.'/cut/views')) mkdir($out.'/cut/views', 0770, true);
            file_put_contents($out.'/cut/views/section.png', $this->cut); file_put_contents($out.'/cut/manifest.json', '{"fixture":true}');
            return ['artifacts' => ['cut' => ['path' => $out.'/cut/views/section.png', 'sha256' => hash('sha256', $this->cut)], 'geometry' => ['path' => $out.'/cut/manifest.json', 'sha256' => hash_file('sha256', $out.'/cut/manifest.json')]],
                'sourceDimensions' => [2048, 1024], 'geometryVersion' => 'fixture', 'adapterSha256' => hash_file('sha256', resource_path('iguide-lab/section_geometry.py')), 'geometryCodeSha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))];
        }
        $artifacts = [];
        foreach (['preview', 'native', 'alpha'] as $kind) { $path = $out.'/'.$kind.'.png'; file_put_contents($path, $this->panorama); $artifacts[$kind] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'width' => 2048, 'height' => 1024]; }
        return ['artifacts' => $artifacts, 'statistics' => ['outsideAlphaChangedPixels' => 0, 'outsideAlphaPixels' => 1000], 'adapterSha256' => str_repeat('c', 64), 'coordinateConvention' => 'fixture raw camera convention'];
    }
}
