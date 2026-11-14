<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabVisualQualityProtocol as Protocol;
use InvalidArgumentException;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class IguideLabVisualQualityProtocolTest extends TestCase
{
    private function fixture(): array
    {
        $context = ['sourceSha256' => str_repeat('a', 64), 'candidateSha256' => str_repeat('b', 64),
            'intentSha256' => str_repeat('c', 64), 'evidenceManifestSha256' => str_repeat('d', 64),
            'projection' => 'equirectangular', 'preset' => 'golden-hour', 'style' => null,
            'intent' => 'Warm the daylight while preserving this room and floor texture.',
            'protectedFeatures' => ['Window frames and original floor boards.'], 'opticalDependencies' => ['Retain the transmitted exterior and glazing reflections.'],
            'sourceCaveats' => ['Artistic lighting; geographic solar alignment is unmeasured.'],
            'reviewRequirements' => ['Retain the source floor texture under the new illumination.'],
            'mechanicalQa' => ['passed' => true, 'blockingReasons' => []], 'views' => []];
        $images = [];
        foreach (Protocol::VIEW_IDS as $i => $id) {
            $bytes = (string) ImageManager::gd()->create(128, 64)->fill(sprintf('%02x8888', 40 + 20 * $i))->toPng();
            $context['views'][] = ['id' => $id, 'sha256' => hash('sha256', $bytes), 'width' => 128, 'height' => 64];
            $images[] = 'data:image/png;base64,'.base64_encode($bytes);
        }
        $checks = [];
        foreach (Protocol::CHECKS as $id) $checks[$id] = ['status' => 'pass', 'evidenceViewIds' => [match ($id) { 'seam' => 'wrap', 'poles' => 'poles', default => 'front' }], 'reason' => 'Observed source-relative detail remains consistent in the cited evidence.'];
        $evaluation = ['protocolVersion' => Protocol::VERSION, 'sourceSha256' => $context['sourceSha256'], 'candidateSha256' => $context['candidateSha256'],
            'intentSha256' => $context['intentSha256'], 'evidenceManifestSha256' => $context['evidenceManifestSha256'],
            'checks' => $checks, 'unresolvedDependencies' => [], 'recommendedAction' => 'none', 'summary' => 'The supplied views support this restrained lighting edit.'];
        return [$context, $images, $evaluation];
    }

    private function response(array $evaluation): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'reasoning', 'private' => 'not exposed'],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($evaluation)]]]]];
    }

    public function test_request_binds_exact_ordered_images_and_complete_schema_without_provider_side_effects(): void
    {
        [$context, $images] = $this->fixture(); $payload = Protocol::request($context, $images);
        $this->assertFalse($payload['store']); $this->assertSame('gpt-6-astra', $payload['model']);
        $this->assertSame($context, json_decode($payload['input'][0]['content'][0]['text'], true));
        foreach ($images as $i => $image) {
            $this->assertSame($image, $payload['input'][0]['content'][2 + 2 * $i]['image_url']);
            $this->assertStringContainsString(Protocol::VIEW_IDS[$i], $payload['input'][0]['content'][1 + 2 * $i]['text']);
        }
        $this->assertSame(Protocol::CHECKS, $payload['text']['format']['schema']['properties']['checks']['required']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Protocol::recipeSha256());
    }

    public function test_pass_is_automated_status_only_and_never_human_or_cross_view_acceptance(): void
    {
        [$context, , $evaluation] = $this->fixture(); $result = Protocol::parse($this->response($evaluation), $context);
        $this->assertSame('passed_automated_checks', $result['qualityStatus']);
        $this->assertFalse($result['manualAcceptance']); $this->assertFalse($result['crossViewVerified']);
        $this->assertArrayNotHasKey('verdict', $result); $this->assertStringNotContainsString('not exposed', json_encode($result));
    }

    public function test_bounded_original_intent_and_source_caveats_survive_without_truncation(): void
    {
        [$context, $images] = $this->fixture();
        $context['intent'] = json_encode(['sourceSha256' => $context['sourceSha256'], 'preset' => 'golden-hour',
            'style' => null, 'instructions' => str_repeat("\x01", 2000)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context['sourceCaveats'] = array_fill(0, 13, 'An unresolved source interpretation must remain available to the independent evaluator.');
        $payload = Protocol::request($context, $images);
        $this->assertSame($context, json_decode($payload['input'][0]['content'][0]['text'], true));
        $context['sourceCaveats'][] = 'Beyond the bounded context.';
        $this->expectException(InvalidArgumentException::class);
        Protocol::request($context, $images);
    }

    public function test_visual_pass_cannot_override_a_mechanical_failure(): void
    {
        [$context, , $evaluation] = $this->fixture(); $context['mechanicalQa'] = ['passed' => false, 'blockingReasons' => ['Changed pixels outside effective support.']];
        $result = Protocol::parse($this->response($evaluation), $context);
        $this->assertSame('needs_review', $result['qualityStatus']); $this->assertSame('manual_review', $result['recommendedAction']);
        $context['mechanicalQa']['passed'] = true;
        $this->assertSame('needs_review', Protocol::parse($this->response($evaluation), $context)['qualityStatus']);
    }

    public function test_uncertainty_unresolved_reflection_or_repair_request_prevents_pass(): void
    {
        [$context, , $evaluation] = $this->fixture();
        $mutations = [
            function ($e) { $e['checks']['optical_dependencies']['status'] = 'uncertain'; return $e; },
            function ($e) { $e['unresolvedDependencies'] = ['Person reflection is outside supplied detail.']; return $e; },
            function ($e) { $e['recommendedAction'] = 'refine_support'; return $e; },
        ];
        foreach ($mutations as $mutate) $this->assertSame('needs_review', Protocol::parse($this->response($mutate($evaluation)), $context)['qualityStatus']);
    }

    public function test_wrong_bindings_unknown_evidence_missing_checks_and_forged_acceptance_are_rejected(): void
    {
        [$context, , $evaluation] = $this->fixture();
        $mutations = [
            function ($e) { $e['candidateSha256'] = str_repeat('e', 64); return $e; },
            function ($e) { $e['intentSha256'] = str_repeat('e', 64); return $e; },
            function ($e) { $e['evidenceManifestSha256'] = str_repeat('e', 64); return $e; },
            function ($e) { $e['checks']['geometry']['evidenceViewIds'] = ['invented_camera']; return $e; },
            function ($e) { $e['checks']['seam']['evidenceViewIds'] = ['overview']; return $e; },
            function ($e) { unset($e['checks']['poles']); return $e; },
            function ($e) { $e['accepted'] = true; return $e; },
        ];
        foreach ($mutations as $mutate) {
            try { Protocol::parse($this->response($mutate($evaluation)), $context); $this->fail('Invalid evaluation was accepted.'); }
            catch (InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }

    public function test_same_dimensions_do_not_allow_swapped_or_unbound_evidence_bytes(): void
    {
        [$context, $images] = $this->fixture(); [$images[1], $images[2]] = [$images[2], $images[1]];
        $this->expectException(InvalidArgumentException::class); $this->expectExceptionMessage('ordered evidence');
        Protocol::request($context, $images);
    }

    public function test_missing_full_sphere_views_and_remote_image_inputs_are_rejected(): void
    {
        [$context, $images] = $this->fixture();
        try { $missing = $context; array_pop($missing['views']); Protocol::request($missing, array_slice($images, 0, 6)); $this->fail('Missing wrap evidence accepted.'); }
        catch (InvalidArgumentException $error) { $this->assertStringContainsString('full-sphere', $error->getMessage()); }
        $images[0] = 'https://example.com/evidence.png'; $this->expectException(InvalidArgumentException::class);
        Protocol::request($context, $images);
    }

    public function test_image_byte_limit_is_enforced_before_provider_submission(): void
    {
        [$context, $images] = $this->fixture(); $bytes = base64_decode(explode(',', $images[0], 2)[1]).str_repeat('x', 24 * 1024 * 1024);
        $images[0] = 'data:image/png;base64,'.base64_encode($bytes); $context['views'][0]['sha256'] = hash('sha256', $bytes);
        $this->expectException(InvalidArgumentException::class); $this->expectExceptionMessage('oversized'); Protocol::request($context, $images);
    }

    public function test_incomplete_or_refused_response_never_becomes_a_quality_result(): void
    {
        [$context, , $evaluation] = $this->fixture();
        $response = $this->response($evaluation); $response['status'] = 'incomplete';
        try { Protocol::parse($response, $context); $this->fail('Incomplete result accepted.'); }
        catch (InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        $response = $this->response($evaluation); $response['output'][1]['content'] = [['type' => 'refusal', 'refusal' => 'Cannot evaluate.']];
        $this->expectException(InvalidArgumentException::class); Protocol::parse($response, $context);
    }
}
