<?php
namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabWorkflowEvaluation;
use App\Models\IguideLabProject;
use App\Models\IguideLabComposite;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Independent evaluation receipts are not operator reviews or successful scene analyses. */
class LabWorkflowEvaluations
{
    public function ready(): bool
    {
        try { LabVisualQualityProtocol::recipe(); return app(LabCredentials::class)->ready(); }
        catch (Throwable) { return false; }
    }

    /** A completed quality record can authorize repair only with its retained paid receipt and exact evidence. */
    public function verifiedCompleted(IguideLabWorkflowItem $item, IguideLabWorkflowEvaluation $record): array
    {
        $step = IguideLabWorkflowStep::where('project_id', $item->project_id)->where('item_id', $item->id)->findOrFail($record->step_id);
        $data = $record->data; $receipt = $data['responseReceipt'] ?? []; $attempts = $record->provider_attempts ?? [];
        $last = count($attempts) - 1; $attempt = $attempts[$last] ?? [];
        abort_unless($record->status === 'completed' && $record->project_id === $item->project_id && $step->kind === 'evaluation'
            && $step->child_id === $record->id && $step->request_id === $record->request_id
            && $record->request_hash === $step->input_sha256 && $step->input_sha256 === LabAnalysisRecipes::hash($step->input)
            && LabAnalysisRecipes::hash($step->input) === LabAnalysisRecipes::hash($data['evidence'])
            && ($data['workflowInvocation'] ?? null) === app(LabWorkflowSteps::class)->invocation($step)
            && in_array(count($attempts), [1, 2], true) && ($attempt['status'] ?? null) === 'received'
            && ($attempt['requestedModelId'] ?? null) === LabVisualQualityProtocol::MODEL
            && ($attempt['httpStatus'] ?? 0) >= 200 && ($attempt['httpStatus'] ?? 0) < 300
            && ($attempt['providerRequestId'] ?? null) === $record->provider_request_id
            && ($receipt['providerRequestId'] ?? null) === $record->provider_request_id
            && is_string($record->provider_request_id) && $record->provider_request_id !== '', 422, 'The quality receipt is not confirmed.');
        abort_unless(($attempts[0]['credentialSource'] ?? null) === 'lab-primary'
            && ($attempts[0]['requestedModelId'] ?? null) === LabVisualQualityProtocol::MODEL
            && ($last === 0 || (($attempts[0]['status'] ?? null) === 'quota_rejected' && ($attempts[0]['httpStatus'] ?? null) === 429
                && ($attempt['credentialSource'] ?? null) === 'dashboard-fallback')), 422, 'Unexpected quality provider attempt history.');
        $path = 'iguide-lab/'.$item->project_id.'/workflow-evaluations/'.$record->id.'/response-'.$last.'.json';
        $disk = Storage::disk(LabService::DISK);
        abort_unless(($receipt['path'] ?? null) === $path && ($receipt['attemptIndex'] ?? null) === $last
            && $disk->exists($path) && ! is_link($disk->path($path)) && $disk->size($path) <= 2 * 1024 * 1024
            && hash_equals($receipt['sha256'] ?? '', hash_file('sha256', $disk->path($path))), 422, 'The retained quality response changed.');
        $raw = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($raw['status'] ?? null) === 'completed' && is_string($raw['id'] ?? null) && $raw['id'] !== ''
            && ($raw['model'] ?? null) === LabVisualQualityProtocol::MODEL
            && $raw['id'] === ($receipt['responseId'] ?? null) && $raw['model'] === ($receipt['responseModel'] ?? null)
            && ($data['recipeSha256'] ?? null) === LabVisualQualityProtocol::recipeSha256()
            && ($data['parserSha256'] ?? null) === hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()), 422, 'The quality execution identity changed.');
        $payload = $this->payload($step->input, $item->project_id, $item->id);
        abort_unless(LabAnalysisRecipes::hash($payload) === ($data['providerBodySha256'] ?? null)
            && $data['providerBodySha256'] === ($receipt['providerBodySha256'] ?? null), 422, 'The quality payload association changed.');
        $result = LabVisualQualityProtocol::parse($raw, $step->input['context']);
        abort_unless(LabAnalysisRecipes::hash($result) === LabAnalysisRecipes::hash($record->result), 422, 'The parsed quality result changed.');
        return $result;
    }

    public function start(IguideLabWorkflowStep $step): IguideLabWorkflowEvaluation
    {
        $invocation = app(LabWorkflowSteps::class)->invocation($step);
        app(LabWorkflowInvocation::class)->verify($invocation, $step->project_id, $step->request_id, 'evaluation');
        abort_unless(hash_equals($step->input_sha256, LabAnalysisRecipes::hash($step->input)), 422, 'The reserved evaluation evidence changed.');
        $payload = $this->payload($step->input, $step->project_id, $step->item_id);
        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($step, $invocation, $payload) {
            IguideLabProject::whereKey($step->project_id)->update(['updated_at' => now()]);
            $prior = IguideLabWorkflowEvaluation::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first();
            if ($prior) { abort_unless(hash_equals($prior->request_hash, $step->input_sha256), 409); return [$prior, false]; }
            app(LabWorkflowInvocation::class)->verify($invocation, $step->project_id, $step->request_id, 'evaluation', false);
            abort_unless($this->ready(), 503, 'Automatic visual evaluation is unavailable.');
            abort_if(IguideLabWorkflowEvaluation::where('project_id', $step->project_id)->count() >= 120
                || IguideLabWorkflowEvaluation::where('created_at', '>=', now()->subDay())->count() >= 120, 429, 'The evaluation safety ceiling was reached.');
            return [IguideLabWorkflowEvaluation::create(['project_id' => $step->project_id, 'step_id' => $step->id,
                'request_id' => $step->request_id, 'request_hash' => $step->input_sha256, 'status' => 'queued',
                'data' => ['workflowInvocation' => $invocation, 'evidence' => $step->input,
                    'providerBodySha256' => LabAnalysisRecipes::hash($payload), 'recipeSha256' => LabVisualQualityProtocol::recipeSha256(),
                    'parserSha256' => hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName())]]), true];
        }), 'iguide-lab.workflow-evaluation-reserve');
        if ($created) {
            try { ProcessIguideLabWorkflowEvaluation::dispatch($record->id); }
            catch (Throwable) { $this->attention($record, 'The evaluation queue receipt could not be confirmed.'); }
        }
        return $record->fresh();
    }

    public function process(IguideLabWorkflowEvaluation $record): void
    {
        if (in_array($record->status, ['completed', 'failed', 'needs_attention'], true)) return;
        if ($record->status === 'submitting') { $this->attention($record, 'A paid evaluation response is unconfirmed; it will not be repeated.'); return; }
        try {
            $data = $record->data; $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
            abort_unless(hash_equals($record->request_hash, LabAnalysisRecipes::hash($data['evidence']))
                && hash_equals($data['recipeSha256'], LabVisualQualityProtocol::recipeSha256())
                && hash_equals($data['parserSha256'], hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName())), 422, 'The evaluation implementation changed after reservation.');
            $payload = $this->payload($data['evidence'], $record->project_id, $step->item_id);
            abort_unless(hash_equals($data['providerBodySha256'], LabAnalysisRecipes::hash($payload)), 422, 'The evaluation payload changed.');
            app(LabStorage::class)->prepareEvaluation($record->project_id, $record->id);
            $claimed = app(LabWorkflowInvocation::class)->claim($data['workflowInvocation'], $record->project_id, $record->request_id, 'evaluation',
                fn () => IguideLabWorkflowEvaluation::whereKey($record->id)->where('status', 'queued')->update(['status' => 'submitting', 'started_at' => now(), 'updated_at' => now()]));
            if (! $claimed) { $this->attention($record, 'A previous evaluation submission is unconfirmed.'); return; }
            $record->refresh(); $response = app(LabWorkflowEvaluationProvider::class)->evaluate($record, $payload);
            $result = LabVisualQualityProtocol::parse($response, $data['evidence']['context']);
            LockedWrite::run(fn () => $record->update(['status' => 'completed', 'result' => $result, 'completed_at' => now(), 'error' => null]), 'iguide-lab.workflow-evaluation-complete');
        } catch (OpenAiImageException $error) {
            $this->attention($record, $error->ambiguous ? 'The evaluation response is unconfirmed; no automatic retry was sent.' : 'The evaluator declined this request.', $error->ambiguous ? 'needs_attention' : 'failed');
        } catch (Throwable) { $this->attention($record, 'The evidence or structured evaluation needs review. No paid request will be repeated.'); }
    }

    /** Revalidates private evidence immediately before submission, never trusts caller image URLs. */
    public function payload(array $evidence, string $projectId, string $itemId): array
    {
        $disk = Storage::disk(LabService::DISK); $images = [];
        $item = IguideLabWorkflowItem::where('project_id', $projectId)->findOrFail($itemId);
        app(LabWorkflowSources::class)->verify($projectId, $item->source);
        abort_unless(($evidence['context']['sourceSha256'] ?? null) === $item->source['sha256'], 422, 'Evaluation source mismatch.');
        $intent = $this->intent($item);
        abort_unless(($evidence['context']['intentSha256'] ?? null) === LabAnalysisRecipes::hash($intent)
            && ($evidence['context']['intent'] ?? null) === json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            && ($evidence['context']['preset'] ?? null) === $item->preset && ($evidence['context']['style'] ?? null) === $item->style, 422, 'Evaluation must use the original requested intent.');
        $composite = IguideLabComposite::where('project_id', $projectId)->where('case_id', $item->case_id)->where('status', 'completed')->findOrFail($evidence['compositeId'] ?? '');
        $kind = $composite->native_path ? 'native' : 'preview';
        $candidatePath = $kind === 'native' ? $composite->native_path : $composite->preview_path;
        $canonical = 'iguide-lab/'.$projectId.'/composites/'.$composite->id.'-'.$kind.'.png';
        abort_unless($candidatePath === $canonical && ! is_link($disk->path($candidatePath))
            && $disk->exists($candidatePath) && filesize($disk->path($candidatePath)) <= 128 * 1024 * 1024
            && hash_equals($composite->manifest[$kind]['sha256'], hash_file('sha256', $disk->path($candidatePath)))
            && ($evidence['context']['candidateSha256'] ?? null) === $composite->manifest[$kind]['sha256'], 422, 'Evaluation candidate changed.');
        $root = realpath($disk->path('iguide-lab/'.$projectId.'/workflow-items/'.$itemId));
        abort_unless($root, 422);
        $manifestPath = $disk->path($evidence['manifestPath'] ?? ''); $manifestReal = realpath($manifestPath);
        abort_unless($manifestReal && ! is_link($manifestPath) && str_starts_with($manifestReal, $root.DIRECTORY_SEPARATOR)
            && filesize($manifestReal) <= 65536 && hash_equals($evidence['context']['evidenceManifestSha256'], hash_file('sha256', $manifestReal)), 422, 'The evidence manifest changed.');
        $manifest = json_decode(file_get_contents($manifestReal), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($manifest['sourceSha256'] ?? null) === $item->source['sha256'] && ($manifest['candidateSha256'] ?? null) === $evidence['context']['candidateSha256'], 422, 'The evidence manifest source association changed.');
        foreach ($evidence['assets'] ?? [] as $asset) {
            $path = $disk->path($asset['path']); $real = realpath($path);
            abort_unless($real && ! is_link($path) && str_starts_with($real, $root.DIRECTORY_SEPARATOR)
                && filesize($real) <= 24 * 1024 * 1024, 422, 'Evaluation evidence is not privately owned.');
            $bytes = file_get_contents($real);
            abort_unless(hash_equals($asset['sha256'], hash('sha256', $bytes)), 422, 'Evaluation evidence changed.');
            $images[] = 'data:image/png;base64,'.base64_encode($bytes);
        }
        return LabVisualQualityProtocol::request($evidence['context'], $images);
    }

    /** Original user intent plus only constraints from the immutable server recipe. */
    public function intent(IguideLabWorkflowItem $item): array
    {
        $intent=['sourceSha256'=>$item->source['sha256'],'preset'=>$item->preset,'style'=>$item->style,'instructions'=>$item->instructions];
        if($item->preset==='interior-design'){
            $workflow=\App\Models\IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
            abort_unless(LabInteriorDesignPolicy::enabled($workflow->policy,$item->preset),422,'Material evaluation recipe is unavailable.');
            app(LabInteriorDesignWorkflow::class)->validateSnapshot($workflow->policy['interiorWorkflow']);
            $intent['fixedRecipeConstraints']=LabInteriorDesignArtifacts::REVIEW_CONSTRAINTS;
        }
        if($item->preset==='staging'){
            $workflow=\App\Models\IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
            abort_unless(LabStagingAssessmentPolicy::enabled($workflow->policy,$item->preset),422,'Staging evaluation recipe is unavailable.');
            app(LabStagingWorkflow::class)->validateSnapshot($workflow->policy['stagingWorkflow']);
            $intent['fixedRecipeConstraints']=LabStagingArtifacts::REVIEW_CONSTRAINTS;
        }
        if($item->preset==='declutter'){
            $workflow=\App\Models\IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
            if(LabDeclutterRemovalPolicy::materials($workflow->policy)){
                app(LabDeclutterWorkflow::class)->validateSnapshot($workflow->policy['declutterWorkflow']);
                $intent['fixedRecipeConstraints']=LabDeclutterArtifacts::MATERIALS_REVIEW_CONSTRAINTS;
            }
        }
        return $intent;
    }

    public function attention(IguideLabWorkflowEvaluation $record, string $message, string $status = 'needs_attention'): void
    {
        LockedWrite::run(fn () => IguideLabWorkflowEvaluation::whereKey($record->id)->whereNotIn('status', ['completed', 'failed'])->update([
            'status' => $status, 'error' => $message, 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.workflow-evaluation-stop');
    }
}
