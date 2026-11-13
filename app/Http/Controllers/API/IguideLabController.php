<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabCase;
use App\Models\IguideLabRun;
use App\Models\IguideLabComposite;
use App\Services\IguideLab\LabComposites;
use App\Services\IguideLab\LabCatalog;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabReviews;
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
            'capabilities' => ['schemaVersion' => 2, 'composites' => true, 'openaiComplexity' => ['low', 'medium', 'high'], 'selectedObjectInstructions' => true, 'maskUpload' => false, 'nativeCompositeUpload' => 'operator_only'],
            'cases' => IguideLabCase::where('project_id', $project->id)->oldest()->get()->map(fn ($case) => $service->caseDto($case)),
            'runs' => IguideLabRun::where('project_id', $project->id)->latest()->get()->map(fn ($run) => $service->runDto($run)),
            'composites' => IguideLabComposite::where('project_id', $project->id)->latest()->get()->map(fn ($record) => app(LabComposites::class)->dto($record)),
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
            'options' => ['sometimes', 'array:roomType,referenceCaseIds,referenceRunIds,furnitureDensity,lightingIntensity,preserveArchitecture,preserveFixedFinishes,allowFinishChanges,complexity,declutterMode,selectedObjects,preserveObjects'],
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
        $query = match ($kind) { 'case' => IguideLabCase::query(), 'run' => IguideLabRun::query(), default => IguideLabComposite::query() };
        $record = $query->where('project_id', $projectId)->findOrFail($id);
        abort_if($kind === 'run' && $record->status !== 'completed', 404);
        $path = match ($kind) { 'case' => $record->source_path, 'run' => $record->output_path, 'composite-preview' => $record->preview_path, 'composite-native' => $record->native_path };
        abort_unless(is_string($path) && str_starts_with($path, 'iguide-lab/'.$projectId.'/') && ! str_contains($path, '..') && Storage::disk(LabService::DISK)->exists($path), 404);

        return response()->file(Storage::disk(LabService::DISK)->path($path), [
            'Content-Type' => $kind === 'case' ? $record->source_mime : 'image/png',
            'Content-Disposition' => ($kind === 'composite-native' ? 'attachment' : 'inline').'; filename="'.($kind === 'case' ? 'source' : 'experiment').'.'.pathinfo($path, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
