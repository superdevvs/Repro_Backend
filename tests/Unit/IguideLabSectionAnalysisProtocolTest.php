<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabAnalysisSourceContext;
use App\Services\IguideLab\LabSectionAnalysisProtocol;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class IguideLabSectionAnalysisProtocolTest extends TestCase
{
    private function fixture(): array
    {
        $binding = ['version' => LabAnalysisSourceContext::VERSION, 'sectionId' => (string) Str::uuid(), 'nativeCaseId' => (string) Str::uuid(),
            'visibleParentCaseId' => (string) Str::uuid(), 'sourceSha256' => str_repeat('a', 64), 'nativeSourceSha256' => str_repeat('b', 64),
            'nativeDimensions' => ['width' => 11008, 'height' => 5504], 'geometrySha256' => str_repeat('c', 64),
            'view' => ['yaw' => 18, 'pitch' => -35, 'hFov' => 65, 'width' => 2048, 'height' => 2048],
            'sourceOrigin' => 'uploaded_native_attachment', 'sourceRole' => 'authoritative_edit_cut', 'contextRole' => 'same_capture_context_only',
            'scopeExpansion' => false, 'independentCameraEvidence' => false, 'dedicatedPoleViews' => false, 'viewRecipe' => LabAnalysisSourceContext::VIEW_RECIPE,
            'preparationHashes' => array_fill_keys(['preparer', 'imagePreparation', 'diagnosticAdapter', 'geometry'], str_repeat('d', 64))];
        $views = []; $images = [];
        foreach (LabAnalysisSourceContext::IDS as $i => $id) {
            [$width, $height] = $i === 0 ? [2048, 2048] : ($i === 1 ? [2048, 1024] : [1024, 1024]);
            $png = (string) ImageManager::gd()->create($width, $height)->fill(sprintf('%02x8888', 32 + $i * 20))->toPng();
            $images[] = 'data:image/png;base64,'.base64_encode($png);
            $view = ['id' => $id, 'sha256' => hash('sha256', $png), 'width' => $width, 'height' => $height,
                'projection' => $i === 1 ? 'equirectangular' : 'rectilinear', 'role' => $i === 0 ? 'authoritative_edit_cut' : 'same_capture_context_only'];
            if ($i === 0) $view += ['yaw' => 18, 'pitch' => -35, 'hFov' => 65];
            if ($i >= 2) $view += ['yaw' => ($i - 2) * 90, 'pitch' => 0, 'hFov' => 100];
            $views[] = $view;
        }
        $context = ['sourceSha256' => $binding['sourceSha256'], 'projection' => 'rectilinear', 'preset' => 'declutter', 'style' => null,
            'viewIds' => LabAnalysisSourceContext::IDS, 'instructions' => 'Remove only the bowl.',
            'sourceContext' => ['version' => LabAnalysisSourceContext::VERSION, 'binding' => $binding, 'bindingSha256' => LabAnalysisRecipes::hash($binding), 'views' => $views]];
        return [app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::SECTION_CONTEXT)['recipe'], $context, $images];
    }

    public function test_wire_keeps_exact_order_bytes_roles_schema_and_twenty_four_mebibyte_bound(): void
    {
        [$recipe, $context, $images] = $this->fixture();
        $payload = LabSectionAnalysisProtocol::compile($recipe, $context, $images);
        $wire = $payload['input'][0]['content'];
        $this->assertSame($context, json_decode($wire[0]['text'], true));
        foreach ($images as $i => $image) {
            $this->assertSame($image, $wire[2 + 2 * $i]['image_url']);
            $this->assertStringContainsString($context['viewIds'][$i], $wire[1 + 2 * $i]['text']);
        }
        $this->assertSame($recipe['schema'], $payload['text']['format']['schema']);
        $bytes = base64_decode(explode(',', $images[0], 2)[1]).str_repeat('x', 24 * 1024 * 1024);
        $images[0] = 'data:image/png;base64,'.base64_encode($bytes);
        $context['sourceContext']['views'][0]['sha256'] = hash('sha256', $bytes);
        $this->expectException(\InvalidArgumentException::class); $this->expectExceptionMessage('oversized');
        LabSectionAnalysisProtocol::compile($recipe, $context, $images);
    }

    public function test_swapped_image_bytes_are_rejected_even_when_dimensions_match(): void
    {
        [$recipe, $context, $images] = $this->fixture();
        [$images[2], $images[3]] = [$images[3], $images[2]];
        $this->expectException(\InvalidArgumentException::class); $this->expectExceptionMessage('ordered manifest');
        LabSectionAnalysisProtocol::compile($recipe, $context, $images);
    }

    public function test_missing_or_wrong_role_and_native_pose_mismatch_are_rejected(): void
    {
        [$recipe, $context, $images] = $this->fixture();
        $mutations = [
            fn ($c) => array_replace_recursive($c, ['sourceContext' => ['views' => [2 => ['role' => 'authoritative_edit_cut']]]]),
            function ($c) { unset($c['sourceContext']['views'][0]['role']); return $c; },
            fn ($c) => array_replace_recursive($c, ['sourceContext' => ['views' => [0 => ['yaw' => 19]]]]),
            fn ($c) => array_replace_recursive($c, ['sourceContext' => ['views' => [5 => ['yaw' => 90]]]]),
        ];
        foreach ($mutations as $mutate) {
            try { LabSectionAnalysisProtocol::compile($recipe, $mutate($context), $images); $this->fail('Invalid context was accepted.'); }
            catch (\InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }
}
