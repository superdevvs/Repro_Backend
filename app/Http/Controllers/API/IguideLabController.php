<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabCase;
use App\Models\IguideLabRun;
use App\Models\IguideLabComposite;
use App\Models\IguideLabPipeline;
use App\Models\IguideLabAnalysis;
use App\Services\IguideLab\LabComposites;
use App\Services\IguideLab\LabCatalog;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabReviews;
use App\Services\IguideLab\LabPipelines;
use App\Services\IguideLab\LabDisplayMetadata;
use App\Services\IguideLab\LabAnalyses;
use App\Services\IguideLab\LabScenePlanProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class IguideLabController extends Controller
{
    public function index(Request $request, LabService $service)
    {
        $project = $request->attributes->get('iguide_lab_project');

        return response()->json(['data' => [
            'project' => ['id' => $project->id, 'name' => $project->name, 'expiresAt' => $project->expires_at->toIso8601String(),
                'maxRuns' => $project->max_runs, 'usedRuns' => $project->used_runs, 'remainingRuns' => max(0, $project->max_runs - $project->used_runs)],
            'models' => LabCatalog::models(), 'presets' => LabCatalog::labels(LabCatalog::PRESETS), 'styles' => LabCatalog::labels(LabCatalog::STYLES),
            'capabilities' => ['schemaVersion' => 5, 'composites' => true, 'openaiComplexity' => ['low', 'medium', 'high'], 'selectedObjectInstructions' => true, 'maskUpload' => true, 'nativeCompositeUpload' => 'operator_only',
                'sectionEditing' => ['supported' => true, 'ready' => app(\App\Services\IguideLab\LabSectionGeometry::class)->ready(), 'automaticAcceptance' => false,
                    'size' => ['width' => 2048, 'height' => 2048], 'hFov' => ['min' => 30, 'max' => 130], 'nativeAttachmentRequiresExactHash' => true,
                    'providerMaskModels' => ['gpt-image-2.6-sunburst', 'gpt-image-2.6-flare'], 'providerMaskGuarantee' => 'soft_guidance_only',
                    'maskConvention' => 'white_edit_black_preserve', 'maskEdgeInsetFraction' => 0.1, 'grassMaskRecomposition' => true,
                    'harmonization' => ['supported' => true, 'ready' => app(\App\Services\IguideLab\LabHarmonization::class)->ready(),
                        'modes' => [\App\Services\IguideLab\LabHarmonization::MODE], 'sectionOnly' => true, 'automaticAcceptance' => false,
                        'limits' => \App\Services\IguideLab\LabHarmonization::LIMITS]],
                'sceneAnalysis' => ['supported' => true, 'ready' => app(LabAnalyses::class)->ready(), 'modelId' => LabScenePlanProtocol::MODEL,
                    'protocolVersion' => LabScenePlanProtocol::VERSION, 'automaticGeneration' => false, 'applyDraft' => true, 'maxAnalyses' => 120,
                    'usedAnalyses' => IguideLabAnalysis::where('project_id', $project->id)->count(), 'suggestedSectionMaxHfov' => 120, 'sectionExecution' => false,
                    'sectionParentContext' => ['supported' => true, 'version' => \App\Services\IguideLab\LabAnalysisSourceContext::VERSION,
                        'requiresVerifiedNativeAttachment' => true, 'maxImages' => 6, 'scopeExpansion' => false, 'automaticGeneration' => false]],
                'referenceComposites' => ['supported' => true, 'requiresAcceptedReview' => true, 'maxCombinedReferences' => 3],
                'pipelines' => ['supported' => true, 'automaticAcceptance' => false, 'recipes' => [['id' => LabPipelines::RECIPE, 'version' => LabPipelines::VERSION,
                    'ready' => app(LabPipelines::class)->ready(), 'preset' => 'grass', 'projection' => 'equirectangular', 'supportsExistingRun' => true,
                    'output' => ['width' => 2048, 'height' => 1024, 'resolutionStrategy' => 'preview_only'], 'supportModelId' => 'fal-ai/sam-3-1/image']]]],
            'cases' => IguideLabCase::where('project_id', $project->id)->oldest()->get()->map(fn ($case) => $service->caseDto($case)),
            'runs' => IguideLabRun::where('project_id', $project->id)->latest()->get()->map(fn ($run) => $service->runDto($run)),
            'composites' => IguideLabComposite::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(LabComposites::class)->dto($record)),
            'pipelines' => IguideLabPipeline::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(LabPipelines::class)->dto($record)),
            'analyses' => IguideLabAnalysis::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(LabAnalyses::class)->dto($record)),
            'sections' => \App\Models\IguideLabSection::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(\App\Services\IguideLab\LabSections::class)->dto($record)),
            'masks' => \App\Models\IguideLabMask::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(\App\Services\IguideLab\LabMasks::class)->dto($record)),
            'compositions' => \App\Models\IguideLabComposition::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(\App\Services\IguideLab\LabCompositions::class)->dto($record)),
        ]]);
    }

    public function upload(Request $request, LabService $service)
    {
        $input = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:30720'],
            'label' => ['required', 'string', 'max:160'], 'roomId' => ['nullable', 'string', 'max:100'],
            'panoId' => ['nullable', 'string', 'max:100'], 'tourUrl' => ['nullable', 'url:https', 'max:2000'],
            'projection' => ['required', Rule::in(['equirectangular', 'rectilinear'])],
            'metadata' => ['nullable', 'json', 'max:16000'],
        ]);
        $input['metadata'] = json_decode($input['metadata'] ?? '{}', true);
        abort_unless(is_array($input['metadata']), 422, 'Metadata must be a JSON object.');
        $case = $service->addCase($request->attributes->get('iguide_lab_project'), $request->file('image'), $input);

        return response()->json(['data' => $service->caseDto($case)], 201);
    }

    public function start(Request $request, LabService $service)
    {
        $input = $request->validate([
            'caseId' => ['required', 'uuid'], 'requestId' => ['required', 'uuid'],
            'modelId' => ['required', Rule::in(array_keys(LabCatalog::MODELS))],
            'preset' => ['required', Rule::in(array_keys(LabCatalog::PRESETS))],
            'style' => ['nullable', Rule::in(array_keys(LabCatalog::STYLES))], 'prompt' => ['nullable', 'string', 'max:4000'],
            'options' => ['sometimes', 'array:roomType,referenceCaseIds,referenceRunIds,referenceCompositeIds,furnitureDensity,lightingIntensity,preserveArchitecture,preserveFixedFinishes,allowFinishChanges,complexity,declutterMode,selectedObjects,preserveObjects,analysisId,promptMode,sectionId,sectionMaskId'],
            'options.sectionId' => ['sometimes', 'uuid'], 'options.sectionMaskId' => ['sometimes', 'uuid'],
            'options.analysisId' => ['sometimes', 'uuid'], 'options.promptMode' => ['sometimes', Rule::in(['preset', 'scene-plan'])],
            'options.referenceCompositeIds' => ['sometimes', 'array', 'max:3'], 'options.referenceCompositeIds.*' => ['required', 'uuid', 'distinct'],
            'options.complexity' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'options.declutterMode' => ['sometimes', Rule::in(['generic', 'selected-object'])],
            'options.selectedObjects' => ['sometimes', 'array', 'max:20'], 'options.selectedObjects.*' => ['required', 'string', 'max:300'],
            'options.preserveObjects' => ['sometimes', 'array', 'max:20'], 'options.preserveObjects.*' => ['required', 'string', 'max:300'],
            'options.roomType' => ['sometimes', Rule::in(['auto', 'living', 'bedroom', 'dining', 'kitchen', 'office', 'bathroom', 'outdoor', 'rec-room', 'other'])],
            'options.referenceCaseIds' => ['sometimes', 'array', 'max:3'], 'options.referenceCaseIds.*' => ['required', 'uuid', 'distinct'],
            'options.referenceRunIds' => ['sometimes', 'array', 'max:3'], 'options.referenceRunIds.*' => ['required', 'uuid', 'distinct'],
            'options.furnitureDensity' => ['sometimes', Rule::in(['light', 'balanced', 'full'])],
            'options.lightingIntensity' => ['sometimes', Rule::in(['natural', 'dramatic'])],
            'options.preserveArchitecture' => ['sometimes', 'boolean'], 'options.preserveFixedFinishes' => ['sometimes', 'boolean'],
            'options.allowFinishChanges' => ['sometimes', 'boolean'],
        ]);
        $run = $service->start($request->attributes->get('iguide_lab_project'), $input);

        return response()->json(['data' => $service->runDto($run)], 202);
    }

    public function show(Request $request, string $id, LabService $service)
    {
        $run = IguideLabRun::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);

        return response()->json(['data' => $service->runDto($run)]);
    }

    public function review(Request $request, string $id, LabReviews $reviews)
    {
        $project = $request->attributes->get('iguide_lab_project');
        $run = IguideLabRun::where('project_id', $project->id)->findOrFail($id);
        [$review, $created] = $reviews->add($project, $run, $request->validate($this->reviewRules()));
        return response()->json(['data' => $reviews->dto($review)], $created ? 201 : 200);
    }

    private function reviewRules(): array
    {
        $rules = [
            'requestId' => ['required', 'uuid'], 'verdict' => ['required', Rule::in(['accepted', 'needs_work'])],
            'checks' => ['required', 'array:edit,geometry,seam,appearance,crossView'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'pipeline' => ['required', Rule::in(['whole_panorama', 'perspective_sections'])],
            'scope' => ['required', Rule::in(['single_panorama', 'cross_view'])],
            'batchId' => ['nullable', 'string', 'max:100'], 'metrics' => ['nullable', 'array', 'max:100'],
        ];
        foreach (['edit', 'geometry', 'seam', 'appearance', 'crossView'] as $check) {
            $rules['checks.'.$check] = ['required', Rule::in(['pass', 'fail', 'unreviewed', 'not_applicable'])];
        }
        return $rules;
    }

    public function addComposite(Request $request, string $id, LabComposites $service)
    {
        $project = $request->attributes->get('iguide_lab_project');
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($id);
        $input = $request->validate(['preview' => ['required', 'file', 'mimetypes:image/png', 'max:30720'], 'manifest' => ['required', 'json', 'max:65536']]);
        $manifest = json_decode($input['manifest'], true);
        abort_unless(is_array($manifest), 422);
        [$record, $created] = $service->add($project, $case, $request->file('preview')->getRealPath(), $manifest);
        return response()->json(['data' => $service->dto($record)], $created ? 201 : 200);
    }

    public function showComposite(Request $request, string $id, LabComposites $service)
    {
        $record = IguideLabComposite::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);
        return response()->json(['data' => $service->dto($record)]);
    }

    public function reviewComposite(Request $request, string $id, LabReviews $reviews)
    {
        $project = $request->attributes->get('iguide_lab_project');
        $record = IguideLabComposite::where('project_id', $project->id)->findOrFail($id);
        [$review, $created] = $reviews->add($project, $record, $request->validate($this->reviewRules()));
        return response()->json(['data' => $reviews->dto($review)], $created ? 201 : 200);
    }

    public function asset(Request $request, string $kind, string $id)
    {
        $projectId = $request->attributes->get('iguide_lab_project')->id;
        $query = match ($kind) { 'case' => IguideLabCase::query(), 'run' => IguideLabRun::query(), 'pipeline-alpha' => IguideLabPipeline::query(), default => IguideLabComposite::query() };
        $record = $query->where('project_id', $projectId)->findOrFail($id);
        abort_if($kind === 'run' && $record->status !== 'completed', 404);
        $path = match ($kind) { 'case' => $record->source_path, 'run' => $record->output_path, 'composite-preview' => $record->preview_path, 'composite-native' => $record->native_path, 'pipeline-alpha' => $record->data['alphaPath'] ?? null };
        abort_unless(is_string($path) && str_starts_with($path, 'iguide-lab/'.$projectId.'/') && ! str_contains($path, '..') && Storage::disk(LabService::DISK)->exists($path), 404);

        return response()->file(Storage::disk(LabService::DISK)->path($path), [
            'Content-Type' => $kind === 'case' ? $record->source_mime : 'image/png',
            'Content-Disposition' => ($kind === 'composite-native' ? 'attachment' : 'inline').'; filename="'.($kind === 'case' ? 'source' : 'experiment').'.'.pathinfo($path, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function startPipeline(Request $request, LabPipelines $service)
    {
        $input = $request->validate([
            'requestId' => ['required', 'uuid'], 'caseId' => ['required', 'uuid'], 'recipe' => ['required', Rule::in([LabPipelines::RECIPE])],
            'existingRunId' => ['nullable', 'uuid'], 'modelId' => ['required_without:existingRunId', Rule::in(array_keys(LabCatalog::MODELS))],
            'prompt' => ['nullable', 'string', 'max:4000'], 'options' => ['sometimes', 'array:complexity'],
            'options.complexity' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
        ]);
        $record = $service->start($request->attributes->get('iguide_lab_project'), $input);
        return response()->json(['data' => $service->dto($record)], 202);
    }

    public function showPipeline(Request $request, string $id, LabPipelines $service)
    {
        $record = IguideLabPipeline::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);
        return response()->json(['data' => $service->dto($record)]);
    }

    public function correctDisplayMetadata(Request $request, string $id, LabDisplayMetadata $service)
    {
        $project = $request->attributes->get('iguide_lab_project');
        $record = IguideLabComposite::where('project_id', $project->id)->findOrFail($id);
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:160'],
            'generatedDetailDescription' => ['required', 'string', 'max:1000'], 'reason' => ['required', 'string', 'max:1000']]);
        abort_if(array_diff(array_keys($request->all()), array_keys($input)), 422, 'Only display text can be corrected.');
        [$entry, $created] = $service->add($project, $record, $input);
        return response()->json(['data' => $service->dto($entry)], $created ? 201 : 200);
    }

    public function startAnalysis(Request $request, LabAnalyses $service)
    {
        abort_if(array_diff(array_keys($request->all()), ['requestId', 'caseId', 'preset', 'style', 'instructions', 'options']), 422, 'Unsupported analysis field.');
        $input = $request->validate([
            'requestId' => ['required', 'uuid'], 'caseId' => ['required', 'uuid'], 'preset' => ['required', Rule::in(array_keys(LabCatalog::PRESETS))],
            'style' => ['nullable', Rule::in(array_keys(LabCatalog::STYLES))], 'instructions' => ['nullable', 'string', 'max:2500'],
            'options' => ['sometimes', 'array:roomType,furnitureDensity,lightingIntensity,preserveArchitecture,preserveFixedFinishes,allowFinishChanges,declutterMode,selectedObjects,preserveObjects'],
            'options.declutterMode' => ['sometimes', Rule::in(['generic', 'selected-object'])],
            'options.selectedObjects' => ['sometimes', 'array', 'max:8'], 'options.selectedObjects.*' => ['required', 'string', 'max:100'],
            'options.preserveObjects' => ['sometimes', 'array', 'max:8'], 'options.preserveObjects.*' => ['required', 'string', 'max:100'],
            'options.roomType' => ['sometimes', Rule::in(['auto', 'living', 'bedroom', 'dining', 'kitchen', 'office', 'bathroom', 'outdoor', 'rec-room', 'other'])],
            'options.furnitureDensity' => ['sometimes', Rule::in(['light', 'balanced', 'full'])],
            'options.lightingIntensity' => ['sometimes', Rule::in(['natural', 'dramatic'])],
            'options.preserveArchitecture' => ['sometimes', 'boolean'], 'options.preserveFixedFinishes' => ['sometimes', 'boolean'], 'options.allowFinishChanges' => ['sometimes', 'boolean'],
        ]);
        return response()->json(['data' => $service->dto($service->start($request->attributes->get('iguide_lab_project'), $input))], 202);
    }

    public function showAnalysis(Request $request, string $id, LabAnalyses $service)
    {
        $record = IguideLabAnalysis::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);
        return response()->json(['data' => $service->dto($record)]);
    }

    public function analysisView(Request $request, string $id, string $view)
    {
        $project = $request->attributes->get('iguide_lab_project');
        $record = IguideLabAnalysis::where('project_id', $project->id)->findOrFail($id);
        $entry = collect($record->data['views'] ?? [])->firstWhere('id', $view); $path = $entry['path'] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'iguide-lab/'.$project->id.'/analyses/'.$record->id.'/')
            && ! str_contains($path, '..') && Storage::disk(LabService::DISK)->exists($path), 404);
        if (in_array($view, ['source_detail_1','source_detail_2'], true)) {
            $descriptor=collect($record->data['stagingLayout']['detailViews']??[])->firstWhere('id',$view);
            abort_unless(is_array($descriptor)&&($descriptor['sha256']??null)===($entry['sha256']??null)
                &&($record->data['stagingLayout']['followup']['operation']??null)==='native_details',404);
            app(\App\Services\IguideLab\LabStagingFramingContext::class)->file($project->id,$path,$descriptor['sha256'],8*1024*1024);
        }
        return response()->file(Storage::disk(LabService::DISK)->path($path), ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="diagnostic-'.$view.'.png"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
