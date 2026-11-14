<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabScenePlanProtocol;
use Tests\TestCase;

class IguideLabAnalysisRecipesTest extends TestCase
{
    public function test_frozen_baseline_compiler_matches_the_original_wire_request(): void
    {
        $recipes = app(LabAnalysisRecipes::class); $snapshot = $recipes->snapshot(LabAnalysisRecipes::BASELINE);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(128, 64)->fill('888888')->toPng();
        $context = ['sourceSha256' => hash('sha256', $png), 'projection' => 'equirectangular', 'preset' => 'declutter', 'style' => null, 'viewIds' => ['source'], 'instructions' => 'Remove the vase only.'];
        $images = ['data:image/png;base64,'.base64_encode($png)];
        $old = LabScenePlanProtocol::request($context, $images); $old['instructions'] = str_replace("\r\n", "\n", $old['instructions']);
        $this->assertSame($old, $recipes->request($snapshot, $context, $images));
        $this->assertSame('f25a88708519f3f704ca957787d8b815358780be8c669f7bcb4a574fc822d514', $snapshot['instructionsSha256']);
        $this->assertSame(LabScenePlanProtocol::schema(), $snapshot['recipe']['schema']);
    }

    public function test_candidate_changes_only_instruction_bytes_and_their_version(): void
    {
        $recipes = app(LabAnalysisRecipes::class);
        $baseline = $recipes->snapshot(LabAnalysisRecipes::BASELINE);
        $candidate = $recipes->active();
        $this->assertSame(LabAnalysisRecipes::CANDIDATE, $candidate['recipe']['instructionsVersion']);
        $this->assertSame('42461b443760cdfe41eeb166c03d227ea5f9cf537ffef63e16854ad02356b626', $candidate['instructionsSha256']);
        $this->assertStringStartsWith($baseline['recipe']['instructions'], $candidate['recipe']['instructions']);
        $this->assertSame($baseline['schemaSha256'], $candidate['schemaSha256']);
        $onlyInstructionsChanged = $candidate['recipe'];
        $onlyInstructionsChanged['instructions'] = $baseline['recipe']['instructions'];
        $onlyInstructionsChanged['instructionsVersion'] = $baseline['recipe']['instructionsVersion'];
        $this->assertSame($baseline['recipe'], $onlyInstructionsChanged);
    }
}
