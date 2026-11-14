<?php

namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabStagingLayoutProtocol};
use InvalidArgumentException;
use Tests\TestCase;

class IguideLabStagingLayoutBudgetTest extends TestCase
{
    private function fixture(): array
    {
        $test = new IguideLabStagingLayoutProtocolTest('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');
        return (new \ReflectionMethod($test, 'fixture'))->invoke($test);
    }

    public function test_medium_recipe_preserves_the_exact_old_recipe_and_changes_only_effort_and_identity(): void
    {
        $recipes = app(LabAnalysisRecipes::class);
        $old = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT);
        $new = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM);
        $this->assertSame('c3166d4b86c79f1c1b6ebdcd8f0f0dda1b7d160680013cbaa9c8ec0b70a93601', hash_file('sha256', resource_path('iguide-lab/analysis-recipes/staging-layout-1.json')));
        $this->assertSame(['effort' => 'high'], $old['recipe']['reasoning']);
        $this->assertSame(['effort' => 'medium'], $new['recipe']['reasoning']);
        $this->assertSame(12000, $new['recipe']['max_output_tokens']);
        $this->assertSame($old['schemaSha256'], $new['schemaSha256']);
        $this->assertSame($old['instructionsSha256'], $new['instructionsSha256']);
        $this->assertNotSame($old['recipeSha256'], $new['recipeSha256']);
        $restored = $new['recipe'];
        $restored['instructionsVersion'] = $old['recipe']['instructionsVersion'];
        $restored['reasoning'] = $old['recipe']['reasoning'];
        $this->assertSame($old['recipe'], $restored);
    }

    public function test_compiled_wire_changes_only_effort_with_same_images_schema_and_source_bindings(): void
    {
        [, $context, $images] = $this->fixture();
        $recipes = app(LabAnalysisRecipes::class);
        $old = $recipes->request($recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT), $context, $images);
        $new = $recipes->request($recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM), $context, $images);
        $this->assertSame(['effort' => 'medium'], $new['reasoning']);
        $this->assertTrue($new['text']['format']['strict']);
        $new['reasoning'] = $old['reasoning'];
        $this->assertSame($old, $new);
    }

    public function test_medium_uses_unchanged_strict_parser_and_does_not_convert_optical_hold_to_authority(): void
    {
        [, $context, , $proposal] = $this->fixture();
        $recipes = app(LabAnalysisRecipes::class);
        $old = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT);
        $new = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM);
        $response = IguideLabStagingFramingProtocolTest::response($proposal);
        $this->assertSame($recipes->parse($old, $response, $context), $recipes->parse($new, $response, $context));
        $proposal['stagingLayout']['optics'] = 'uncertain';
        $held = $recipes->parse($new, IguideLabStagingFramingProtocolTest::response($proposal), $context);
        $this->assertFalse($held['stagingLayout']['eligibleForDraft']);
        $this->assertFalse($held['stagingLayout']['realizedPlacementVerified']);
        $this->assertFalse($held['stagingLayout']['supportMaskAuthority']);
        $this->assertFalse($held['automaticGeneration']);
    }

    public function test_exhausted_reasoning_only_response_still_fails_closed_without_retry_or_proposal(): void
    {
        [, $context] = $this->fixture();
        $response = ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'reasoning', 'summary' => []]], 'usage' => ['output_tokens' => 12000, 'output_tokens_details' => ['reasoning_tokens' => 12000]]];
        $this->expectException(InvalidArgumentException::class);
        $recipes = app(LabAnalysisRecipes::class);
        $recipes->parse($recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM), $response, $context);
    }

    public function test_reserved_high_snapshot_remains_valid_and_unregistered_low_override_is_rejected(): void
    {
        $recipes = app(LabAnalysisRecipes::class);
        $old = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT);
        $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM);
        $this->assertSame($old['recipe'], $recipes->validate($old));
        $this->assertSame(LabAnalysisRecipes::CANDIDATE, $recipes->active()['recipe']['instructionsVersion']);
        $bad = $recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM);
        $bad['recipe']['reasoning']['effort'] = 'low';
        $this->expectException(InvalidArgumentException::class);
        $recipes->validate($bad);
    }
}
