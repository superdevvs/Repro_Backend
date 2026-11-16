<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabTourGeometry;
use Tests\TestCase;

/** (u,v) panorama fractions to contract regions (spec 5.9, D7; tourContract.ts:88-93). */
class IguideLabTourGeometryTest extends TestCase
{
    private function box(float $uMin, float $uMax, float $vMin, float $vMax): array
    {
        return ['uMin' => $uMin, 'uMax' => $uMax, 'vMin' => $vMin, 'vMax' => $vMax];
    }

    public function test_centre_box(): void
    {
        $region = LabTourGeometry::region($this->box(0.45, 0.55, 0.4, 0.6));
        $this->assertSame(['yaw' => 0.0, 'pitch' => 0.0, 'hfov' => 36.0, 'vfov' => 36.0], $region);
        // No negative zero reaches the wire (json_encode(-0.0) is "-0").
        $this->assertSame('{"yaw":0,"pitch":0,"hfov":36,"vfov":36}', json_encode($region));
        $this->assertSame(['yaw' => 0, 'pitch' => 0, 'hfov' => 360, 'vfov' => 180], LabTourGeometry::FULL_SPHERE);
    }

    public function test_seam_crossing_box(): void
    {
        $this->assertSame(['yaw' => -180.0, 'pitch' => -45.0, 'hfov' => 36.0, 'vfov' => 18.0],
            LabTourGeometry::region($this->box(0.95, 0.05, 0.7, 0.8)));
        $edge = LabTourGeometry::region($this->box(0.999, 0.0005, 0.4, 0.6));
        $this->assertNotNull($edge);
        $this->assertGreaterThanOrEqual(-180, $edge['yaw']);
        $this->assertLessThan(180, $edge['yaw']);
        $this->assertSame(0.54, $edge['hfov']);
    }

    public function test_full_ring_below_camera(): void
    {
        $this->assertSame(['yaw' => 0.0, 'pitch' => -76.5, 'hfov' => 360.0, 'vfov' => 27.0],
            LabTourGeometry::region($this->box(0, 1, 0.85, 1)));
        // Poles are clamped, not rejected.
        $this->assertSame(['yaw' => 0.0, 'pitch' => 76.5, 'hfov' => 360.0, 'vfov' => 27.0],
            LabTourGeometry::region($this->box(-0.2, 1.3, -0.5, 0.15)));
    }

    public function test_yaw_is_never_180_after_rounding(): void
    {
        $this->assertSame(-180.0, LabTourGeometry::normalizeYaw(179.999));
        $this->assertSame(-180.0, LabTourGeometry::normalizeYaw(180.0));
        $this->assertSame(-180.0, LabTourGeometry::normalizeYaw(-180.0));
        $this->assertSame(-180.0, LabTourGeometry::normalizeYaw(-180.004));
        $this->assertSame(179.99, LabTourGeometry::normalizeYaw(-180.006));
        $this->assertSame(-180.0, LabTourGeometry::normalizeYaw(539.995));
        $this->assertSame(170.0, LabTourGeometry::normalizeYaw(-190.0));
        $this->assertSame(0.0, LabTourGeometry::normalizeYaw(-0.001));
        $this->assertSame('0', json_encode(LabTourGeometry::normalizeYaw(-0.001)));
        // A seam box whose centre rounds to 180 is emitted as -180.
        $this->assertSame(-180.0, LabTourGeometry::region($this->box(0.999, 0.001, 0.4, 0.6))['yaw']);
    }

    public function test_degenerate_and_inverted_boxes_return_null(): void
    {
        $this->assertNull(LabTourGeometry::region($this->box(0.4, 0.6, 0.6, 0.6)), 'vMin == vMax');
        $this->assertNull(LabTourGeometry::region($this->box(0.4, 0.6, 0.7, 0.6)), 'vMin > vMax');
        $this->assertNull(LabTourGeometry::region($this->box(0.5, 0.5, 0.4, 0.6)), 'uMin == uMax');
        $this->assertNull(LabTourGeometry::region($this->box(1.2, 1.5, 0.4, 0.6)), 'both u clamp to 1');
        $this->assertNull(LabTourGeometry::region($this->box(0.5, 0.501, 0.4, 0.6)), 'hfov below 0.5 degrees');
        $this->assertNull(LabTourGeometry::region($this->box(0.4, 0.6, 0.5, 0.5025)), 'vfov below 0.5 degrees');
        $this->assertNotNull(LabTourGeometry::region($this->box(0.4, 0.6, 0.5, 0.503)), 'vfov 0.54 degrees');
        $this->assertNull(LabTourGeometry::region(['uMin' => 0.1, 'uMax' => 0.2, 'vMin' => 0.1]), 'missing key');
        $this->assertNull(LabTourGeometry::region(['uMin' => '0.1', 'uMax' => 0.2, 'vMin' => 0.1, 'vMax' => 0.3]), 'string');
        $this->assertNull(LabTourGeometry::region($this->box(NAN, 0.2, 0.1, 0.3)), 'NaN');
        $this->assertNull(LabTourGeometry::region($this->box(0.1, INF, 0.1, 0.3)), 'INF');
    }

    public function test_random_boxes_satisfy_region_schema(): void
    {
        mt_srand(20261116);
        $rand = fn () => -0.05 + 1.1 * mt_rand() / mt_getrandmax();
        $regions = 0;
        for ($i = 0; $i < 10000; $i++) {
            [$a, $b] = [$rand(), $rand()];
            if ($i % 10 !== 0) [$a, $b] = [min($a, $b), max($a, $b)];
            $region = LabTourGeometry::region($this->box($rand(), $rand(), $a, $b));
            if ($region === null) continue;
            $regions++;
            $this->assertSame(['yaw', 'pitch', 'hfov', 'vfov'], array_keys($region));
            foreach ($region as $value) $this->assertTrue(is_float($value) && is_finite($value));
            $this->assertTrue($region['yaw'] >= -180 && $region['yaw'] < 180, 'yaw '.$region['yaw']);
            $this->assertTrue($region['pitch'] >= -90 && $region['pitch'] <= 90, 'pitch '.$region['pitch']);
            $this->assertTrue($region['hfov'] > 0 && $region['hfov'] <= 360, 'hfov '.$region['hfov']);
            $this->assertTrue($region['vfov'] > 0 && $region['vfov'] <= 180, 'vfov '.$region['vfov']);
            $this->assertLessThanOrEqual(90.5, abs($region['pitch']) + $region['vfov'] / 2);
            $this->assertLessThanOrEqual(90 + 1e-9, abs($region['pitch']) + $region['vfov'] / 2);
            foreach ($region as $value) $this->assertSame(round($value, 2), $value);
        }
        $this->assertGreaterThan(7000, $regions);
    }
}
