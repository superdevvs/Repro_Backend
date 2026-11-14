<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\IguideLabWorkflow;
use App\Services\IguideLab\LabCatalog;
use App\Services\IguideLab\LabWorkflowPolicy;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IguideLabWorkflowController extends Controller
{
    public function index(Request $request, LabWorkflows $service)
    {
        $project = $request->attributes->get('iguide_lab_project');
        return response()->json(['data' => ['capabilities' => app(LabWorkflowPolicy::class)->capabilities(),
            'workflows' => IguideLabWorkflow::where('project_id', $project->id)->latest()->limit(120)->get()->map(fn ($record) => $service->dto($record))]]);
    }
    public function start(Request $request, LabWorkflows $service)
    {
        abort_if(array_diff(array_keys($request->all()), ['requestId', 'items']), 422, 'Unsupported workflow field.');
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['required', 'array:caseId,preset,style,instructions'], 'items.*.caseId' => ['required', 'uuid'],
            'items.*.preset' => ['required', Rule::in(array_keys(LabCatalog::PRESETS))], 'items.*.style' => ['nullable', Rule::in(array_keys(LabCatalog::STYLES))],
            'items.*.instructions' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $input['items'] = array_map(fn ($item) => [...$item, 'instructions' => $item['instructions'] ?? ''], $input['items']);
        return response()->json(['data' => $service->dto($service->start($request->attributes->get('iguide_lab_project'), $input))], 202);
    }
    public function show(Request $request, string $id, LabWorkflows $service)
    {
        return response()->json(['data' => $service->dto($this->owned($request, $id))]);
    }
    public function cancel(Request $request, string $id, LabWorkflows $service)
    {
        abort_if($request->all(), 422, 'Cancellation has no additional settings.');
        return response()->json(['data' => $service->dto($service->cancel($this->owned($request, $id)))]);
    }
    private function owned(Request $request, string $id): IguideLabWorkflow
    {
        return IguideLabWorkflow::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);
    }
}
