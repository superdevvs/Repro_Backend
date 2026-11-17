<?php
namespace Tests\Feature;

use App\Models\IguideLabDeclutterRemovalPlan;
use App\Models\IguideLabLifestyleAssessment;
use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabDeclutterRemovalBridge;
use App\Services\IguideLab\LabDeclutterRemovalContext;
use App\Services\IguideLab\LabDeclutterRemovalPlans;
use App\Services\IguideLab\LabDeclutterRemovalPolicy;
use App\Services\IguideLab\LabLifestyleAssessmentBridge;
use App\Services\IguideLab\LabLifestyleAssessmentContext;
use App\Services\IguideLab\LabLifestyleAssessmentPolicy;
use App\Services\IguideLab\LabLifestyleAssessments;
use App\Services\IguideLab\LabProseText;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 17 Nov production: a removal plan and a lifestyle assessment were discarded because one free-text field was a little
 * longer than its parser bound (STRING_BOUND), after the lifestyle image had been bought. Synthetic answers of the same
 * shape now complete through the real protocol bridges; an over-long non-prose field still stops the record.
 */
class IguideLabProseClippingTest extends TestCase
{
    use RefreshDatabase;
    private const PYTHON = '/usr/local/bin/python3.12';
    private const SENTENCE = 'The tall mirror beside the window may reflect the adults, so the reflection must be checked closely. ';
    private string $key;
    private array $manifests = [];
    private array $reply = [];
    private ProseRemovalContext $removal;
    private ProseLifestyleContext $lifestyle;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        // One stub for the whole test: each fixture answer is read when its request is sent.
        Http::fake(['api.openai.com/v1/responses' => fn () => Http::response($this->reply, 200, ['x-request-id' => 'fixture'])]);
        $this->key = tempnam(sys_get_temp_dir(), 'prose-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback',
            'iguide-lab.openai_primary_key_path' => $this->key, 'iguide-lab.pipeline_python_path' => self::PYTHON]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->removal = new ProseRemovalContext; $this->lifestyle = new ProseLifestyleContext;
        $this->app->instance(LabDeclutterRemovalContext::class, $this->removal);
        $this->app->instance(LabLifestyleAssessmentContext::class, $this->lifestyle);
        $this->manifest('declutter_removal_runtime_manifest', fn () => app(LabDeclutterRemovalBridge::class)->snapshot());
        $this->manifest('lifestyle_assessment_runtime_manifest', fn () => app(LabLifestyleAssessmentBridge::class)->snapshot());
    }

    protected function tearDown(): void
    {
        foreach ([$this->key, ...$this->manifests] as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    public function test_clip_matches_the_python_rule(): void
    {
        $text = 'Small decorative ceramic vase with dried stems on the kitchen island, beside the fruit bowl and the cutting board near the sink';
        $this->assertSame('Small decorative ceramic vase with dried stems on the kitchen island, beside the fruit bowl and the cutting board near…', LabProseText::clip($text, 120));
        $this->assertSame('alpha beta…', LabProseText::clip('alpha beta, gamma delta', 13));
        $this->assertSame(str_repeat('n', 699).'…', LabProseText::clip(str_repeat('n', 900), 700));
        $this->assertSame(str_repeat('é', 30).'…', LabProseText::clip(str_repeat('é', 30).' '.str_repeat('ü', 30), 40));
        $this->assertSame('Within bound.', LabProseText::clip('Within bound.', 120));
    }

    public function test_removal_plan_with_an_overlong_target_description_completes(): void
    {
        if (! $this->pythonAvailable()) return;
        $plans = app(LabDeclutterRemovalPlans::class);
        foreach ([131, 162] as $length) {
            $record = $this->removalPlan(fn (array $p) => array_replace_recursive($p, ['target' => ['description' => $this->prose($length)]]));
            $this->assertSame('completed', $record->status, (string) $record->error_code);
            $this->assertNull($record->error_code);
            $description = $record->result['proposal']['target']['description'];
            $this->assertLessThanOrEqual(120, mb_strlen($description)); $this->assertStringEndsWith('…', $description);
            $this->assertEquals([['path' => '$.target.description', 'length' => $length, 'maxLength' => 120]], $record->result['proseClipped']);
            $this->assertSame('ready_for_segmentation', $record->result['status']);
            // The clipped plan still authorizes its machine segmentation.
            $steps = $plans->reserveSegmentationSteps($record);
            $this->assertSame($record->id, $plans->forSegmentation($steps['target_object'])['assessmentId']);
        }
    }

    public function test_removal_plan_with_an_overlong_non_prose_field_still_stops(): void
    {
        if (! $this->pythonAvailable()) return;
        $record = $this->removalPlan(fn (array $p) => [...$p, 'protectedFeatures' => [['id' => str_repeat('f', 81),
            'box' => ['xMin' => .7, 'yMin' => .1, 'xMax' => .9, 'yMax' => .3], 'evidenceIds' => ['source_cut'], 'notes' => 'Kept.']]]);
        $this->assertSame('needs_attention', $record->status);
        $this->assertSame('submitted_assessment_unconfirmed_or_rejected', $record->error_code);
    }

    public function test_lifestyle_assessment_with_overlong_optics_notes_completes(): void
    {
        if (! $this->pythonAvailable()) return;
        foreach ([507, 784] as $length) {
            $record = $this->lifestyleAssessment(fn (array $p) => array_replace_recursive($p, ['optics' => ['notes' => $this->prose($length)]]));
            $this->assertSame('completed', $record->status, (string) $record->error_code);
            $notes = $record->result['proposal']['optics']['notes'];
            $this->assertLessThanOrEqual(500, mb_strlen($notes)); $this->assertStringEndsWith('…', $notes);
            $this->assertEquals([['path' => '$.optics.notes', 'length' => $length, 'maxLength' => 500]], $record->result['proseClipped']);
            $this->assertSame('ready_for_segmentation', $record->result['status']);
        }
    }

    public function test_lifestyle_assessment_with_an_overlong_binding_still_stops(): void
    {
        if (! $this->pythonAvailable()) return;
        $record = $this->lifestyleAssessment(fn (array $p) => array_replace_recursive($p, ['binding' => ['captureId' => str_repeat('c', 81)]]));
        $this->assertSame('needs_attention', $record->status);
    }

    /** CI runs the PHP suite without the pinned Python runtime; the real bridges must then fail closed (never skip). */
    private function pythonAvailable(): bool
    {
        if (is_executable(self::PYTHON)) return true;
        $this->assertFalse(app(LabDeclutterRemovalBridge::class)->ready());
        $this->assertFalse(app(LabLifestyleAssessmentBridge::class)->ready());
        return false;
    }

    private function manifest(string $key, \Closure $snapshot): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prose-manifest-'); $this->manifests[] = $path;
        config(['iguide-lab.'.$key => $path]); file_put_contents($path, '{}');
        $values = ['validationStatus' => 'passed', 'pythonVersion' => '3.12.3', 'pillowVersion' => '13.0.0', 'pythonPath' => self::PYTHON];
        foreach ($snapshot() as $name => $value) if (! in_array($name, ['version', 'runtimeManifestSha256'], true)) $values['validated'.ucfirst($name)] = $value;
        file_put_contents($path, json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function prose(int $length): string
    {
        $text = substr(str_repeat(self::SENTENCE, intdiv($length, strlen(self::SENTENCE)) + 2), 0, $length);
        return str_ends_with($text, ' ') ? substr($text, 0, -1).'x' : $text;
    }

    private function item(string $preset): array
    {
        $project = IguideLabProject::create(['name' => 'Prose bound', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $bytes = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $bytes), ['label' => 'Original', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => $preset]]]);
        $policy = $workflow->policy;
        if ($preset === 'declutter') $policy['declutterRemoval'] = LabDeclutterRemovalPolicy::snapshot();
        else $policy['lifestyleAssessment'] = LabLifestyleAssessmentPolicy::snapshot();
        $workflow->update(['policy' => $policy]);
        return [$project, IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole()];
    }

    /** Synthetic same-capture evidence: exact PNG bytes the Python protocol re-hashes and re-measures. */
    private function evidence(IguideLabProject $project, array $ids, array $sizes): array
    {
        $views = []; $assets = [];
        foreach ($ids as $i => $id) {
            $bytes = (string) \Intervention\Image\ImageManager::gd()->create(...$sizes[$i])->fill(sprintf('%02x7882', 70 + 10 * $i))->toPng();
            $path = 'iguide-lab/'.$project->id.'/prose-fixture/'.$id.'.png'; Storage::disk(LabService::DISK)->put($path, $bytes);
            $assets[] = ['id' => $id, 'path' => $path, 'sha256' => hash('sha256', $bytes)]; $views[] = ['id' => $id, 'sha256' => hash('sha256', $bytes)];
        }
        return [$views, $assets];
    }

    private function removalPlan(\Closure $mutate): IguideLabDeclutterRemovalPlan
    {
        [$project, $item] = $this->item('declutter'); $sha = str_repeat('a', 64);
        $ids = ['source_cut', 'parent_overview', 'parent_front', 'parent_right', 'parent_back', 'parent_left'];
        [$views, $assets] = $this->evidence($project, $ids, [[2048, 2048], [2048, 1024], [1024, 1024], [1024, 1024], [1024, 1024], [1024, 1024]]);
        $capture = '00000000-0000-0000-0000-000000000001';
        $binding = array_fill_keys(['workflowItemId', 'captureId', 'nativeSourceSha256', 'sourceCutSha256', 'submittedSourceSha256', 'parentContextSha256',
            'sourceGeometrySha256', 'sourcePreparationSha256', 'sourceAnalysisSha256'], $sha);
        $binding = [...$binding, 'workflowItemId' => '00000000-0000-0000-0000-000000000002', 'captureId' => $capture,
            'sourceCutSha256' => $views[0]['sha256'], 'parentContextSha256' => $views[1]['sha256']];
        $pose = ['yaw' => 0, 'pitch' => -30, 'hFov' => 65, 'width' => 2048, 'height' => 2048];
        foreach ($views as $i => $view) $views[$i] += ['captureId' => $capture, 'nativeSourceSha256' => $sha, 'derivationSha256' => $sha,
            'width' => $i === 0 ? 2048 : ($i === 1 ? 2048 : 1024), 'height' => $i === 0 ? 2048 : 1024, 'projection' => $i === 1 ? 'equirectangular' : 'rectilinear',
            'pose' => $i === 0 ? $pose : ($i === 1 ? null : ['yaw' => ($i - 2) * 90, 'pitch' => 0, 'hFov' => 100, 'width' => 1024, 'height' => 1024])];
        $this->removal->bundle = ['context' => ['schemaVersion' => LabDeclutterRemovalPolicy::VERSION, 'binding' => $binding, 'pose' => $pose, 'evidence' => $views,
            'sourcePlan' => ['summary' => 'Synthetic narrow one-object removal route.', 'targets' => [], 'protectedFeatures' => [], 'sourceArtifacts' => [], 'uncertainties' => []]],
            'assets' => $assets];
        $service = app(LabDeclutterRemovalPlans::class);
        $record = $service->start($service->reserve($item, (string) Str::uuid(), (string) Str::uuid()));
        $finding = fn (array $value, bool $parents = false) => $value + ['evidenceIds' => $parents ? array_slice($ids, 1) : ['source_cut'], 'notes' => 'Synthetic mechanics only.'];
        $proposal = ['contextSha256' => $this->contextSha($record->data['payload']['path']), 'binding' => $binding,
            'target' => $finding(['id' => 'target_object', 'description' => 'solid decorative stone', 'count' => 'exactly_one', 'purpose' => 'decorative_only',
                'opacity' => 'opaque', 'silhouette' => 'complete_compact_solid', 'box' => ['xMin' => .3, 'yMin' => .3, 'xMax' => .45, 'yMax' => .55]]),
            'receiver' => $finding(['material' => 'matte_simple_continuous', 'continuity' => 'visible_surrounding_support', 'hiddenSurface' => 'unobserved_generated_estimate']),
            'shadow' => $finding(['status' => 'none_observed', 'box' => null]),
            'optics' => $finding(['status' => 'no_relevant_dependency_observed'], true), 'foreground' => $finding(['status' => 'no_overlap_observed']),
            'contextCoverage' => $finding(['status' => 'sufficient_same_capture_views'], true), 'protectedFeatures' => [],
            'generationPrompt' => 'Remove only the compact decorative stone and its attributable local shadow. Continue the matte tabletop naturally, preserving all other objects, original lighting, geometry, perspective and image boundaries.',
            'uncertainties' => []];
        return $this->answer($record, $mutate($proposal), fn ($r) => $service->process($r));
    }

    private function lifestyleAssessment(\Closure $mutate): IguideLabLifestyleAssessment
    {
        [$project, $item] = $this->item('lifestyle');
        $ids = ['source_cut', 'candidate_cut', 'parent_overview', 'parent_front', 'parent_right', 'parent_back', 'parent_left'];
        [$views, $assets] = $this->evidence($project, $ids, [[2048, 2048], [2048, 2048], [2048, 1024], [1024, 1024], [1024, 1024], [1024, 1024], [1024, 1024]]);
        $names = ['workflowItemId', 'captureId', 'nativeSourceSha256', 'sourceCutSha256', 'submittedSourceSha256', 'candidateCutSha256', 'parentContextSha256',
            'sourceGeometrySha256', 'sourcePreparationSha256', 'sourceAnalysisSha256', 'generationSettingsSha256'];
        $binding = array_combine($names, array_map(fn ($k) => hash('sha256', $k), $names));
        $binding = [...$binding, 'workflowItemId' => '2209f4f0-0b7d-4e0a-85db-e14f09236655', 'captureId' => '8f585286-158e-4a8b-97ef-e77034a32fa2',
            'sourceCutSha256' => $views[0]['sha256'], 'candidateCutSha256' => $views[1]['sha256'], 'parentContextSha256' => $views[2]['sha256']];
        $pose = ['yaw' => 0, 'pitch' => -15, 'hFov' => 90, 'width' => 2048, 'height' => 2048];
        foreach ($views as $i => $view) $views[$i] += ['captureId' => $binding['captureId'], 'nativeSourceSha256' => $binding['nativeSourceSha256'],
            'derivationSha256' => $i === 0 ? $binding['sourceGeometrySha256'] : hash('sha256', $view['id']),
            'width' => $i < 3 ? 2048 : 1024, 'height' => $i < 2 ? 2048 : 1024, 'projection' => $i === 2 ? 'equirectangular' : 'rectilinear',
            'pose' => $i < 2 ? $pose : ($i === 2 ? null : ['yaw' => [0, 90, 180, 270][$i - 3], 'pitch' => 0, 'hFov' => 100, 'width' => 1024, 'height' => 1024])];
        $this->lifestyle->bundle = ['context' => ['schemaVersion' => 'automatic-standing-pair-assessment/1', 'binding' => $binding, 'pose' => $pose, 'evidence' => $views],
            'assets' => $assets];
        $service = app(LabLifestyleAssessments::class);
        $record = $service->start($service->reserve($item, (string) Str::uuid(), (string) Str::uuid()));
        $both = ['source_cut', 'candidate_cut'];
        $finding = fn (string $status, array $evidence) => ['status' => $status, 'evidenceIds' => $evidence, 'notes' => 'Synthetic diagnostic only.'];
        $people = [];
        foreach (['adult_1' => .2, 'adult_2' => .58] as $id => $x) {
            $shoes = [];
            foreach (['left' => .025, 'right' => .11] as $side => $offset) $shoes[] = ['side' => $side, 'status' => 'visible_contact',
                'soleSegment' => [['x' => $x + $offset, 'y' => .8], ['x' => $x + $offset + .045, 'y' => .8]], 'sourceFloorRelation' => 'visible_level_floor',
                'evidenceIds' => $both, 'notes' => 'Explicit synthetic shoe segment.'];
            $people[] = ['id' => $id, 'box' => ['xMin' => $x, 'yMin' => .2, 'xMax' => $x + .2, 'yMax' => .82], 'status' => 'complete_standing_adult',
                'anatomy' => 'plausible_visible_limbs', 'scale' => 'plausible_source_relative', 'topology' => 'clear_visible_boundaries_and_holes',
                'shoes' => $shoes, 'evidenceIds' => $both, 'notes' => 'Synthetic person.'];
        }
        $proposal = ['schemaVersion' => 'automatic-standing-pair-assessment/1', 'contextSha256' => $this->contextSha($record->data['payload']['path']), 'binding' => $binding,
            'summary' => 'Synthetic pair only.', 'people' => $people,
            'sourceRegion' => $finding('visible_level_floor_no_existing_people', ['source_cut', 'parent_overview']),
            'group' => $finding('two_separate_standing_adults_no_props', $both), 'foreground' => $finding('no_foreground_overlap', $both),
            'protectedFeatures' => $finding('no_conflict_observed', $both), 'optics' => $finding('no_relevant_dependency_observed', $ids),
            'lighting' => $finding('diffuse_tiny_contact_only', $ids), 'contextCoverage' => $finding('sufficient_same_capture_views', array_slice($ids, 2)),
            'floorSelection' => ['status' => 'visible_level_floor', 'box' => ['xMin' => .1, 'yMin' => .6, 'xMax' => .9, 'yMax' => .95], 'material' => 'wood_laminate',
                'evidenceIds' => ['source_cut'], 'notes' => 'Source floor.'], 'uncertainties' => []];
        return $this->answer($record, $mutate($proposal), fn ($r) => $service->process($r));
    }

    private function contextSha(string $payloadPath): string
    {
        $payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
        return json_decode($payload['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR)['contextSha256'];
    }

    /** One paid provider answer (faked transport), then the separate parse tick, exactly as the queued job runs. */
    private function answer($record, array $proposal, \Closure $process)
    {
        $this->assertSame('queued', $record->status);
        $model = json_decode(file_get_contents($record->data['payload']['path']), true, flags: JSON_THROW_ON_ERROR)['model'];
        $this->reply = ['id' => 'resp_prose', 'model' => $model, 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($proposal, JSON_THROW_ON_ERROR)]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20]];
        $process($record->fresh()); $this->assertSame('received', $record->fresh()->status);
        $process($record->fresh());
        return $record->fresh();
    }
}

class ProseRemovalContext extends LabDeclutterRemovalContext
{
    public array $bundle = [];
    public function capture(IguideLabWorkflowItem $item, string $sectionId, string $sourceAnalysisId): array { return $this->bundle; }
}

class ProseLifestyleContext extends LabLifestyleAssessmentContext
{
    public array $bundle = [];
    public function capture(IguideLabWorkflowItem $item, string $sectionId, string $candidateRunId): array { return $this->bundle; }
}
