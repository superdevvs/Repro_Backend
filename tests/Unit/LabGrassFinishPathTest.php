<?php
namespace Tests\Unit;

use App\Services\IguideLab\LabGrassAssessmentBridge;
use App\Services\IguideLab\LabGrassWorkflowArtifacts;
use Tests\TestCase;

class LabGrassFinishPathTest extends TestCase
{
    public function test_bridge_keeps_the_protocol_error_code_from_a_failed_run(): void
    {
        $stderr = "Traceback (most recent call last):\n  File \"grass_assessment_bridge.py\", line 225\nValueError: GRASS_SELECTION_ID_INVALID\n";
        $this->assertSame('GRASS_SELECTION_ID_INVALID', LabGrassAssessmentBridge::failureCode($stderr));
        $this->assertSame('ENUM_INVALID:$.material.status', LabGrassAssessmentBridge::failureCode("noise\nValueError: ENUM_INVALID:$.material.status\n"));
        $this->assertSame('unknown', LabGrassAssessmentBridge::failureCode(''));
        $this->assertSame('unknown', LabGrassAssessmentBridge::failureCode("Killed\n"));
        $this->assertLessThanOrEqual(200, strlen(LabGrassAssessmentBridge::failureCode('ValueError: '.str_repeat('X', 500))));
    }

    public function test_assessment_caveats_reach_the_final_check_as_one_plain_caveat(): void
    {
        $service = app(LabGrassWorkflowArtifacts::class);
        $this->assertNull($service->assessmentCaveat(['caveats' => []]));
        $this->assertNull($service->assessmentCaveat([]));
        $caveat = $service->assessmentCaveat(['caveats' => ['TEXTURE_OR_MATERIAL_REQUIRES_REVIEW', 'EXCLUSION_UNRESOLVED:plants', 'ROLE_MASK_ASSOCIATION_AMBIGUOUS_PROTECTED_IN_ORDER']]);
        $this->assertIsString($caveat); $this->assertLessThanOrEqual(600, mb_strlen($caveat));
        $this->assertStringContainsString('TEXTURE_OR_MATERIAL_REQUIRES_REVIEW', $caveat);
        $this->assertStringContainsString('EXCLUSION_UNRESOLVED:plants', $caveat);
        $this->assertStringContainsString('honest recolor', $caveat);
        $long = $service->assessmentCaveat(['caveats' => array_map(fn ($i) => 'SELECTION_TOO_FINE_FOR_GUIDANCE:role_'.$i.str_repeat('x', 40), range(1, 30))]);
        $this->assertLessThanOrEqual(600, mb_strlen($long));
    }

    public function test_pre_image_admission_caveats_reach_the_final_check_as_one_plain_caveat(): void
    {
        $service = app(LabGrassWorkflowArtifacts::class);
        $this->assertNull($service->admissionCaveat(['caveats' => []]));
        $this->assertNull($service->admissionCaveat(['status' => 'eligible_for_image']));
        $caveat = $service->admissionCaveat(['caveats' => ['SOURCE_TURF_MATERIAL_UNRESOLVED', 'SOURCE_EXCLUSION_UNRESOLVED:plants']]);
        $this->assertStringContainsString('SOURCE_TURF_MATERIAL_UNRESOLVED, SOURCE_EXCLUSION_UNRESOLVED:plants', $caveat);
        $this->assertStringContainsString('not blockers', $caveat);
        $long = $service->admissionCaveat(['caveats' => array_map(fn ($i) => 'SOURCE_EXCLUSION_UNRESOLVED:role_'.$i.str_repeat('x', 40), range(1, 30))]);
        $this->assertLessThanOrEqual(600, mb_strlen($long));
    }
}
