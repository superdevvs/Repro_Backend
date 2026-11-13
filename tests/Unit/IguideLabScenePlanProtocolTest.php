<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabScenePlanProtocol;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IguideLabScenePlanProtocolTest extends TestCase
{
    private function context(): array
    {
        return ['sourceSha256' => str_repeat('a', 64), 'projection' => 'equirectangular', 'preset' => 'declutter',
            'style' => null, 'viewIds' => ['source', 'yaw_0']];
    }

    private function proposal(): array
    {
        return ['schemaVersion' => LabScenePlanProtocol::VERSION, ...array_diff_key($this->context(), ['viewIds' => true]),
            'summary' => 'Remove the vase on the kitchen counter; preserve the window and appliance.',
            'protectedFeatures' => [['id' => 'window', 'description' => 'Window frame above the blue counter', 'evidenceViewIds' => ['source', 'yaw_0'], 'confidence' => 'high']],
            'targets' => [['id' => 'vase', 'description' => 'The vase and flowers on the counter', 'action' => 'remove', 'objectCount' => 1, 'evidenceViewIds' => ['yaw_0']]],
            'sourceArtifacts' => ['Original camera silhouette at the nadir'], 'uncertainties' => ['The exact removal mask needs review'],
            'workflow' => ['mode' => 'perspective_sections', 'reason' => 'Only a small counter area changes.',
                'sections' => [['id' => 'counter', 'yawDegrees' => 0, 'pitchDegrees' => -12.5, 'hfovDegrees' => 80, 'targetIds' => ['vase']]]],
            'generationPrompt' => 'Remove only the vase and bouquet. Keep the counter, window, appliance and all other objects unchanged.',
            'reviewChecks' => array_map(fn ($check) => ['check' => $check, 'instruction' => 'Inspect the source and result.'], ['edit', 'geometry', 'seam', 'appearance'])];
    }

    private function response(?array $proposal = null): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => json_encode($proposal ?? $this->proposal(), JSON_THROW_ON_ERROR)]]]]];
    }

    public function test_valid_plan_is_only_an_unreviewed_proposal(): void
    {
        $result = LabScenePlanProtocol::parse($this->response(), $this->context());
        self::assertSame('unreviewed', $result['reviewStatus']);
        foreach (['geometryVerified', 'maskVerified', 'crossViewVerified', 'automaticGeneration'] as $flag) self::assertFalse($result[$flag]);
        self::assertSame(-12.5, $result['proposal']['workflow']['sections'][0]['pitchDegrees']);
    }

    public function test_wrong_source_hash_cannot_be_applied_to_another_case(): void
    {
        $proposal = $this->proposal(); $proposal['sourceSha256'] = str_repeat('b', 64);
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_rejects_accepted_claim_in_provider_json(): void
    {
        $proposal = $this->proposal(); $proposal['accepted'] = true;
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_rejects_incomplete_output_even_if_partial_json_is_valid(): void
    {
        $response = $this->response(); $response['status'] = 'incomplete';
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($response, $this->context());
    }

    public function test_refusal_is_not_treated_as_a_generation_prompt(): void
    {
        $response = $this->response(); $response['output'][1]['content'] = [['type' => 'refusal', 'refusal' => 'Unavailable']];
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($response, $this->context());
    }

    public function test_unknown_view_or_target_ids_do_not_become_geometry_evidence(): void
    {
        $proposal = $this->proposal(); $proposal['targets'][0]['evidenceViewIds'] = ['a_different_scan'];
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_section_coordinates_have_physical_bounds(): void
    {
        $proposal = $this->proposal(); $proposal['workflow']['sections'][0]['hfovDegrees'] = 200;
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_whole_panorama_mode_cannot_smuggle_section_requests(): void
    {
        $proposal = $this->proposal(); $proposal['workflow']['mode'] = 'whole_panorama';
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_wider_than_supported_group_framing_is_rejected_instead_of_clamped(): void
    {
        $proposal = $this->proposal(); $proposal['workflow']['sections'][0]['hfovDegrees'] = 130;
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_prompt_length_is_checked_locally(): void
    {
        $proposal = $this->proposal(); $proposal['generationPrompt'] = str_repeat('x', 4001);
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_missing_geometry_quality_check_is_rejected(): void
    {
        $proposal = $this->proposal(); $proposal['reviewChecks'][1]['check'] = 'appearance';
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::parse($this->response($proposal), $this->context());
    }

    public function test_request_has_no_tools_or_remote_fetches_and_disables_storage(): void
    {
        $image = imagecreatetruecolor(128, 64); ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $url = 'data:image/png;base64,'.base64_encode($bytes);
        $request = LabScenePlanProtocol::request($this->context(), [$url, $url]);
        self::assertFalse($request['store']);
        self::assertArrayNotHasKey('tools', $request);
        self::assertTrue($request['text']['format']['strict']);
        self::assertCount(5, $request['input'][0]['content']);
        self::assertArrayNotHasKey('maxLength', $request['text']['format']['schema']['properties']['generationPrompt']);
    }

    public function test_rejects_remote_image_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabScenePlanProtocol::request($this->context(), ['https://example.com/source.png', 'https://example.com/view.png']);
    }
}
