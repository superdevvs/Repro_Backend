<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposition;
use App\Models\IguideLabMask;
use App\Models\IguideLabSection;
use App\Services\IguideLab\LabCompositions;
use App\Services\IguideLab\LabMasks;
use App\Services\IguideLab\LabSections;
use App\Services\IguideLab\LabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IguideLabGeometryController extends Controller
{
    public function attachNative(Request $request, string $id, LabSections $sections, LabService $lab)
    {
        $this->keys($request, ['requestId', 'image']);
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:30720']]);
        $project = $request->attributes->get('iguide_lab_project');
        $parent = IguideLabCase::where('project_id', $project->id)->findOrFail($id);
        $case = $sections->attachNative($project, $parent, $request->file('image'), $input['requestId']);
        return response()->json(['data' => ['parentCaseId' => $parent->id, 'nativeSource' => $parent->fresh()->native_source, 'case' => $lab->caseDto($case)]], 201);
    }

    public function startSection(Request $request, LabSections $sections)
    {
        $this->keys($request, ['requestId', 'caseId', 'view']);
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'caseId' => ['required', 'uuid'], 'view' => ['required', 'array:yaw,pitch,hFov'],
            'view.yaw' => ['required', 'numeric', 'between:-180,180'], 'view.pitch' => ['required', 'numeric', 'between:-90,90'], 'view.hFov' => ['required', 'numeric', 'between:30,130']]);
        return response()->json(['data' => $sections->dto($sections->start($request->attributes->get('iguide_lab_project'), $input))], 202);
    }

    public function showSection(Request $request, string $id, LabSections $sections)
    {
        return response()->json(['data' => $sections->dto(IguideLabSection::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id))]);
    }

    public function addSectionMask(Request $request, string $id, LabMasks $masks) { return $this->addMask($request, 'section', $id, $masks); }
    public function addPipelineMask(Request $request, string $id, LabMasks $masks) { return $this->addMask($request, 'pipeline', $id, $masks); }
    private function addMask(Request $request, string $kind, string $id, LabMasks $masks)
    {
        $this->keys($request, ['requestId', 'image', 'reviewed']);
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'image' => ['required', 'file', 'mimetypes:image/png', 'max:8192'], 'reviewed' => ['required', 'accepted']]);
        [$mask, $created] = $masks->add($request->attributes->get('iguide_lab_project'), $kind, $id, $request->file('image'), $input['requestId']);
        return response()->json(['data' => $masks->dto($mask)], $created ? 201 : 200);
    }

    public function composeSection(Request $request, string $id, LabCompositions $service) { return $this->compose($request, 'section', $id, $service); }
    public function composePipeline(Request $request, string $id, LabCompositions $service) { return $this->compose($request, 'pipeline', $id, $service); }
    private function compose(Request $request, string $kind, string $id, LabCompositions $service)
    {
        $this->keys($request, ['requestId', 'runId', 'maskId', 'harmonization']);
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'runId' => ['required', 'uuid'], 'maskId' => ['required', 'uuid'],
            'harmonization' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(['none', \App\Services\IguideLab\LabHarmonization::MODE])]]);
        return response()->json(['data' => $service->dto($service->start($request->attributes->get('iguide_lab_project'), $kind, $id, $input))], 202);
    }

    public function showComposition(Request $request, string $id, LabCompositions $service)
    {
        return response()->json(['data' => $service->dto(IguideLabComposition::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id))]);
    }

    public function maskAsset(Request $request, string $id)
    {
        $projectId = $request->attributes->get('iguide_lab_project')->id;
        return $this->asset($projectId, IguideLabMask::where('project_id', $projectId)->findOrFail($id)->path);
    }
    public function compositionAlpha(Request $request, string $id)
    {
        $projectId = $request->attributes->get('iguide_lab_project')->id;
        $record = IguideLabComposition::where('project_id', $projectId)->where('status', 'completed')->findOrFail($id);
        return $this->asset($projectId, $record->data['alphaPath'] ?? null);
    }
    public function compositionClipping(Request $request, string $id)
    {
        $projectId = $request->attributes->get('iguide_lab_project')->id;
        $record = IguideLabComposition::where('project_id', $projectId)->findOrFail($id);
        return $this->asset($projectId, $record->data['harmonizationClippingPath'] ?? null);
    }
    private function asset(string $projectId, ?string $path)
    {
        $disk = Storage::disk(LabService::DISK); abort_unless($path && str_starts_with($path, 'iguide-lab/'.$projectId.'/') && ! str_contains($path, '..') && $disk->exists($path), 404);
        $real = realpath($disk->path($path)); $root = realpath($disk->path('iguide-lab/'.$projectId));
        abort_unless($real && $root && str_starts_with($real, $root.DIRECTORY_SEPARATOR), 404);
        return response()->file($real, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
    private function keys(Request $request, array $allowed): void { abort_if(array_diff(array_keys($request->all()), $allowed), 422, 'Unexpected geometry request field.'); }
}
