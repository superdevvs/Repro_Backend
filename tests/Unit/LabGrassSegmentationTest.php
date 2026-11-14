<?php
namespace Tests\Unit;

use App\Services\IguideLab\LabGrassMaskPixels;
use App\Services\IguideLab\LabGrassSegmentationPlan;
use App\Services\IguideLab\LabMachineMaskPixels;
use App\Services\IguideLab\LabMachineSegmentationPlan;
use Tests\TestCase;

class LabGrassSegmentationTest extends TestCase
{
    private function fixture(string $path): string { return file_get_contents(base_path('tests/Fixtures/iguide-lab/grass-integration/'.$path)); }
    private function plans(): array { return json_decode($this->fixture('plans.json'), true, flags: JSON_THROW_ON_ERROR); }
    public function test_six_source_sphere_plans_match_frozen_python_fingerprints(): void
    {
        $plans = $this->plans(); $this->assertCount(6, $plans);
        foreach ($plans as $plan) {
            app(LabGrassSegmentationPlan::class)->validate($plan, $plan['requestId']);
            $payload = app(LabGrassSegmentationPlan::class)->payload($plan, $this->fixture('images/source.png'));
            $this->assertSame($plan['payload']['prompt'], $payload['prompt']);
            $this->assertSame($plan['inputSha256'], hash('sha256', base64_decode(explode(',', $payload['image_url'], 2)[1], true)));
        }
    }
    public function test_staging_plan_remains_square_and_rejects_grass(): void
    {
        $plan = $this->plans()[0]; $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(LabMachineSegmentationPlan::class)->validate($plan, $plan['requestId']);
    }
    public function test_grass_rejects_wrong_dimensions_prompt_role_source_and_y_bound(): void
    {
        $base = $this->plans()[0];
        foreach (['height', 'prompt', 'role', 'y', 'source'] as $mutation) {
            $p = $base;
            match ($mutation) {
                'height' => $p['height'] = 2048, 'prompt' => $p['payload']['prompt'] = 'floor',
                'role' => $p['maskRole'] = 'wall', 'y' => $p['payload']['box_prompts'][0]['y_max'] = 1024,
                'source' => $p['inputSha256'] = str_repeat('f', 64),
            };
            $u = $p; unset($u['inputFingerprint']); $p['inputFingerprint'] = LabMachineSegmentationPlan::canonicalHash($u);
            try { app(LabGrassSegmentationPlan::class)->payload($p, $this->fixture('images/source.png')); $this->fail('Mutation accepted: '.$mutation); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
    }
    public function test_exact_raw_mask_encodings_and_pixel_descriptors(): void
    {
        $expected = json_decode($this->fixture('masks-index.json'), true)[0]['descriptor'];
        foreach (['L', 'LA', 'RGB', 'RGBA'] as $mode) {
            $bytes = $this->fixture('encodings/opaque-'.$mode.'.png'); $d = app(LabGrassMaskPixels::class)->describe($bytes);
            $this->assertSame(hash('sha256', $bytes), $d['sha256']);
            foreach (['width', 'height', 'bounds', 'positivePixels'] as $key) $this->assertSame($expected[$key], $d[$key]);
        }
    }
    public function test_color_transparency_square_and_trailing_bytes_reject(): void
    {
        foreach (['colored-RGB.png', 'translucent-RGBA.png', 'square-L.png'] as $name) {
            try { app(LabGrassMaskPixels::class)->describe($this->fixture('encodings/'.$name)); $this->fail('Invalid mask accepted: '.$name); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->expectException(\InvalidArgumentException::class);
        app(LabGrassMaskPixels::class)->describe($this->fixture('encodings/opaque-L.png').'extra');
    }
    public function test_staging_mask_decoder_does_not_accept_sphere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LabMachineMaskPixels::class)->describe($this->fixture('encodings/opaque-L.png'));
    }
    public function test_empty_is_not_a_class_absence_claim(): void
    {
        $d = app(LabGrassMaskPixels::class)->describe($this->fixture('encodings/empty-L.png'));
        $this->assertSame(0, $d['positivePixels']); $this->assertNull($d['bounds']);
        $this->assertArrayNotHasKey('noneObserved', $d);
    }
    public function test_original_eight_bit_alpha_all_five_png_filters(): void
    {
        foreach (range(0, 4) as $filter) {
            $base = base_path('tests/Fixtures/iguide-lab/grass-alpha/filter-'.$filter.'-');
            $valid = app(LabGrassMaskPixels::class)->describe(file_get_contents($base.'opaque.png'));
            $this->assertSame(2048 * 1024, $valid['positivePixels']);
            try { app(LabGrassMaskPixels::class)->describe(file_get_contents($base.'alpha254.png')); $this->fail('Eight-bit alpha lost under filter '.$filter); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
