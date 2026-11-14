<?php
namespace App\Services\IguideLab;

class LabWorkflowPolicy
{
    public const VERSION = 'automatic-tour-edit-workflow/1';
    public function snapshot(): array
    {
        $policy = ['version' => self::VERSION, 'modelId' => 'gpt-image-2.6-sunburst', 'quality' => 'high', 'complexity' => 'low',
            'credentialPolicy' => 'lab-primary-explicit-quota-fallback', 'maxImageAttemptsPerItem' => 3, 'maxPlanRevisionsPerItem' => 2,
            'maxSegmentationsPerImageAttempt' => 2, 'maxItems' => 20, 'maxActiveItems' => 1, 'recipeVersion' => 'whole-panorama-lighting/1',
            'manualAcceptance' => false, 'operatorInterventions' => 0,
            'analysisRecipe' => app(LabAnalysisRecipes::class)->active(),
            'evaluationRecipeSha256' => LabVisualQualityProtocol::recipeSha256(),
            'evaluationParserSha256' => hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),
            'artifacts' => app(LabWorkflowArtifacts::class)->snapshot()];
        if (config('iguide-lab.lighting_repair_enabled', false) === true && app(LabWorkflowArtifacts::class)->ready()) {
            $policy['lightingRepair'] = app(LabLightingRepairs::class)->snapshot();
        }
        if (config('iguide-lab.staging_workflow_enabled',false) === true && class_exists(LabStagingWorkflow::class) && app(LabStagingWorkflow::class)->ready()) {
            $policy['stagingAssessment'] = LabStagingAssessmentPolicy::snapshot();
            $policy['stagingWorkflow'] = app(LabStagingWorkflow::class)->snapshot();
            $policy['sectionAnalysisRecipe'] = app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::SECTION_CONTEXT);
            if (config('iguide-lab.staging_framing_enabled', false) === true) $policy['stagingFraming'] = app(LabStagingFramings::class)->snapshot();
        }
        if (config('iguide-lab.lifestyle_workflow_enabled',false) === true && class_exists(LabLifestyleWorkflow::class) && app(LabLifestyleWorkflow::class)->ready()) {
            $policy['lifestyleAssessment'] = LabLifestyleAssessmentPolicy::snapshot();
            $policy['lifestyleWorkflow'] = app(LabLifestyleWorkflow::class)->snapshot();
            $policy['lifestyleFraming'] = app(LabLifestyleFramings::class)->snapshot();
            $policy['sectionAnalysisRecipe'] = app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::SECTION_CONTEXT);
        }
        if (config('iguide-lab.grass_workflow_enabled',false) === true && class_exists(LabGrassWorkflow::class) && app(LabGrassWorkflow::class)->ready()) {
            $policy['grassAssessment'] = LabGrassAssessmentPolicy::snapshot();
            $policy['grassRecipe'] = app(LabGrassWorkflow::class)->snapshot();
            if (config('iguide-lab.grass_source_admission_enabled', false) === true) $policy['grassSourceAdmission'] = LabGrassSourceAdmissionPolicy::snapshot();
        }
        if (config('iguide-lab.declutter_workflow_enabled',false) === true && class_exists(LabDeclutterWorkflow::class) && app(LabDeclutterWorkflow::class)->ready()) {
            $policy['declutterRemoval'] = LabDeclutterRemovalPolicy::snapshot();
            $policy['declutterWorkflow'] = app(LabDeclutterWorkflow::class)->snapshot();
        }
        return $policy;
    }
    public function capabilities(): array
    {
        $evaluationReady = app(LabWorkflowEvaluations::class)->ready();
        $model = collect(LabCatalog::models())->firstWhere('id', $this->snapshot()['modelId']);
        $ready = $evaluationReady && app(LabAnalyses::class)->ready() && ($model['ready'] ?? false) && app(LabWorkflowArtifacts::class)->ready();
        $stagingReady=$ready&&config('iguide-lab.staging_workflow_enabled',false)===true&&class_exists(LabStagingWorkflow::class)&&app(LabStagingWorkflow::class)->ready();
        $grassReady=$ready&&config('iguide-lab.grass_workflow_enabled',false)===true&&class_exists(LabGrassWorkflow::class)&&app(LabGrassWorkflow::class)->ready();
        $declutterReady=$ready&&config('iguide-lab.declutter_workflow_enabled',false)===true&&class_exists(LabDeclutterWorkflow::class)&&app(LabDeclutterWorkflow::class)->ready();
        $lifestyleReady=$ready&&config('iguide-lab.lifestyle_workflow_enabled',false)===true&&class_exists(LabLifestyleWorkflow::class)&&app(LabLifestyleWorkflow::class)->ready();
        $recipeReady=fn($preset)=>match($preset){'staging'=>$stagingReady,'grass'=>$grassReady,'declutter'=>$declutterReady,'lifestyle'=>$lifestyleReady,'twilight','golden-hour'=>$ready,default=>false};
        return ['supported' => true, 'ready' => $ready, 'version' => self::VERSION, 'maxItems' => 20,
            'manualAcceptance' => false, 'requiresAutomaticChecks' => true,
            'recipes' => array_map(fn ($preset) => ['preset' => $preset, 'ready' => $recipeReady($preset),
                'reason' => in_array($preset, ['twilight', 'golden-hour','staging','grass','declutter','lifestyle'], true)
                    ? ($recipeReady($preset) ? null : 'Required automatic checking or explicit recipe enablement is not available yet.')
                    : 'An automatic support and composition adapter is not available yet.'], array_keys(LabCatalog::PRESETS))];
    }
}
