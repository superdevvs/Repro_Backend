<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabWorkflowArtifacts;
use PHPUnit\Framework\TestCase;

class IguideLabWorkflowArtifactsTest extends TestCase
{
    public function test_all_bounded_visual_triage_flags_reach_evaluator_without_becoming_verdicts(): void
    {
        $instructions = array_map(fn ($i) => 'Original scene requirement '.$i, range(1, 8));
        $proposal = ['reviewChecks' => array_map(fn ($instruction) => ['instruction' => $instruction], $instructions)];
        $qa = ['reviewReasons' => array_map(fn ($code) => ['code' => $code, 'kind' => 'visual_flag', 'blocking' => false],
            ['WRAP_CHANGE_REVIEW', 'SUPPORT_RIM_REVIEW', 'EDGE_CHANGE_REVIEW', 'WRAP_CHANGE_REVIEW', 'UNKNOWN_FLAG'])];
        $result = (new LabWorkflowArtifacts)->evaluationReviewRequirements($proposal, $qa);
        $this->assertCount(11, $result);
        $this->assertSame($instructions, array_slice($result, 0, 8));
        foreach (array_slice($result, 8) as $instruction) {
            $this->assertStringContainsString('diagnostic flag, not a defect verdict', $instruction);
            $this->assertLessThanOrEqual(600, mb_strlen($instruction));
        }
        $this->assertStringStartsWith('WRAP_CHANGE_REVIEW:', $result[8]);
        $this->assertStringStartsWith('SUPPORT_RIM_REVIEW:', $result[9]);
        $this->assertStringStartsWith('EDGE_CHANGE_REVIEW:', $result[10]);
        $this->assertSame($instructions, (new LabWorkflowArtifacts)->evaluationReviewRequirements($proposal, ['reviewReasons' => []]));
    }
}
