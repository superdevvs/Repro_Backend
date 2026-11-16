<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Services\IguideLab\LabTourAcceptances;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourCapabilities;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabTourRecords;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourSelections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tour analysis layer endpoints (spec 10.1-10.3): key guards, validation and status codes only; the work happens in
 * the tour services. Every action except index answers 404 while tour_analysis_enabled is off (D10). Public errors
 * use LabTourErrors (D11), never abort() for a client-visible 409, 422 or 429.
 */
class IguideLabTourController extends Controller
{
    private const REVISION_RULE = ['required', 'integer', 'min:0', 'max:2147483647'];
    private const SHORT_ID_RULE = ['string', 'regex:/^[a-z0-9]{1,8}$/D', 'distinct'];

    /** E1: capabilities and up to 10 summaries; 200 with enabled:false while the layer is off. */
    public function index(Request $request, LabTourCapabilities $capabilities): JsonResponse
    {
        return response()->json(['data' => $capabilities->dto($this->project($request))]);
    }

    /** E2: 202 when this call queued new survey work, 200 otherwise (replay, shared record, everything cached). */
    public function store(Request $request, LabTourRecords $records): JsonResponse
    {
        $this->enabled();
        $input = $this->input($request, ['requestId', 'importId', 'preset'], 'default', [
            'requestId' => LabTourErrors::uuidRules(), 'importId' => LabTourErrors::uuidRules(), 'preset' => ['required', 'string', Rule::in(LabTourCapabilities::PRESETS)]]);
        $input = ['requestId' => strtolower($input['requestId']), 'importId' => strtolower($input['importId']), 'preset' => $input['preset']];
        [$analysis, $queuedWork] = app(LabTourAnalyses::class)->create($this->project($request), $input);

        return response()->json(['data' => $records->dto($analysis, kick: false)], $queuedWork ? 202 : 200);
    }

    /** E3: the record; may kick stale monitors (9.8). */
    public function show(Request $request, string $id, LabTourRecords $records): JsonResponse
    {
        $this->enabled();

        return response()->json(['data' => $records->dto($this->analysis($request, $id))]);
    }

    /** E4: progress (< 2 KiB); may kick stale monitors (9.8). */
    public function progress(Request $request, string $id, LabTourRecords $records): JsonResponse
    {
        $this->enabled();

        return response()->json(['data' => $records->progressDto($this->analysis($request, $id))]);
    }

    /** E5: replace the stored delta of each listed capture (7.2). */
    public function selection(Request $request, string $id, LabTourCapabilities $capabilities): JsonResponse
    {
        $this->enabled();
        $analysis = $this->analysis($request, $id);
        $input = $this->input($request, ['preset', 'baseRevision', 'changes'], 'selection', [
            'preset' => ['required', 'string', Rule::in(LabTourCapabilities::PRESETS)],
            'baseRevision' => self::REVISION_RULE,
            'changes' => ['required', 'array', 'list', 'min:1', 'max:80'],
            'changes.*' => ['required', 'array:caseId,add,remove'],
            'changes.*.caseId' => LabTourErrors::uuidRules(),
            'changes.*.add' => ['present', 'array', 'list', 'max:24'],
            'changes.*.remove' => ['present', 'array', 'list', 'max:24'],
            'changes.*.add.*' => self::SHORT_ID_RULE,
            'changes.*.remove.*' => self::SHORT_ID_RULE,
        ]);
        // Laravel's integer and array rules accept "3" and {"0": ...}; the reader's JSON types do not (10.2).
        $raw = json_decode((string) $request->getContent(), false);
        $typed = $raw instanceof \stdClass && is_int($raw->baseRevision ?? null) && is_array($raw->changes ?? null);
        foreach ($typed ? $raw->changes : [] as $change) {
            $typed = $typed && $change instanceof \stdClass && is_array($change->add ?? null) && is_array($change->remove ?? null);
        }
        if (! $typed) throw LabTourErrors::unprocessable('tour_invalid_type');
        $changes = array_map(fn (array $c) => ['caseId' => strtolower($c['caseId']), 'add' => array_values($c['add']), 'remove' => array_values($c['remove'])],
            array_values($input['changes']));
        $caseIds = array_column($changes, 'caseId');
        if (count($caseIds) !== count(array_unique($caseIds))) throw LabTourErrors::unprocessable('tour_duplicate_change');
        foreach ($changes as $change) if (array_intersect($change['add'], $change['remove'])) throw LabTourErrors::unprocessable('tour_duplicate_change');
        $input = ['preset' => $input['preset'], 'baseRevision' => $input['baseRevision'], 'changes' => $changes];
        // Facts may read or write the capabilities cache, so they are computed before the write transaction (7.2).
        $facts = $capabilities->liveFacts($input['preset']);

        return response()->json(['data' => app(LabTourSelections::class)->write($analysis, $input, $facts)]);
    }

