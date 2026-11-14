<?php
namespace App\Services\IguideLab;
use App\Jobs\ProcessIguideLabDeclutterRemovalPlan;
use App\Models\IguideLabProject;
use App\Models\IguideLabDeclutterRemovalPlan;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Internal-only authority. No HTTP route can supply a successful source-removal plan. */
class LabDeclutterRemovalPlans
{
    public function ready(): bool { return app(LabCredentials::class)->ready() && app(LabDeclutterRemovalBridge::class)->ready(); }
    public function reserve(IguideLabWorkflowItem $item, string $sectionId, string $sourceAnalysisId): IguideLabWorkflowStep
    {
        $workflow = IguideLabWorkflow::where('project_id', $item->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabDeclutterRemovalPolicy::enabled($workflow->policy, $item->preset), 422, 'The workflow has no declutter removal plan budget.');
        abort_unless($this->ready(), 503, 'Validated source-removal plan is unavailable.');
        $snapshot = app(LabDeclutterRemovalContext::class)->capture($item, $sectionId, $sourceAnalysisId);
        return app(LabWorkflowSteps::class)->reserve($item, 'removal-plan', 'analysis',
            ['protocolVersion' => LabDeclutterRemovalPolicy::protocolVersion($workflow->policy), 'sectionId' => $sectionId, 'sourceAnalysisId' => $sourceAnalysisId,
                'contextSnapshot' => $snapshot, 'recipe' => app(LabDeclutterRemovalBridge::class)->snapshot()]);
    }
    public function start(IguideLabWorkflowStep $step): IguideLabDeclutterRemovalPlan
    {
        if ($prior = IguideLabDeclutterRemovalPlan::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first()) {
            abort_unless($prior->step_id === $step->id && $prior->request_hash === $step->input_sha256, 409);
            return $this->reconcileReservation($step, $prior);
        }
        $this->verifyStep($step); $bridge = app(LabDeclutterRemovalBridge::class); $input = $step->input;
        $id = $step->request_id; app(LabDeclutterStorage::class)->preparePlan($step->project_id, $id);
        $base = 'iguide-lab/'.$step->project_id.'/declutter-removal-plans/'.$id;
        $context = $bridge->json($base.'/context.json', $input['contextSnapshot']['context']);
        $compiled = $this->compile($step, $base, $context); $payload = $bridge->output($step->project_id, $compiled, 'payload', 36 * 1024 * 1024);
        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($step, $input, $id, $context, $payload, $base) {
            IguideLabProject::whereKey($step->project_id)->update(['updated_at' => now()]);
            $invocation = app(LabWorkflowSteps::class)->invocation($step);
            app(LabWorkflowInvocation::class)->verify($invocation, $step->project_id, $step->request_id, 'analysis', false);
            if ($prior = IguideLabDeclutterRemovalPlan::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first()) {
                abort_unless($prior->request_hash === $step->input_sha256 && $prior->step_id === $step->id, 409); return [$prior, false];
            }
            abort_if(IguideLabDeclutterRemovalPlan::where('project_id', $step->project_id)->count() >= 120
                || IguideLabDeclutterRemovalPlan::where('created_at', '>=', now()->subDay())->count() >= 120, 429, 'Assessment safety ceiling reached.');
            return [IguideLabDeclutterRemovalPlan::create(['id' => $id, 'step_id' => $step->id, 'project_id' => $step->project_id,
                'request_id' => $step->request_id, 'request_hash' => $step->input_sha256, 'status' => 'queued',
                'data' => ['workflowInvocation' => $invocation, 'context' => $context, 'payload' => $payload, 'base' => $base,
                    'contextSnapshot' => $input['contextSnapshot'], 'recipe' => $input['recipe'], 'providerBodySha256' => $payload['sha256']]]), true];
        }), 'iguide-lab.declutter-removal-plan-reserve');
        return $this->reconcileReservation($step, $record);
    }
    private function reconcileReservation(IguideLabWorkflowStep $step, IguideLabDeclutterRemovalPlan $record): IguideLabDeclutterRemovalPlan
    {
        $record->refresh();
        $queue = $record->status === 'queued' && empty($record->provider_attempts) && empty($record->data['providerReceipt']);
        if ($queue) $this->verifyStep($step);
        app(LabWorkflowSteps::class)->attach($step, $record->id);
        // Reservation commit, attachment and queue acknowledgement cannot share a
        // transaction. Reconcile only never-submitted queued records. Duplicate
        // queued jobs share the same lock and atomic submission claim.
        if ($queue) {
            try { ProcessIguideLabDeclutterRemovalPlan::dispatch($record->id); }
            catch (\Throwable) { $this->attention($record, 'queue_receipt_unconfirmed'); }
        }
        return $record->fresh();
    }
    public function process(IguideLabDeclutterRemovalPlan $record): void
    {
        $record->refresh(); if (in_array($record->status, ['completed', 'needs_attention'], true)) return;
        if ($record->status === 'submitting' && empty($record->data['providerReceipt'])) { $this->attention($record, 'submission_outcome_unconfirmed'); return; }
        try {
            $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
            $this->verifyStep($step); abort_unless($record->request_hash === $step->input_sha256, 422);
            $bridge = app(LabDeclutterRemovalBridge::class); $data = $record->data;
            abort_unless($data['contextSnapshot'] === $step->input['contextSnapshot'] && $data['recipe'] === $step->input['recipe'], 422);
            $compiled = $this->compile($step, $data['base'], $data['context']); $payload = $bridge->output($record->project_id, $compiled, 'payload', 36 * 1024 * 1024);
            abort_unless($payload === $data['payload'] && $payload['sha256'] === $data['providerBodySha256'], 422);
            $bytes = file_get_contents($payload['path']);
            if (! empty($data['providerReceipt'])) {
                abort_unless(in_array($record->status, ['received', 'submitting'], true), 422);
                $receipt = $data['providerReceipt'];
            } else {
                app(LabWorkflowInvocation::class)->claim($data['workflowInvocation'], $record->project_id, $record->request_id, 'analysis', function () use ($record) {
                    abort_unless($record->fresh()->status === 'queued', 409);
                    $record->update(['status' => 'submitting', 'started_at' => now()]);
                });
                $receipt = app(LabDeclutterRemovalProvider::class)->assess($record, $bytes);
                // Durable received state is parsed on a separate bounded tick. A lost
                // submitting receipt still stops; this never re-enters the provider.
                return;
            }
            $this->verifyStep($step); // A cancelled or drifted source cannot authorize machine support after receipt.
            $execution = $this->providerExecution($record->fresh(), $step);
            $executionAsset = $bridge->json($data['base'].'/execution.json', $execution);
            $disk = Storage::disk(LabService::DISK); $response = ['path' => $disk->path($receipt['response']['path']), 'sha256' => $receipt['response']['sha256']];
            $report = $bridge->run($record->project_id, $data['base'].'/parse-request.json', ['schemaVersion' => 1, 'operation' => 'parse',
                'outputDirectory' => $disk->path($data['base'].'/parsed'), 'context' => $data['context'], 'response' => $response,
                'execution' => $executionAsset, 'compiledPayload' => $payload], $data['recipe']);
            $asset = $bridge->output($record->project_id, $report, 'assessment'); $result = json_decode(file_get_contents($asset['path']), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(in_array($result['status'] ?? '', ['ready_for_segmentation', 'needs_review'], true)
                && ($result['schemaVersion']??null)===$step->input['protocolVersion']
                && ($result['automaticAcceptance'] ?? true) === false && ($result['authorOrigin'] ?? null) === 'machine', 422);
            $data['resultAsset'] = $asset; $data['execution'] = $execution;
            LockedWrite::run(fn () => $record->update(['status' => 'completed', 'result' => $result, 'result_sha256' => $asset['sha256'],
                'data' => $data, 'completed_at' => now(), 'error_code' => null]), 'iguide-lab.declutter-removal-plan-complete');
        } catch (\Throwable $error) {
            $this->attention($record, in_array($record->fresh()->status, ['submitting', 'received'], true) ? 'submitted_assessment_unconfirmed_or_rejected' : 'assessment_preflight_rejected');
        }
    }
    private function protocolContextHash(array $compiled): string
    {
        $payload = json_decode(file_get_contents($compiled['outputs']['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $text = json_decode($payload['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_string($text['contextSha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $text['contextSha256']), 422);
        return $text['contextSha256'];
    }
    private function compile(IguideLabWorkflowStep $step, string $base, array $context): array
    {
        $disk = Storage::disk(LabService::DISK); $images = array_map(fn ($asset) => ['id' => $asset['id'], 'path' => $disk->path($asset['path']), 'sha256' => $asset['sha256']], $step->input['contextSnapshot']['assets']);
        return app(LabDeclutterRemovalBridge::class)->run($step->project_id, $base.'/compile-request.json', ['schemaVersion' => 1, 'operation' => 'compile',
            'outputDirectory' => $disk->path($base.'/compiled'), 'context' => $context, 'images' => $images], $step->input['recipe']);
    }
    private function verifyStep(IguideLabWorkflowStep $step): IguideLabWorkflowItem
    {
        abort_unless($step->kind === 'analysis' && in_array($step->input['protocolVersion'] ?? null,[LabDeclutterRemovalPolicy::VERSION,LabDeclutterRemovalPolicy::MATERIALS_VERSION],true)
            && $step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422, 'The source-removal reservation changed.');
        $item = IguideLabWorkflowItem::where('project_id', $step->project_id)->findOrFail($step->item_id);
        $workflow = IguideLabWorkflow::where('project_id', $step->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabDeclutterRemovalPolicy::enabled($workflow->policy, $item->preset), 422, 'Declutter removal plan policy is unavailable.');
        abort_unless(($step->input['protocolVersion']??null)===LabDeclutterRemovalPolicy::protocolVersion($workflow->policy)
            &&($step->input['contextSnapshot']['context']['schemaVersion']??null)===$step->input['protocolVersion'],422,'Removal protocol and reserved policy differ.');
        abort_unless(!LabDeclutterRemovalPolicy::materials($workflow->policy)||config('iguide-lab.declutter_materials_enabled',false)===true,503,'Material-aware removal is disabled.');
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step), $step->project_id, $step->request_id, 'analysis');
        $bridge = app(LabDeclutterRemovalBridge::class); $bridge->validate($step->input['recipe']); abort_unless($this->ready(), 503);
        $fresh = app(LabDeclutterRemovalContext::class)->capture($item, $step->input['sectionId'], $step->input['sourceAnalysisId']);
        // JSON persistence normalizes integral floats (0.0 -> 0); compare the
        // actual persisted representation, retaining all values and source hashes.
        abort_unless(LabAnalysisRecipes::hash($fresh) === LabAnalysisRecipes::hash($step->input['contextSnapshot']), 422, 'The actual source or parent context changed.');
        return $item;
    }
    /** Revalidate typed source, recipe and payload before every primary/quota claim. */
    public function verifyForAttempt(IguideLabDeclutterRemovalPlan $record,string $payload): void
    {
        $record->refresh();$step=IguideLabWorkflowStep::where('project_id',$record->project_id)->findOrFail($record->step_id);
        $this->verifyStep($step);$data=$record->data;
        abort_unless($record->status==='submitting'&&$record->request_hash===$step->input_sha256
            &&LabMachineSegmentationPlan::canonicalHash($data['contextSnapshot']??[])===LabMachineSegmentationPlan::canonicalHash($step->input['contextSnapshot'])
            &&($data['recipe']??null)===$step->input['recipe']&&hash('sha256',$payload)===($data['providerBodySha256']??null),422,'The reserved removal attempt changed.');
        $path='iguide-lab/'.$record->project_id.'/declutter-removal-plans/'.$record->id.'/compiled/payload.json';
        $actual=app(LabDeclutterRemovalContext::class)->file($record->project_id,$path,$data['providerBodySha256'],36*1024*1024);
        abort_unless(($data['payload']['path']??null)===$actual&&($data['payload']['sha256']??null)===$data['providerBodySha256'],422);
    }
    public function reserveSegmentationSteps(IguideLabDeclutterRemovalPlan $record): array
    {
        [$assessmentStep, $item] = $this->completed($record); $out = [];
        foreach ($record->result['segmentationPlan'] as $logical) {
            abort_unless(in_array($logical['stepKey'], ['target_object', 'target_local_shadow'], true), 422);
            $out[$logical['stepKey']] = app(LabWorkflowSteps::class)->reserve($item, $logical['stepKey'].'-'.$record->id, 'segmentation',
                ['protocolVersion' => LabDeclutterRemovalPolicy::SEGMENTATION, 'assessmentStepId' => $assessmentStep->id,
                    'sectionId' => $assessmentStep->input['sectionId'], 'sourceAnalysisId' => $assessmentStep->input['sourceAnalysisId'], 'logicalPlan' => $logical]);
        }
        abort_unless(array_keys($out) === array_column($record->result['segmentationPlan'], 'stepKey') && in_array(count($out), [1,2], true), 422);
        return $out; // The optional shadow role exists only when declared by source evidence.
    }
    /** Internal image/support callers receive current authority, never client assertions. */
    public function verified(IguideLabDeclutterRemovalPlan $record): array { return $this->completed($record); }
    /** Called again for both initial SAM planning and every resumed receipt association. */
    public function forSegmentation(IguideLabWorkflowStep $step): array
    {
        abort_unless($step->kind === 'segmentation' && ($step->input['protocolVersion'] ?? null) === LabDeclutterRemovalPolicy::SEGMENTATION
            && $step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422);
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step), $step->project_id, $step->request_id, 'segmentation');
        $assessmentStep = IguideLabWorkflowStep::where('project_id', $step->project_id)->where('item_id', $step->item_id)->findOrFail($step->input['assessmentStepId']);
        $record = IguideLabDeclutterRemovalPlan::where('project_id', $step->project_id)->where('step_id', $assessmentStep->id)->firstOrFail();
        $this->completed($record);
        abort_unless($record->id === $assessmentStep->child_id && $step->input['sectionId'] === $assessmentStep->input['sectionId']
            && $step->input['sourceAnalysisId'] === $assessmentStep->input['sourceAnalysisId'], 422, 'Segmentation assessment ownership changed.');
        $key = $step->input['logicalPlan']['stepKey'] ?? null;
        $logical = collect($record->result['segmentationPlan'])->firstWhere('stepKey', $key);
        abort_unless($logical !== null && LabMachineSegmentationPlan::canonicalHash($logical) === LabMachineSegmentationPlan::canonicalHash($step->input['logicalPlan']), 422, 'The machine segmentation proposal changed.');
        $reservations = [];
        foreach (array_column($record->result['segmentationPlan'], 'stepKey') as $name) {
            $child = IguideLabWorkflowStep::where('project_id', $step->project_id)->where('item_id', $step->item_id)
                ->where('step_key', $name.'-'.$record->id)->where('kind', 'segmentation')->sole();
            abort_unless($child->input_sha256 === LabAnalysisRecipes::hash($child->input) && ($child->input['assessmentStepId'] ?? null) === $assessmentStep->id, 422);
            $reservations[$name] = $child->request_id;
        }
        abort_unless(($reservations[$key] ?? null) === $step->request_id, 422);
        $bridge = app(LabDeclutterRemovalBridge::class); $disk = Storage::disk(LabService::DISK); $base = $record->data['base'];
        $reservedAsset = $bridge->json($base.'/sam-reservations.json', $reservations);
        $report = $bridge->run($step->project_id, $base.'/bind-plans-request.json', ['schemaVersion' => 1, 'operation' => 'bind-plans',
            'outputDirectory' => $disk->path($base.'/bound-plans'), 'assessment' => $record->data['resultAsset'], 'reservations' => $reservedAsset], $record->data['recipe']);
        $plansAsset = $bridge->output($step->project_id, $report, 'plans'); $plans = json_decode(file_get_contents($plansAsset['path']), true, flags: JSON_THROW_ON_ERROR);
        $bound = collect($plans)->firstWhere('stepKey', $key); abort_unless($bound && $bound['requestId'] === $step->request_id, 422);
        $inputId = 'source_cut';
        $asset = collect($record->data['contextSnapshot']['assets'])->firstWhere('id', $inputId);
        $path = app(LabDeclutterRemovalContext::class)->file($step->project_id, $asset['path'], $asset['sha256'], 32 * 1024 * 1024);
        abort_unless($bound['inputSha256'] === $asset['sha256'], 422);
        return ['assessmentId' => $record->id, 'assessmentStepId' => $assessmentStep->id, 'assessmentSha256' => $record->result_sha256,
            'logicalPlan' => $logical, 'boundPlan' => $bound, 'inputPath' => $path, 'inputSha256' => $asset['sha256'], 'sourceRole' => $inputId];
    }
    public function associate(IguideLabWorkflowStep $step, array $receipt): array
    {
        $trusted = $this->forSegmentation($step); // Receipt resume never bypasses current trusted source verification.
        $record = IguideLabDeclutterRemovalPlan::findOrFail($trusted['assessmentId']); $base = $record->data['base'];
        $bridge = app(LabDeclutterRemovalBridge::class); $disk = Storage::disk(LabService::DISK);
        $plan = $bridge->json($base.'/sam-'.$step->id.'-plan.json', $trusted['boundPlan']);
        $savedReceipt = $bridge->json($base.'/sam-'.$step->id.'-receipt.json', $receipt);
        $report = $bridge->run($step->project_id, $base.'/associate-'.$step->id.'-request.json', ['schemaVersion' => 1, 'operation' => 'associate',
            'outputDirectory' => $disk->path($base.'/association-'.$step->id), 'step' => $plan, 'receipt' => $savedReceipt], $record->data['recipe']);
        $asset = $bridge->output($step->project_id, $report, 'association');
        return json_decode(file_get_contents($asset['path']), true, flags: JSON_THROW_ON_ERROR);
    }
    private function completed(IguideLabDeclutterRemovalPlan $record): array
    {
        $record->refresh(); $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
        $item = $this->verifyStep($step);
        abort_unless($record->status === 'completed' && $record->request_hash === $step->input_sha256 && $step->child_id === $record->id
            && ($record->result['schemaVersion']??null)===$step->input['protocolVersion']
            && ($record->result['status'] ?? null) === 'ready_for_segmentation' && ($record->result['reviewReasons'] ?? null) === []
            && ($record->result['automaticAcceptance'] ?? true) === false, 422, 'The persisted source-removal plan does not authorize support.');
        $path = $record->data['base'].'/parsed/assessment.json';
        $file = app(LabDeclutterRemovalContext::class)->file($record->project_id, $path, $record->result_sha256 ?? '', 1024 * 1024);
        abort_unless(($record->data['resultAsset']['path'] ?? null) === $file
            && ($record->data['resultAsset']['sha256'] ?? null) === $record->result_sha256
            && LabMachineSegmentationPlan::canonicalHash(json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR))
                === LabMachineSegmentationPlan::canonicalHash($record->result), 422, 'The persisted machine assessment changed.');
        $this->verifyCompletedReceipt($record, $step);
        return [$step, $item];
    }
    private function providerExecution(IguideLabDeclutterRemovalPlan $record, IguideLabWorkflowStep $step): array
    {
        $data = $record->data; $attempts = $record->provider_attempts ?? [];
        $base = 'iguide-lab/'.$record->project_id.'/declutter-removal-plans/'.$record->id;
        abort_unless(($data['base'] ?? null) === $base && is_array($attempts) && array_is_list($attempts)
            && in_array(count($attempts), [1, 2], true)
            && LabMachineSegmentationPlan::canonicalHash($data['contextSnapshot'] ?? []) === LabMachineSegmentationPlan::canonicalHash($step->input['contextSnapshot'])
            && ($data['recipe'] ?? null) === $step->input['recipe'], 422, 'The completed assessment receipt binding changed.');
        $index = count($attempts) - 1; $attempt = $attempts[$index];
        abort_unless(($attempt['status'] ?? null) === 'received' && ($attempt['requestedModelId'] ?? null) === 'gpt-6-astra'
            && is_int($attempt['httpStatus'] ?? null) && $attempt['httpStatus'] >= 200 && $attempt['httpStatus'] < 300
            && ($attempt['providerRequestId'] ?? null) === $record->provider_request_id
            && ($attempt['credentialSource'] ?? null) === ($index === 0 ? 'lab-primary' : 'dashboard-fallback'), 422, 'The completed provider attempt changed.');
        if ($index === 1) abort_unless(($attempts[0]['status'] ?? null) === 'quota_rejected' && ($attempts[0]['httpStatus'] ?? null) === 429
            && ($attempts[0]['credentialSource'] ?? null) === 'lab-primary' && ($attempts[0]['requestedModelId'] ?? null) === 'gpt-6-astra', 422, 'The assessment fallback was not quota-authorized.');
        $receipt = $data['providerReceipt'] ?? []; $loader = app(LabDeclutterRemovalContext::class);
        abort_unless(($receipt['response']['path'] ?? null) === $base.'/response-'.$index.'.json'
            && ($receipt['model'] ?? null) === 'gpt-6-astra' && is_string($receipt['providerResponseId'] ?? null)
            && preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $receipt['providerResponseId']), 422, 'The retained assessment response identity changed.');
        $response = $loader->file($record->project_id, $receipt['response']['path'], $receipt['response']['sha256'] ?? '', 128 * 1024);
        $payload = $loader->file($record->project_id, $base.'/compiled/payload.json', $data['providerBodySha256'] ?? '', 36 * 1024 * 1024);
        abort_unless(($data['payload']['path'] ?? null) === $payload && ($data['payload']['sha256'] ?? null) === $data['providerBodySha256'], 422, 'The retained assessment request changed.');
        $raw = json_decode(file_get_contents($response), true, flags: JSON_THROW_ON_ERROR);
        $body = json_decode(file_get_contents($payload), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($raw['status'] ?? null) === 'completed' && ($raw['id'] ?? null) === $receipt['providerResponseId']
            && ($raw['model'] ?? null) === $receipt['model'] && ($body['model'] ?? null) === 'gpt-6-astra' && ($body['store'] ?? null) === false, 422, 'The retained assessment envelope changed.');
        $execution = ['state' => 'completed', 'requestId' => $record->request_id, 'providerResponseId' => $receipt['providerResponseId'],
            'model' => $receipt['model'], 'contextSha256' => $this->protocolContextHash(['outputs' => ['payload' => ['path' => $payload]]]),
            'requestPayloadSha256' => $data['providerBodySha256'], 'responseSha256' => $receipt['response']['sha256']];
        return $execution;
    }
    private function verifyCompletedReceipt(IguideLabDeclutterRemovalPlan $record, IguideLabWorkflowStep $step): void
    {
        $execution=$this->providerExecution($record,$step);$data=$record->data;
        $base='iguide-lab/'.$record->project_id.'/declutter-removal-plans/'.$record->id;
        $loader=app(LabDeclutterRemovalContext::class);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($data['execution'] ?? []) === LabMachineSegmentationPlan::canonicalHash($execution)
            && LabMachineSegmentationPlan::canonicalHash($record->result['execution'] ?? []) === LabMachineSegmentationPlan::canonicalHash($execution), 422, 'The parsed assessment execution changed.');
        $loader->file($record->project_id, $base.'/execution.json', hash('sha256', json_encode($execution, JSON_THROW_ON_ERROR)), 4096);
    }
    public function attention(IguideLabDeclutterRemovalPlan $record, string $code): void
    {
        LockedWrite::run(fn () => IguideLabDeclutterRemovalPlan::whereKey($record->id)->where('status', '!=', 'completed')->update([
            'status' => 'needs_attention', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.declutter-removal-plan-attention');
    }
}
