<?php
namespace App\Services\IguideLab;
use App\Jobs\ProcessIguideLabGrassSourceAdmission;
use App\Models\IguideLabProject;
use App\Models\IguideLabGrassSourceAdmission;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Internal-only authority. No HTTP route can supply a successful material assessment. */
class LabGrassSourceAdmissions
{
    public function ready(): bool { return config('iguide-lab.grass_source_admission_enabled', false) === true && app(LabCredentials::class)->ready() && app(LabGrassSourceAdmissionBridge::class)->ready(); }
    public function reserve(IguideLabWorkflowItem $item, ?string $priorAdmissionId = null): IguideLabWorkflowStep
    {
        $workflow = IguideLabWorkflow::where('project_id', $item->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabGrassSourceAdmissionPolicy::enabled($workflow->policy, $item->preset), 422, 'The workflow has no grass assessment budget.');
        abort_unless($this->ready(), 503, 'Validated material assessment is unavailable.');
        $snapshot = app(LabGrassSourceAdmissionContext::class)->capture($item, $priorAdmissionId);
        return app(LabWorkflowSteps::class)->reserve($item, 'grass-source-admission-'.($priorAdmissionId === null ? '0' : '1'), 'analysis',
            ['protocolVersion' => LabGrassSourceAdmissionPolicy::VERSION, 'priorAdmissionId' => $priorAdmissionId,
                'contextSnapshot' => $snapshot, 'recipe' => app(LabGrassSourceAdmissionBridge::class)->snapshot()]);
    }
    public function start(IguideLabWorkflowStep $step): IguideLabGrassSourceAdmission
    {
        if ($prior = IguideLabGrassSourceAdmission::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first()) {
            abort_unless($prior->step_id === $step->id && $prior->request_hash === $step->input_sha256, 409);
            return $this->reconcileReservation($step, $prior);
        }
        $this->verifyStep($step); $bridge = app(LabGrassSourceAdmissionBridge::class); $input = $step->input;
        $id = $step->request_id; app(LabGrassSourceAdmissionStorage::class)->prepareAssessment($step->project_id, $id);
        $base = 'iguide-lab/'.$step->project_id.'/grass-source-admissions/'.$id;
        $context = $bridge->json($base.'/context.json', $input['contextSnapshot']['context']);
        $compiled = $this->compile($step, $base, $context); $payload = $bridge->output($step->project_id, $compiled, 'payload', 36 * 1024 * 1024);
        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($step, $input, $id, $context, $payload, $base) {
            IguideLabProject::whereKey($step->project_id)->update(['updated_at' => now()]);
            $invocation = app(LabWorkflowSteps::class)->invocation($step);
            app(LabWorkflowInvocation::class)->verify($invocation, $step->project_id, $step->request_id, 'analysis', false);
            if ($prior = IguideLabGrassSourceAdmission::where('project_id', $step->project_id)->where('request_id', $step->request_id)->first()) {
                abort_unless($prior->request_hash === $step->input_sha256 && $prior->step_id === $step->id, 409); return [$prior, false];
            }
            abort_if(IguideLabGrassSourceAdmission::where('project_id', $step->project_id)->count() >= 120
                || IguideLabGrassSourceAdmission::where('created_at', '>=', now()->subDay())->count() >= 120, 429, 'Assessment safety ceiling reached.');
            return [IguideLabGrassSourceAdmission::create(['id' => $id, 'step_id' => $step->id, 'project_id' => $step->project_id,
                'request_id' => $step->request_id, 'request_hash' => $step->input_sha256, 'status' => 'queued',
                'data' => ['workflowInvocation' => $invocation, 'context' => $context, 'payload' => $payload, 'base' => $base,
                    'contextSnapshot' => $input['contextSnapshot'], 'recipe' => $input['recipe'], 'providerBodySha256' => $payload['sha256']]]), true];
        }), 'iguide-lab.grass-source-admission-reserve');
        return $this->reconcileReservation($step, $record);
    }
    private function reconcileReservation(IguideLabWorkflowStep $step, IguideLabGrassSourceAdmission $record): IguideLabGrassSourceAdmission
    {
        $record->refresh();
        $queue = $record->status === 'queued' && empty($record->provider_attempts) && empty($record->data['providerReceipt']);
        if ($queue) $this->verifyStep($step);
        app(LabWorkflowSteps::class)->attach($step, $record->id);
        // Reservation commit, attachment and queue acknowledgement cannot share a
        // transaction. Reconcile only never-submitted queued records. Duplicate
        // queued jobs share the same lock and atomic submission claim.
        if ($queue) {
            try { ProcessIguideLabGrassSourceAdmission::dispatch($record->id); }
            catch (\Throwable) { $this->attention($record, 'queue_receipt_unconfirmed'); }
        }
        return $record->fresh();
    }
    public function process(IguideLabGrassSourceAdmission $record): void
    {
        $record->refresh(); if (in_array($record->status, ['completed', 'needs_attention'], true)) return;
        if ($record->status === 'submitting' && empty($record->data['providerReceipt'])) { $this->attention($record, 'submission_outcome_unconfirmed'); return; }
        try {
            $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
            $this->verifyStep($step); abort_unless($record->request_hash === $step->input_sha256, 422);
            $bridge = app(LabGrassSourceAdmissionBridge::class); $data = $record->data;
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
                $receipt = app(LabGrassSourceAdmissionProvider::class)->assess($record, $bytes);
                // Durable received state is parsed on a separate bounded tick. A lost
                // submitting receipt still stops; this never re-enters the provider.
                return;
            }
            $this->verifyStep($step); // A cancelled or drifted source cannot authorize machine support after receipt.
            [$receipt, $execution] = $this->verifiedProviderReceipt($record);
            $executionAsset = $bridge->json($data['base'].'/execution.json', $execution);
            $disk = Storage::disk(LabService::DISK); $response = ['path' => $disk->path($receipt['response']['path']), 'sha256' => $receipt['response']['sha256']];
            $report = $bridge->run($record->project_id, $data['base'].'/parse-request.json', ['schemaVersion' => 1, 'operation' => 'parse',
                'outputDirectory' => $disk->path($data['base'].'/parsed'), 'context' => $data['context'], 'response' => $response,
                'execution' => $executionAsset, 'compiledPayload' => $payload], $data['recipe']);
            $asset = $bridge->output($record->project_id, $report, 'assessment'); $result = json_decode(file_get_contents($asset['path']), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(in_array($result['status'] ?? '', ['eligible_for_image', 'needs_detail', 'needs_review'], true)
                && ($result['automaticAcceptance'] ?? true) === false && ($result['segmentationAuthority'] ?? true) === false
                && ($result['authorOrigin'] ?? null) === 'machine', 422);
            $data['resultAsset'] = $asset; $data['execution'] = $execution;
            LockedWrite::run(fn () => $record->update(['status' => 'completed', 'result' => $result, 'result_sha256' => $asset['sha256'],
                'data' => $data, 'completed_at' => now(), 'error_code' => null]), 'iguide-lab.grass-source-admission-complete');
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
        return app(LabGrassSourceAdmissionBridge::class)->run($step->project_id, $base.'/compile-request.json', ['schemaVersion' => 1, 'operation' => 'compile',
            'outputDirectory' => $disk->path($base.'/compiled'), 'context' => $context, 'images' => $images], $step->input['recipe']);
    }
    private function verifyStep(IguideLabWorkflowStep $step): IguideLabWorkflowItem
    {
        abort_unless($step->kind === 'analysis' && ($step->input['protocolVersion'] ?? null) === LabGrassSourceAdmissionPolicy::VERSION
            && $step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422, 'The material reservation changed.');
        $item = IguideLabWorkflowItem::where('project_id', $step->project_id)->findOrFail($step->item_id);
        $workflow = IguideLabWorkflow::where('project_id', $step->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabGrassSourceAdmissionPolicy::enabled($workflow->policy, $item->preset), 422, 'Grass assessment policy is unavailable.');
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step), $step->project_id, $step->request_id, 'analysis');
        $bridge = app(LabGrassSourceAdmissionBridge::class); $bridge->validate($step->input['recipe']); abort_unless($this->ready(), 503);
        $fresh = app(LabGrassSourceAdmissionContext::class)->capture($item, $step->input['priorAdmissionId']);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($fresh) === LabMachineSegmentationPlan::canonicalHash($step->input['contextSnapshot']), 422, 'The original source, detail pose or prior admission changed.');
        return $item;
    }
    public function verifySubmission(IguideLabGrassSourceAdmission $record): void
    {
        $record->refresh(); $step = IguideLabWorkflowStep::where('project_id', $record->project_id)->findOrFail($record->step_id);
        $this->verifyStep($step);
        abort_unless($record->request_hash === $step->input_sha256 && $record->status === 'submitting'
            && LabMachineSegmentationPlan::canonicalHash($record->data['contextSnapshot'] ?? []) === LabMachineSegmentationPlan::canonicalHash($step->input['contextSnapshot'])
            && ($record->data['recipe'] ?? null) === $step->input['recipe'], 422, 'The original-source attempt binding changed.');
        $path = 'iguide-lab/'.$record->project_id.'/grass-source-admissions/'.$record->id.'/compiled/payload.json';
        $file = app(LabGrassSourceAdmissionContext::class)->file($record->project_id, $path, $record->data['providerBodySha256'] ?? '', 36 * 1024 * 1024);
        abort_unless(($record->data['payload']['path'] ?? null) === $file && ($record->data['payload']['sha256'] ?? null) === $record->data['providerBodySha256'], 422);
    }
    /** Current source/receipt proof; this does not authorize segmentation. */
    public function verifiedReport(IguideLabGrassSourceAdmission $record, IguideLabWorkflowItem $item): array
    {
        $step = IguideLabWorkflowStep::where('project_id', $item->project_id)->where('item_id', $item->id)->findOrFail($record->step_id);
        $this->verifyStep($step);
        abort_unless($record->project_id === $item->project_id && $record->status === 'completed'
            && $record->request_hash === $step->input_sha256 && $step->child_id === $record->id
            && in_array($record->result['status'] ?? null, ['eligible_for_image', 'needs_detail', 'needs_review'], true)
            && ($record->result['automaticAcceptance'] ?? true) === false && ($record->result['segmentationAuthority'] ?? true) === false, 422);
        $path = $record->data['base'].'/parsed/assessment.json';
        $file = app(LabGrassSourceAdmissionContext::class)->file($record->project_id, $path, $record->result_sha256 ?? '', 1024 * 1024);
        abort_unless(($record->data['resultAsset']['path'] ?? null) === $file
            && ($record->data['resultAsset']['sha256'] ?? null) === $record->result_sha256
            && LabMachineSegmentationPlan::canonicalHash(json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR))
                === LabMachineSegmentationPlan::canonicalHash($record->result), 422, 'The original-source admission changed.');
        [, $execution] = $this->verifiedProviderReceipt($record);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($record->data['execution'] ?? []) === LabMachineSegmentationPlan::canonicalHash($execution)
            && LabMachineSegmentationPlan::canonicalHash($record->result['execution'] ?? []) === LabMachineSegmentationPlan::canonicalHash($execution)
            && ($record->result['contextSha256'] ?? null) === $execution['contextSha256'], 422);
        app(LabGrassSourceAdmissionContext::class)->file($record->project_id, $record->data['base'].'/execution.json',
            hash('sha256', json_encode($execution, JSON_THROW_ON_ERROR)), 65536);
        return $record->result;
    }

    /** Revalidate the retained paid response before parsing and every later SAM authority use. */
    private function verifiedProviderReceipt(IguideLabGrassSourceAdmission $record): array
    {
        $data = $record->data; $base = 'iguide-lab/'.$record->project_id.'/grass-source-admissions/'.$record->id;
        $attempts = $record->provider_attempts; $receipt = $data['providerReceipt'] ?? [];
        abort_unless(($data['base'] ?? null) === $base && is_array($attempts) && array_is_list($attempts)
            && in_array(count($attempts), [1, 2], true), 422, 'The retained assessment attempt ownership changed.');
        $index = count($attempts) - 1; $last = $attempts[$index];
        abort_unless(($last['status'] ?? null) === 'received' && ($last['requestedModelId'] ?? null) === 'gpt-6-astra'
            && is_int($last['httpStatus'] ?? null) && $last['httpStatus'] >= 200 && $last['httpStatus'] < 300
            && ($last['providerRequestId'] ?? null) === $record->provider_request_id
            && ($last['credentialSource'] ?? null) === ($index === 0 ? 'lab-primary' : 'dashboard-fallback'), 422, 'The retained assessment attempt changed.');
        if ($index === 1) abort_unless(($attempts[0]['status'] ?? null) === 'quota_rejected'
            && ($attempts[0]['httpStatus'] ?? null) === 429 && ($attempts[0]['requestedModelId'] ?? null) === 'gpt-6-astra'
            && ($attempts[0]['credentialSource'] ?? null) === 'lab-primary', 422, 'An assessment fallback lacks its explicit quota rejection.');
        $loader = app(LabGrassSourceAdmissionContext::class); $disk = Storage::disk(LabService::DISK);
        $responsePath = $base.'/response-'.$index.'.json';
        abort_unless(($receipt['response']['path'] ?? null) === $responsePath && ($receipt['model'] ?? null) === 'gpt-6-astra', 422, 'The retained assessment receipt path changed.');
        $file = $loader->file($record->project_id, $responsePath, $receipt['response']['sha256'] ?? '', 128 * 1024);
        $raw = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($raw['status'] ?? null) === 'completed' && ($raw['model'] ?? null) === $receipt['model']
            && is_string($raw['id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $raw['id'])
            && $raw['id'] === ($receipt['providerResponseId'] ?? null), 422, 'The retained provider response identity changed.');
        $payloadPath = $base.'/compiled/payload.json'; $payload = $data['payload'] ?? [];
        abort_unless(($payload['path'] ?? null) === $disk->path($payloadPath)
            && ($payload['sha256'] ?? null) === ($data['providerBodySha256'] ?? null), 422, 'The assessment request body association changed.');
        $loader->file($record->project_id, $payloadPath, $payload['sha256'] ?? '', 36 * 1024 * 1024);
        $contextHash = $this->protocolContextHash(['outputs' => ['payload' => $payload]]);
        return [$receipt, ['state' => 'completed', 'requestId' => $record->request_id, 'providerResponseId' => $receipt['providerResponseId'],
            'model' => $receipt['model'], 'contextSha256' => $contextHash, 'requestPayloadSha256' => $payload['sha256'], 'responseSha256' => $receipt['response']['sha256']]];
    }
    public function attention(IguideLabGrassSourceAdmission $record, string $code): void
    {
        LockedWrite::run(fn () => IguideLabGrassSourceAdmission::whereKey($record->id)->where('status', '!=', 'completed')->update([
            'status' => 'needs_attention', 'error_code' => $code, 'completed_at' => now(), 'updated_at' => now()]), 'iguide-lab.grass-source-admission-attention');
    }
}
