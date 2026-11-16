<?php
namespace App\Services\IguideLab;

/**
 * Panorama (u,v) box to contract region (spec 5.9, D7). Convention of pano_geometry.py:3-4,23: raw yaw 0 at
 * u = 0.5, yaw grows to the right, pitch up, longitude wraps; uMin > uMax is a box crossing the seam.
 */
final class LabTourGeometry
{
    public const FULL_SPHERE = ['yaw' => 0, 'pitch' => 0, 'hfov' => 360, 'vfov' => 180];

    /**
     * @param array{uMin:float,uMax:float,vMin:float,vMax:float} $box
     * @return ?array{yaw:float,pitch:float,hfov:float,vfov:float} null for a degenerate, inverted or malformed box
     */
    public static function region(array $box): ?array
    {
        $v = [];
        foreach (['uMin', 'uMax', 'vMin', 'vMax'] as $key) {
            $value = $box[$key] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) return null;
            $v[$key] = min(1.0, max(0.0, (float) $value));
        }
        ['uMin' => $uMin, 'uMax' => $uMax, 'vMin' => $vMin, 'vMax' => $vMax] = $v;
        if ($vMin >= $vMax || $uMin == $uMax) return null;

        $hfov = ($uMin < $uMax ? $uMax - $uMin : 1 - $uMin + $uMax) * 360;
        $yaw = ($uMin - 0.5) * 360 + $hfov / 2;
        $top = (0.5 - $vMin) * 180;
        $bottom = (0.5 - $vMax) * 180;
        $vfov = $top - $bottom;
        $pitch = ($top + $bottom) / 2;
        if ($hfov < 0.5 || $vfov < 0.5) return null;

        $pitch = round($pitch, 2) + 0.0;
        $hfov = min(round($hfov, 2), 360.0);
        $vfov = round($vfov, 2);
        if (abs($pitch) + $vfov / 2 > 90) $vfov = round(2 * (90 - abs($pitch)), 2);

        return ['yaw' => self::normalizeYaw($yaw), 'pitch' => $pitch, 'hfov' => $hfov, 'vfov' => $vfov];
    }

    /** Rounds to 2 decimals, then wraps into [-180, 180): 180 itself is invalid (tourContract.ts:88). Never -0.0. */
    public static function normalizeYaw(float $yaw): float
    {
        $y = fmod(round($yaw, 2) + 180, 360);
        if ($y < 0) $y += 360;
        $y = round($y - 180, 2);
        if ($y >= 180) $y -= 360;

        return $y + 0.0;
    }
}
