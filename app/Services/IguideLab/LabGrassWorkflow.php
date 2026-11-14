<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabComposite;
use App\Models\IguideLabGrassAssessment;
use App\Models\IguideLabMachineSegmentation;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;

/** One bounded native turf recolor attempt. No hidden repair, retry or human masks. */
class LabGrassWorkflow
{
    public function snapshot(): array
    {
        $snapshot = ['version' => LabGrassAssessmentPolicy::POLICY, 'driverSha256' => hash_file('sha256', __FILE__),
            'assessment' => app(LabGrassAssessmentBridge::class)->snapshot(), 'artifacts' => app(LabGrassWorkflowArtifacts::class)->snapshot(),
            'segmentationAuthoritySha256' => hash_file('sha256', (new \ReflectionClass(LabGrassSegmentationAuthority::class))->getFileName()),
            'segmentationPlanSha256' => hash_file('sha256', (new \ReflectionClass(LabGrassSegmentationPlan::class))->getFileName()),
            'maskDecoderSha256' => hash_file('sha256', (new \ReflectionClass(LabGrassMaskPixels::class))->getFileName())];
        if (config('iguide-lab.grass_source_admission_enabled', false) === true) $snapshot['sourceAdmission'] = app(LabGrassSourceAdmissionBridge::class)->snapshot();
        return $snapshot;
    }
    public function ready(): bool
    {
        return config('iguide-lab.grass_workflow_enabled', false) === true
            && (config('iguide-lab.grass_source_admission_enabled', false) !== true || app(LabGrassSourceAdmissions::class)->ready())
            && app(LabGrassAssessments::class)->ready() && app(LabGrassWorkflowArtifacts::class)->ready()
            && app(LabMachineSegmentationTransport::class)->ready() && app(LabAnalyses::class)->ready()
            && app(LabWorkflowEvaluations::class)->ready()
            && (collect(LabCatalog::models())->firstWhere('id', 'gpt-image-2.6-sunburst')['ready'] ?? false);
    }
    public function advance(IguideLabWorkflow $workflow, IguideLabWorkflowItem $item, IguideLabProject $project): void
    {
        $state = app(LabWorkflows::class); $steps = app(LabWorkflowSteps::class); $data = $item->data;
        abort_unless(LabGrassAssessmentPolicy::enabled($workflow->policy, $item->preset), 422, 'No grass workflow policy is reserved.');
        abort_unless(($workflow->policy['grassRecipe'] ?? null) === $this->snapshot(), 422, 'Grass implementation changed after reservation.');
        if (trim((string) $item->instructions) !== '') {
            $state->finish($item, 'needs_review', 'grass_custom_scope_unsupported', 'This initial automatic grass recipe recolors all visible established turf. Custom scope or instructions require review.'); return;
        }
        if (! $this->ready()) { $state->finish($item, 'needs_review', 'grass_capability_unavailable', 'Automatic source-bound grass processing is unavailable.'); return; }
        app(LabAnalysisRecipes::class)->validate($workflow->policy['analysisRecipe']);
        abort_unless(($workflow->policy['modelId'] ?? null) === 'gpt-image-2.6-sunburst' && ($workflow->policy['quality'] ?? null) === 'high'
            && ($workflow->policy['complexity'] ?? null) === 'low' && $workflow->policy['evaluationRecipeSha256'] === LabVisualQualityProtocol::recipeSha256()
            && $workflow->policy['evaluationParserSha256'] === hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()), 422);
        $case = app(LabWorkflowSources::class)->verify($project->id, $item->source); $artifacts = app(LabGrassWorkflowArtifacts::class);
        $artifacts->preflight($item, $workflow->policy['grassRecipe']['artifacts']);
        if (LabGrassSourceAdmissionPolicy::enabled($workflow->policy, $item->preset)) {
            if (! $this->sourceAdmission($item, $data)) return;
        } else abort_if(isset($workflow->policy['grassRecipe']['sourceAdmission']), 422, 'Source-admission policy is missing.');
        $analysisInput = ['caseId' => $case->id, 'preset' => 'grass', 'style' => $item->style,
            'instructions' => $item->instructions, 'options' => LabAnalyses::EDIT_DEFAULTS];
        $analysisStep = $steps->reserve($item, 'grass-source-analysis', 'analysis', $analysisInput);
        $analysis = IguideLabAnalysis::where('project_id', $project->id)->where('request_id', $analysisStep->request_id)->first();
        if (! $analysis) $analysis = app(LabAnalyses::class)->start($project, $analysisInput + ['requestId' => $analysisStep->request_id], $steps->invocation($analysisStep));
        else $state->reconcileQueuedChild($analysisStep, $analysis);
        $steps->attach($analysisStep, $analysis->id); $data['analysisId'] = $analysis->id;
        if ($analysis->status !== 'completed') {
            if (in_array($analysis->status, ['failed', 'needs_attention'], true)) $state->finish($item, 'needs_review', 'grass_analysis_unconfirmed', 'The source proposal could not be confirmed.', $data);
            else $state->save($item, 'analyzing', $data);
            return;
        }
        if (($analysis->proposal['workflow']['mode'] ?? null) !== 'whole_panorama') {
            $state->finish($item, 'needs_review', 'grass_route_requires_review', 'This source needs an editing route not supported by the automatic turf recolor recipe.', $data); return;
        }
        $imageInput = ['caseId' => $case->id, 'preset' => 'grass', 'style' => $item->style, 'modelId' => $workflow->policy['modelId'],
            'prompt' => $analysis->proposal['generationPrompt'], 'options' => $analysis->settings['options'] + [
                'analysisId' => $analysis->id, 'promptMode' => 'scene-plan', 'complexity' => 'low']];
        $imageStep = $steps->reserve($item, 'grass-image', 'image', $imageInput);
        $run = IguideLabRun::where('project_id', $project->id)->where('request_id', $imageStep->request_id)->first();
        if (! $run) $run = app(LabService::class)->start($project, $imageInput + ['requestId' => $imageStep->request_id], $steps->invocation($imageStep));
        else $state->reconcileQueuedChild($imageStep, $run);
        $steps->attach($imageStep, $run->id); $data['runId'] = $run->id; $item->attempt_count = 1;
        if ($run->status !== 'completed') {
            if (in_array($run->status, ['failed', 'needs_attention'], true)) $state->finish($item, 'needs_review', 'grass_image_unconfirmed', 'The image result could not be confirmed; no paid duplicate was sent.', $data);
            else $state->save($item, 'editing', $data);
            return;
        }
        $outputSha = $artifacts->runHash($run); $assessments = app(LabGrassAssessments::class);
        $assessmentStep = $assessments->reserve($item, $run->id); $assessment = $assessments->start($assessmentStep);
        $data['grassAssessmentId'] = $assessment->id;
        if ($assessment->status !== 'completed') {
            if ($assessment->status === 'needs_attention') $state->finish($item, 'needs_review', 'grass_assessment_unconfirmed', 'Grass material and source exclusions could not be confirmed.', $data);
            else $state->save($item, 'analyzing', $data);
            return;
        }
        if (($assessment->result['status'] ?? null) !== 'ready_for_source_segmentation') {
            $data['grassAssessmentReasons'] = $assessment->result['reviewReasons'] ?? [];
            $state->finish($item, 'needs_review', 'grass_material_or_optics', 'Source material, exclusions or optical dependencies require review.', $data); return;
        }
        $samSteps = $assessments->reserveSegmentationSteps($assessment); $authority = app(LabGrassSegmentationAuthority::class);
        foreach ($samSteps as $samStep) {
            $segmentation = IguideLabMachineSegmentation::where('project_id', $project->id)->where('request_id', $samStep->request_id)->first();
            if (! $segmentation || $segmentation->status === 'queued') $segmentation = app(LabMachineSegmentations::class)->start($samStep);
            $steps->attach($samStep, $segmentation->id);
            $data['grassMaskRecords'][$samStep->input['logicalPlan']['maskRole']] = $segmentation->id;
            if ($segmentation->status !== 'completed') {
                if (in_array($segmentation->status, ['needs_review', 'needs_attention', 'failed'], true)) $state->finish($item, 'needs_review', 'grass_source_masks_unresolved', 'An original-source mask is missing, ambiguous or unconfirmed. No hidden repair call was sent.', $data);
                else $state->save($item, 'preparing', $data);
                return;
            }
            $authority->verifiedReceipt($samStep);
        }
        $compositionStep = $steps->reserve($item, 'grass-composition', 'composition', ['runId' => $run->id, 'outputSha256' => $outputSha,
            'assessmentId' => $assessment->id, 'assessmentSha256' => $assessment->result_sha256, 'recipe' => $workflow->policy['grassRecipe']['artifacts']]);
        if (! $compositionStep->child_id) {
            $state->save($item, 'preparing', $data);
            $composite = $artifacts->compose($item, $compositionStep, $run, $analysis);
            $steps->attach($compositionStep, $composite->id); $data['compositeId'] = $composite->id;
            $state->save($item, 'preparing', $data); return;
        }
        $composite = IguideLabComposite::where('project_id', $project->id)->findOrFail($compositionStep->child_id);
        $artifacts->verifyComposite($item, $composite, $compositionStep, $run); $data['compositeId'] = $composite->id;
        $evaluationStep = IguideLabWorkflowStep::where('item_id', $item->id)->where('step_key', 'grass-evaluation')->first();
        $evidence = $evaluationStep ? $evaluationStep->input : $artifacts->evidence($item, $composite, $analysis);
        if (! $evidence['context']['mechanicalQa']['passed']) {
            $state->finish($item, 'needs_review', 'grass_numeric_checks_failed', 'The composed sphere failed its source-preservation checks.', $data); return;
        }
        $evaluationStep ??= $steps->reserve($item, 'grass-evaluation', 'evaluation', $evidence);
        $evaluation = IguideLabWorkflowEvaluation::where('project_id', $project->id)->where('request_id', $evaluationStep->request_id)->first();
        if (! $evaluation) $evaluation = app(LabWorkflowEvaluations::class)->start($evaluationStep);
        else $state->reconcileQueuedChild($evaluationStep, $evaluation);
        $steps->attach($evaluationStep, $evaluation->id); $data['evaluationId'] = $evaluation->id;
        if ($evaluation->status !== 'completed') {
            if (in_array($evaluation->status, ['failed', 'needs_attention'], true)) $state->finish($item, 'needs_review', 'grass_evaluation_unconfirmed', 'Independent full-sphere quality could not be confirmed.', $data);
            else $state->save($item, 'checking', $data);
            return;
        }
        app(LabWorkflowEvaluations::class)->payload($evidence, $project->id, $item->id);
        if (($evaluation->result['qualityStatus'] ?? null) === 'passed_automated_checks') {
            $state->finish($item, 'ready', null, null, $data); return;
        }
        $state->finish($item, 'needs_review', 'grass_quality_requires_review', 'The independent sphere check did not pass. No automatic reroll was requested.', $data);
    }
    private function sourceAdmission(IguideLabWorkflowItem $item, array &$data): bool
    {
        $service = app(LabGrassSourceAdmissions::class); $state = app(LabWorkflows::class); $priorId = null;
        for ($round = 0; $round < 2; $round++) {
            $step = $service->reserve($item, $priorId); $record = $service->start($step);
            $data['grassSourceAdmissionIds'][$round] = $record->id;
            if ($record->status !== 'completed') {
                if ($record->status === 'needs_attention') $state->finish($item, 'needs_review', 'grass_source_admission_unconfirmed', 'Original lawn admissibility could not be confirmed; no image was requested.', $data);
                else $state->save($item, 'analyzing', $data);
                return false;
            }
            $result = $service->verifiedReport($record, $item);
            if ($result['status'] === 'eligible_for_image') {
                $data['grassSourceAdmissionId'] = $record->id; $data['grassSourceAdmissionSha256'] = $record->result_sha256;
                return true;
            }
            if ($result['status'] !== 'needs_detail' || $round === 1) {
                $data['grassSourceAdmissionReasons'] = $result['reviewReasons'];
                $state->finish($item, 'needs_review', 'grass_original_material_or_context', 'Original turf, protected debris or optical boundaries require review; no image was requested.', $data);
                return false;
            }
            $priorId = $record->id;
        }
        return false;
    }
}
