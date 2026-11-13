<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabCase;
use App\Models\IguideLabRun;
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
            'cases' => IguideLabCase::where('project_id', $project->id)->oldest()->get()->map(fn ($case) => $service->caseDto($case)),
            'runs' => IguideLabRun::where('project_id', $project->id)->latest()->get()->map(fn ($run) => $service->runDto($run)),
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
            'options' => ['sometimes', 'array:roomType,referenceCaseIds,referenceRunIds,furnitureDensity,lightingIntensity,preserveArchitecture,preserveFixedFinishes,allowFinishChanges'],
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
        [$review, $created] = $reviews->add($project, $run, $request->validate($rules));
        return response()->json(['data' => $reviews->dto($review)], $created ? 201 : 200);
    }

    public function asset(Request $request, string $kind, string $id)
    {
        $projectId = $request->attributes->get('iguide_lab_project')->id;
        $record = ($kind === 'case' ? IguideLabCase::query() : IguideLabRun::query())->where('project_id', $projectId)->findOrFail($id);
        abort_if($kind === 'run' && $record->status !== 'completed', 404);
        $path = $kind === 'case' ? $record->source_path : $record->output_path;
        abort_unless(is_string($path) && str_starts_with($path, 'iguide-lab/'.$projectId.'/') && ! str_contains($path, '..') && Storage::disk(LabService::DISK)->exists($path), 404);

        return response()->file(Storage::disk(LabService::DISK)->path($path), [
            'Content-Type' => $kind === 'case' ? $record->source_mime : 'image/png',
            'Content-Disposition' => 'inline; filename="'.($kind === 'case' ? 'source' : 'experiment').'.'.pathinfo($path, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
