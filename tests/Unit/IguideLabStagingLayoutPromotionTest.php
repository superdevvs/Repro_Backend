<?php

namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes, LabStagingLayouts};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabStagingLayoutPromotionTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = tempnam(sys_get_temp_dir(), 'layout-promotion-');
        // This fixture tests the manifest/selection contract, not Python runtime readiness.
        config(['iguide-lab.pipeline_python_path' => PHP_BINARY,
            'iguide-lab.staging_layout_runtime_manifest' => $this->manifestPath]);
        file_put_contents($this->manifestPath, json_encode([
            'version' => LabStagingLayouts::VERSION, 'validationStatus' => 'passed',
            'files' => app(LabStagingLayouts::class)->implementation(),
            'pythonPath' => PHP_BINARY, 'pythonSha256' => hash_file('sha256', PHP_BINARY),
            'pythonVersion' => '3.12.3', 'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0',
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) unlink($this->manifestPath);
        parent::tearDown();
    }

    public function test_new_workflow_snapshot_selects_v3_and_preserves_bounded_authority(): void
    {
        $snapshot = app(LabStagingLayouts::class)->snapshot();
        $this->assertSame(LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE, $snapshot['cutRecipe']['recipe']['instructionsVersion']);
        $this->assertSame(['effort' => 'medium'], $snapshot['cutRecipe']['recipe']['reasoning']);
        $this->assertSame(12000, $snapshot['cutRecipe']['recipe']['max_output_tokens']);
        $this->assertSame([1, 2, 2, 1, 4], array_values(array_intersect_key($snapshot, array_flip([
            'maxAdaptiveSourceOperations', 'maxNativeDetails', 'maxSourceSections', 'maxImages', 'maxAnalyses',
        ]))));
        $this->assertArrayHasKey('resources/iguide-lab/analysis-recipes/staging-layout-3.json', $snapshot['files']);
        $this->assertArrayHasKey('resources/iguide-lab/analysis-recipes/staging-layout-1.json', $snapshot['files']);
        app(LabStagingLayouts::class)->validate($snapshot);
    }

    public function test_old_manifest_without_v3_source_binding_is_rejected_before_reservation(): void
    {
        $manifest = json_decode(file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['files']['resources/iguide-lab/analysis-recipes/staging-layout-3.json']);
        file_put_contents($this->manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Staging layout implementation is not validated.');
        app(LabStagingLayouts::class)->snapshot();
    }

    public function test_old_recipe_snapshots_remain_explicitly_replayable_without_becoming_default(): void
    {
        $recipes = app(LabAnalysisRecipes::class);
        foreach ([LabAnalysisRecipes::STAGING_LAYOUT, LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM] as $version) {
            $old = $recipes->snapshot($version);
            $this->assertSame($version, $recipes->validate($old)['instructionsVersion']);
            $this->assertNotSame($old, app(LabStagingLayouts::class)->snapshot()['cutRecipe']);
        }
    }
}
