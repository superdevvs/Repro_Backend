<?php
namespace App\Services\IguideLab;

class LabWorkflowPolicy
{
    public const VERSION = 'automatic-tour-edit-workflow/1';
    public function snapshot(): array
    {
        return ['version' => self::VERSION, 'modelId' => 'gpt-image-2.6-sunburst', 'quality' => 'high', 'complexity' => 'low',
            'credentialPolicy' => 'lab-primary-explicit-quota-fallback', 'maxImageAttemptsPerItem' => 3, 'maxPlanRevisionsPerItem' => 2,
            'maxSegmentationsPerImageAttempt' => 2, 'maxItems' => 20, 'maxActiveItems' => 1, 'recipeVersion' => 'whole-panorama-lighting/1',
            'manualAcceptance' => false, 'operatorInterventions' => 0,
            'analysisRecipe' => app(LabAnalysisRecipes::class)->active(),
            'evaluationRecipeSha256' => LabVisualQualityProtocol::recipeSha256(),
            'evaluationParserSha256' => hash_file('sha256', (new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),
            'artifacts' => app(LabWorkflowArtifacts::class)->snapshot()];
    }
    public function capabilities(): array
    {
        $evaluationReady = app(LabWorkflowEvaluations::class)->ready();
        $model = collect(LabCatalog::models())->firstWhere('id', $this->snapshot()['modelId']);
        $ready = $evaluationReady && app(LabAnalyses::class)->ready() && ($model['ready'] ?? false) && app(LabWorkflowArtifacts::class)->ready();
        return ['supported' => true, 'ready' => $ready, 'version' => self::VERSION, 'maxItems' => 20,
            'manualAcceptance' => false, 'requiresAutomaticChecks' => true,
            'recipes' => array_map(fn ($preset) => ['preset' => $preset, 'ready' => in_array($preset, ['twilight', 'golden-hour'], true) && $ready,
                'reason' => in_array($preset, ['twilight', 'golden-hour'], true)
                    ? ($ready ? null : 'Required automatic checking is not available yet.')
                    : 'An automatic support and composition adapter is not available yet.'], array_keys(LabCatalog::PRESETS))];
    }
}
