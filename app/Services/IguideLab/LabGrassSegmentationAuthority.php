<?php
namespace App\Services\IguideLab;

use App\Contracts\LabMachineSegmentationCapability;
use App\Models\IguideLabGrassAssessment;
use App\Models\IguideLabMachineSegmentation;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use Illuminate\Support\Facades\Storage;

/** Persisted source-sphere authority. The candidate is never a segmentation input. */
class LabGrassSegmentationAuthority implements LabMachineSegmentationCapability
{
    public function plan(IguideLabWorkflowStep $step): array
    {
        return app(LabGrassAssessments::class)->forSegmentation($step)['boundPlan'];
    }
    public function associate(IguideLabWorkflowStep $step, array $receipt): array
    {
        return app(LabGrassAssessments::class)->associate($step, $receipt);
    }
    public function validatePlan(array $plan, string $requestId): void { app(LabGrassSegmentationPlan::class)->validate($plan, $requestId); }
    public function payload(array $plan, string $bytes): array { return app(LabGrassSegmentationPlan::class)->payload($plan, $bytes); }
    public function describeMask(string $bytes): array { return app(LabGrassMaskPixels::class)->describe($bytes); }
    public function readyAssociationStatus(): string { return 'ready_for_source_support'; }
    public function verifyOwnedInputs(IguideLabWorkflowStep $step, array $plan): array
    {
        $trusted = app(LabGrassAssessments::class)->forSegmentation($step);
        abort_unless($trusted['boundPlan'] === $plan && $trusted['sourceRole'] === 'source_panorama', 422, 'Grass source authority changed.');
        $item = IguideLabWorkflowItem::where('project_id', $step->project_id)->findOrFail($step->item_id);
        $native = app(LabWorkflowSources::class)->verify($step->project_id, $item->source);
        $record = IguideLabGrassAssessment::where('project_id', $step->project_id)->findOrFail($trusted['assessmentId']);
        $context = $record->data['contextSnapshot']; $bytes = file_get_contents($trusted['inputPath']);
        $this->payload($plan, $bytes);
        abort_unless(($context['context']['binding']['nativeSourceSha256'] ?? null) === $native->source_sha256
            && ($context['context']['binding']['guidanceSourceSha256'] ?? null) === hash('sha256', $bytes), 422);
        return [$bytes, ['capability' => 'source-sphere-grass/1', 'nativeSource' => $item->source,
            'assessmentId' => $record->id, 'assessmentSha256' => $record->result_sha256,
            'contextSha256' => $plan['contextSha256'], 'assessmentProposalSha256' => $plan['assessmentProposalSha256'],
            'sourcePreparation' => $context['sourcePreparation'], 'sourceSha256' => hash('sha256', $bytes),
            'candidateRunId' => $context['candidateRunId'], 'candidateSha256' => $context['context']['binding']['candidateSha256']]];
    }
    public function prerequisite(IguideLabWorkflowStep $step, array $plan): void
    {
        $trusted = app(LabGrassAssessments::class)->forSegmentation($step);
        abort_unless($trusted['boundPlan'] === $plan, 422);
        $assessment = IguideLabGrassAssessment::where('project_id', $step->project_id)->findOrFail($trusted['assessmentId']);
        // Sequential required roles: a missing/failed lawn cannot authorize exclusion spending.
        foreach ($assessment->result['segmentationPlan'] as $logical) {
            if ($logical['stepKey'] === $plan['stepKey']) return;
            $prior = IguideLabWorkflowStep::where('project_id', $step->project_id)->where('item_id', $step->item_id)
                ->where('step_key', $logical['stepKey'].'-'.$assessment->id)->where('kind', 'segmentation')->sole();
            $this->verifiedReceipt($prior);
        }
        abort(422, 'The grass role was not reserved by this assessment.');
    }
    /** Recheck retained raw masks and exact receipt ownership before reuse or composition. */
    public function verifiedReceipt(IguideLabWorkflowStep $step): array
    {
        $plan = $this->plan($step); $this->validatePlan($plan, $step->request_id);
        [$bytes, $binding] = $this->verifyOwnedInputs($step, $plan); unset($bytes);
        $record = IguideLabMachineSegmentation::where('project_id', $step->project_id)->where('step_id', $step->id)->sole();
        abort_unless($step->child_id === $record->id && $record->status === 'completed' && $record->request_id === $step->request_id
            && $record->request_hash === $step->input_sha256 && ($record->data['plan'] ?? null) === $plan
            && ($record->data['assetBinding'] ?? null) === $binding && ($record->result['maskOrigin'] ?? null) === 'model_segmentation'
            && ($record->result['selectionOrigin'] ?? null) === 'machine' && ($record->result['humanReviewed'] ?? null) === false
            && ($record->result['automaticAcceptance'] ?? null) === false, 422, 'The required source masks are unavailable or changed.');
        $receipt = $record->result['receipt'];
        abort_unless(($receipt['providerRequestId'] ?? null) === $record->provider_request_id && ($receipt['status'] ?? null) === 'completed', 422);
        $base = 'iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;
        $loader = app(LabGrassAssessmentContext::class);
        $loader->file($step->project_id, $base.'/provider-result.json', $receipt['rawResponseSha256'], LabMachineSegmentationTransport::MAX_RESPONSE_BYTES);
        $artifacts = $record->result['artifacts'] ?? [];
        abort_unless(count($artifacts) === count($receipt['masks']) && count($artifacts) === count($plan['expectedIdentities']), 422);
        foreach ($artifacts as $index => $asset) {
            abort_unless(($asset['index'] ?? null) === $index && ($asset['path'] ?? null) === $base.'/mask-'.$index.'.png', 422);
            $path = $loader->file($step->project_id, $asset['path'], $asset['sha256'], LabMachineSegmentationTransport::MAX_MASK_BYTES);
            abort_unless($this->describeMask(file_get_contents($path)) === $receipt['masks'][$index], 422, 'Decoded source mask changed.');
        }
        $association = $this->associate($step, $receipt);
        abort_unless(($association['status'] ?? null) === $this->readyAssociationStatus()
            // Database JSON normalizes a Python numeric 1.0 to PHP integer 1.
            // Canonical JSON still binds every value without treating that representation change as drift.
            && LabMachineSegmentationPlan::canonicalHash($association) === LabMachineSegmentationPlan::canonicalHash($record->result['association']), 422, 'The machine mask association changed.');
        return ['plan' => $plan, 'receipt' => $receipt, 'association' => $association, 'artifacts' => $artifacts, 'recordId' => $record->id];
    }
    public function support(IguideLabGrassAssessment $assessment): array
    {
        $service = app(LabGrassAssessments::class); $service->completed($assessment);
        $steps = $service->reserveSegmentationSteps($assessment); $bridge = app(LabGrassAssessmentBridge::class);
        $disk = Storage::disk(LabService::DISK); $base = $assessment->data['base'];
        $plans = $receipts = $lineage = $lawn = []; $exclusions = array_fill_keys(array_slice(LabGrassAssessmentPolicy::ROLES, 1), []);
        foreach ($steps as $step) {
            $verified = $this->verifiedReceipt($step); $plan = $verified['plan']; $role = $plan['maskRole'];
            $plans[] = $plan;
            $receipts[] = $bridge->json($base.'/support-'.$step->id.'-receipt.json', $verified['receipt']);
            $lineage[] = ['stepId' => $step->id, 'requestId' => $step->request_id, 'recordId' => $verified['recordId'],
                'role' => $role, 'inputFingerprint' => $plan['inputFingerprint'], 'receiptSha256' => $verified['association']['receiptSha256']];
            foreach ($verified['association']['associations'] as $i => $match) {
                $asset = $verified['artifacts'][$match['maskIndex']];
                $evidence = $bridge->json($base.'/support-'.$step->id.'-'.$i.'-evidence.json', [
                    'inputSha256' => $plan['inputSha256'], 'maskSha256' => $asset['sha256'], 'generationOrigin' => 'model_segmentation',
                    'selectionOrigin' => 'machine', 'providerRequestId' => $verified['receipt']['providerRequestId'],
                    'assessmentProposalSha256' => $plan['assessmentProposalSha256'], 'semanticClassVerified' => false]);
                $mask = ['image' => ['path' => $disk->path($asset['path']), 'sha256' => $asset['sha256']], 'evidence' => $evidence];
                if ($role === 'lawn') $lawn[] = $mask; else $exclusions[$role][] = $mask;
            }
        }
        $planAsset = $bridge->json($base.'/support-plans.json', $plans);
        $report = $bridge->run($assessment->project_id, $base.'/finalize-support-request.json', [
            'schemaVersion' => 1, 'operation' => 'finalize-support', 'outputDirectory' => $disk->path($base.'/finalized'),
            'assessment' => $assessment->data['resultAsset'], 'plans' => $planAsset, 'receipts' => $receipts], $assessment->data['recipe']);
        abort_unless($report['resultStatus'] === $this->readyAssociationStatus(), 422, 'Required source roles are unresolved.');
        return ['assessment' => $bridge->output($assessment->project_id, $report, 'support-assessment'),
            'lawnMasks' => $lawn, 'exclusionMasks' => $exclusions, 'lineage' => $lineage];
    }
}
