<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use Illuminate\Support\Facades\Storage;

/** Server-authored failed-native evidence, never a client URL or a truncated feedback string. */
class LabWorkflowRepairContext
{
    public const VERSION = 'lighting-repair-context/1';
    public const VIEW_RECIPE = 'failed-native-paired7/1';
    public const IDS = ['source', 'front', 'right', 'back', 'left', 'poles', 'wrap'];

    public function reference(IguideLabWorkflowItem $item, IguideLabWorkflowEvaluation $evaluation): array
    {
        $result = app(LabWorkflowEvaluations::class)->verifiedCompleted($item, $evaluation);
        abort_unless($result['qualityStatus'] === 'needs_review', 422, 'A passing result is not repair authority.');
        return ['version' => self::VERSION, 'evaluationId' => $evaluation->id,
            'resultSha256' => LabAnalysisRecipes::hash($result),
            'evidenceSha256' => $evaluation->data['evidence']['context']['evidenceManifestSha256'],
            'responseSha256' => $evaluation->data['responseReceipt']['sha256']];
    }

    public function verify(IguideLabWorkflowItem $item, array $reference): IguideLabWorkflowEvaluation
    {
        $evaluation = IguideLabWorkflowEvaluation::where('project_id', $item->project_id)->findOrFail($reference['evaluationId'] ?? '');
        abort_unless(LabAnalysisRecipes::hash($reference) === LabAnalysisRecipes::hash($this->reference($item, $evaluation)), 422, 'Repair authority changed.');
        return $evaluation;
    }

    public function prepare(IguideLabAnalysis $analysis, IguideLabCase $case): array
    {
        $invocation = $analysis->data['workflowInvocation'] ?? null;
        abort_unless(is_array($invocation), 422, 'Repair context requires a server workflow.');
        app(LabWorkflowInvocation::class)->verify($invocation, $analysis->project_id, $analysis->request_id, 'analysis');
        $item = IguideLabWorkflowItem::where('project_id', $analysis->project_id)->findOrFail($invocation['itemId']);
        abort_unless($item->case_id === $case->id && $case->projection === 'equirectangular'
            && $analysis->source_sha256 === $item->source['sha256'], 422, 'Repair source changed.');
        $evaluation = $this->verify($item, $analysis->data['repairContext']);
        $evidence = $evaluation->data['evidence']; $context = $evidence['context'];
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id;
        app(LabStorage::class)->prepareAnalysis($analysis->project_id, $analysis->id);
        $prepared = LabImages::prepared($disk->get($case->source_path));
        $this->put($base.'/prepared-source.png', $prepared);
        $views = [];
        abort_unless(array_column($context['views'], 'id') === LabVisualQualityProtocol::VIEW_IDS && count($evidence['assets']) === 7, 422, 'Repair requires all seven paired sphere views.');
        foreach ($evidence['assets'] as $i => $asset) {
            $bytes = $disk->get($asset['path']); $view = $context['views'][$i];
            abort_unless(hash('sha256', $bytes) === $view['sha256'] && $asset['sha256'] === $view['sha256']
                && [$view['width'], $view['height']] === [2048, 1024], 422, 'Repair evidence changed.');
            $path = $base.'/'.self::IDS[$i].'.png'; $this->put($path, $bytes);
            $views[] = ['id' => self::IDS[$i], 'evidenceViewId' => $view['id'], 'path' => $path,
                'sha256' => $view['sha256'], 'width' => 2048, 'height' => 1024, 'role' => 'source_left_failed_candidate_right'];
        }
        $manifest = ['version' => self::VERSION, 'sourceSha256' => $case->source_sha256,
            'candidateSha256' => $context['candidateSha256'], 'intentSha256' => $context['intentSha256'],
            'evidenceManifestSha256' => $context['evidenceManifestSha256'], 'authority' => $analysis->data['repairContext'],
            'originalIntent' => app(LabWorkflowEvaluations::class)->intent($item), 'feedback' => $evaluation->result,
            'sameCaptureOnly' => true, 'candidateIsGeometryAuthority' => false,
            'views' => array_map(function ($view) { unset($view['path']); return $view; }, $views)];
        abort_if(strlen(json_encode($manifest, JSON_THROW_ON_ERROR)) > 64 * 1024, 422, 'Repair context exceeds its bound.');
        $this->put($base.'/repair-context.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        return ['preparedSourceSha256' => hash('sha256', $prepared), 'views' => $views,
            'repairContextManifest' => $manifest, 'repairContextManifestSha256' => hash_file('sha256', $disk->path($base.'/repair-context.json')),
            'coordinateConvention' => 'All pairs are SOURCE LEFT and FAILED CANDIDATE RIGHT, from the same native capture. Source halves alone are geometry authority.', 'diagnosticOnly' => true];
    }

    private function put(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) { abort_unless(hash('sha256', $bytes) === hash_file('sha256', $disk->path($path)), 422, 'Immutable repair input changed.'); return; }
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 500);
    }
}