    /** E6: 202 run created (status creating, workflows []), 200 replay (9.1). */
    public function runs(Request $request, string $id, LabTourRecords $records): JsonResponse
    {
        $this->enabled();
        $analysis = $this->analysis($request, $id);
        $input = $this->input($request, ['requestId', 'preset', 'selectionRevision', 'selectionSha256'], 'default', [
            'requestId' => LabTourErrors::uuidRules(),
            'preset' => ['required', 'string', Rule::in(LabTourCapabilities::PRESETS)],
            'selectionRevision' => self::REVISION_RULE,
            'selectionSha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        if (! is_int($input['selectionRevision'])) throw LabTourErrors::unprocessable('tour_invalid_type');
        $input = ['requestId' => strtolower($input['requestId']), 'preset' => $input['preset'], 'selectionRevision' => $input['selectionRevision'],
            'selectionSha256' => strtolower($input['selectionSha256'])];
        [$run, $created] = app(LabTourRuns::class)->create($analysis, $input);

        return response()->json(['data' => $records->runDto($analysis, $run->id)], $created ? 202 : 200);
    }

    /** E7: "Use this edit anyway" or Undo; the updated run (10.8, D13). */
    public function accept(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        $analysis = $this->analysis($request, $id);
        $input = $this->input($request, ['runId', 'caseId', 'accepted'], 'default', [
            'runId' => LabTourErrors::uuidRules(), 'caseId' => LabTourErrors::uuidRules(), 'accepted' => ['required', 'boolean']]);
        if (! is_bool($input['accepted'])) throw LabTourErrors::unprocessable('tour_invalid_type');
        $input = ['runId' => strtolower($input['runId']), 'caseId' => strtolower($input['caseId']), 'accepted' => $input['accepted']];

        return response()->json(['data' => app(LabTourAcceptances::class)->accept($analysis, $input)]);
    }

    /** E8: cancel the tour layer's own work; idempotent; the body must be {} (9.6). */
    public function cancel(Request $request, string $id, LabTourRecords $records): JsonResponse
    {
        $this->enabled();
        $analysis = $this->analysis($request, $id);
        $this->input($request, [], 'default', []);
        $analysis = app(LabTourAnalyses::class)->cancel($analysis);

        return response()->json(['data' => $records->dto($analysis, kick: false)]);
    }

    private function enabled(): void
    {
        abort_unless(LabTourConfig::analysisEnabled(), 404);
    }

    private function project(Request $request): IguideLabProject
    {
        return $request->attributes->get('iguide_lab_project');
    }

    /** 404 across projects (pattern IguideLabWorkflowController.php:40-43); the path UUID is lowercased first (10.2). */
    private function analysis(Request $request, string $id): IguideLabTourAnalysis
    {
        return IguideLabTourAnalysis::where('project_id', $this->project($request)->id)->findOrFail(strtolower($id));
    }

    /**
     * Body limit (413), then the unknown-key guard (422 tour_unsupported_field; any query parameter counts, even one
     * with an allowed name), then Laravel validation (10.2).
     */
    private function input(Request $request, array $allowed, string $limit, array $rules): array
    {
        if (strlen((string) $request->getContent()) > LabTourConfig::bodyLimit($limit)) throw LabTourErrors::unprocessable('tour_body_too_large');
        if ($request->query->count() > 0 || array_diff(array_map('strval', array_keys($request->all())), $allowed)) {
            throw LabTourErrors::unprocessable('tour_unsupported_field');
        }

        return $rules === [] ? [] : $request->validate($rules);
    }
}
