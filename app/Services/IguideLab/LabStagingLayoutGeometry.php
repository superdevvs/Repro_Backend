<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Numerical consistency of proposed 2D source proxies. Never masks or metric floor truth. */
final class LabStagingLayoutGeometry
{
    public const VERSION = 'staging-layout-proxy-geometry/1';
    public const TARGETS = ['sofa', 'coffee_table', 'chair'];
    private const EPS = 1e-9;

    public static function inspect(array $layout): array
    {
        self::keys($layout, ['visibleFloor', 'forbiddenFloor', 'protectedFeatures', 'routes', 'targets']);
        $floor = self::regions($layout['visibleFloor'], 1, 4);
        $forbidden = self::regions($layout['forbiddenFloor'], 0, 12);
        $features = self::regions($layout['protectedFeatures'], 0, 12);
        $routes = self::regions($layout['routes'], 1, 4);
        if (! is_array($layout['targets']) || ! array_is_list($layout['targets']) || count($layout['targets']) !== 3) self::invalid();
        $ids = array_column($layout['targets'], 'id'); $sorted = $ids; sort($sorted);
        if ($sorted !== ['chair', 'coffee_table', 'sofa']) self::invalid();
        $targets = []; $reasons = []; $metrics = [];
        foreach ($layout['targets'] as $target) {
            self::keys($target, ['id', 'footprint', 'editEnvelope']);
            $p = self::polygon($target['footprint'], 4, 4); $b = $target['editEnvelope'];
            if (! is_array($b) || ! array_is_list($b) || count($b) !== 4) self::invalid();
            foreach ($b as $n) self::number($n);
            if ($b[0] >= $b[2] || $b[1] >= $b[3]) self::invalid();
            $box = [[$b[0], $b[1]], [$b[2], $b[1]], [$b[2], $b[3]], [$b[0], $b[3]]];
            $id = $target['id'];
            if (! self::contains($box, $p)) $reasons[] = $id.':footprint_outside_edit_envelope';
            $margin = min($b[0], $b[1], 1 - $b[2], 1 - $b[3]);
            if ($margin + self::EPS < 0.1) $reasons[] = $id.':outer_ten_percent_guard';
            if (! array_filter($floor, fn ($r) => self::contains($r['polygon'], $p))) $reasons[] = $id.':footprint_not_inside_one_visible_floor_region';
            foreach (array_merge($forbidden, $routes) as $r) {
                if (self::intersects($p, $r['polygon'])) $reasons[] = $id.':floor_clearance_overlap:'.$r['id'];
            }
            foreach ($features as $r) {
                if (self::intersects($box, $r['polygon'])) $reasons[] = $id.':protected_feature_overlap:'.$r['id'];
            }
            $targets[$id] = $p;
            $metrics[$id] = ['frameMarginFraction' => $margin, 'footprintAreaFraction' => self::area($p)];
        }
        for ($i = 0; $i < 3; $i++) for ($j = $i + 1; $j < 3; $j++) {
            $a = self::TARGETS[$i]; $b = self::TARGETS[$j];
            if (self::intersects($targets[$a], $targets[$b])) $reasons[] = $a.':footprint_overlap:'.$b;
        }
        foreach ($routes as $route) {
            if (! array_filter($floor, fn ($r) => self::contains($r['polygon'], $route['polygon']))) $reasons[] = 'route_not_inside_visible_floor:'.$route['id'];
        }
        sort($reasons); ksort($metrics);
        return ['version' => self::VERSION, 'numericallyConsistent' => $reasons === [], 'reasons' => $reasons, 'targets' => $metrics,
            'proxyOnly' => true, 'sourceFloorCertified' => false, 'metricClearanceVerified' => false,
            'realizedPlacementVerified' => false, 'supportMaskAuthority' => false];
    }

    private static function regions(mixed $regions, int $min, int $max): array
    {
        if (! is_array($regions) || ! array_is_list($regions) || count($regions) < $min || count($regions) > $max) self::invalid();
        $seen = [];
        foreach ($regions as &$r) {
            self::keys($r, ['id', 'polygon']);
            if (! is_string($r['id']) || ! preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $r['id']) || isset($seen[$r['id']])) self::invalid();
            $seen[$r['id']] = true; $r['polygon'] = self::polygon($r['polygon'], 3, 12);
        }
        return $regions;
    }

    private static function polygon(mixed $p, int $min, int $max): array
    {
        if (! is_array($p) || ! array_is_list($p) || count($p) < $min || count($p) > $max) self::invalid();
        foreach ($p as $xy) {
            if (! is_array($xy) || ! array_is_list($xy) || count($xy) !== 2) self::invalid();
            self::number($xy[0]); self::number($xy[1]);
        }
        // Strict convexity rejects self-crossing, duplicated/collinear corners and concave unsupported proxies.
        $sign = null; $n = count($p);
        for ($i = 0; $i < $n; $i++) {
            $cross = self::cross($p[$i], $p[($i + 1) % $n], $p[($i + 2) % $n]);
            if (abs($cross) <= self::EPS) self::invalid();
            $s = $cross > 0 ? 1 : -1;
            if ($sign !== null && $s !== $sign) self::invalid(); $sign = $s;
        }
        // A star polygon can have same-sign consecutive turns; every vertex must lie on every edge's inner side.
        for ($i = 0; $i < $n; $i++) foreach ($p as $q) {
            if ($sign * self::cross($p[$i], $p[($i + 1) % $n], $q) < -self::EPS) self::invalid();
        }
        if (self::area($p) <= self::EPS) self::invalid();
        return $sign > 0 ? $p : array_reverse($p);
    }

    private static function contains(array $outer, array $inner): bool
    {
        foreach ($inner as $q) for ($i = 0; $i < count($outer); $i++) {
            if (self::cross($outer[$i], $outer[($i + 1) % count($outer)], $q) < -self::EPS) return false;
        }
        return true;
    }

    private static function intersects(array $a, array $b): bool
    {
        // Separating-axis test. Touching is conservatively an unresolved clearance, not a measured gap.
        foreach ([$a, $b] as $poly) for ($i = 0; $i < count($poly); $i++) {
            $p = $poly[$i]; $q = $poly[($i + 1) % count($poly)]; $axis = [-($q[1] - $p[1]), $q[0] - $p[0]];
            $pa = array_map(fn ($v) => $v[0] * $axis[0] + $v[1] * $axis[1], $a);
            $pb = array_map(fn ($v) => $v[0] * $axis[0] + $v[1] * $axis[1], $b);
            if (max($pa) < min($pb) - self::EPS || max($pb) < min($pa) - self::EPS) return false;
        }
        return true;
    }

    private static function area(array $p): float
    {
        $s = 0; for ($i = 0; $i < count($p); $i++) { $q = $p[($i + 1) % count($p)]; $s += $p[$i][0] * $q[1] - $q[0] * $p[$i][1]; }
        return abs($s) / 2;
    }
    private static function cross(array $a, array $b, array $c): float { return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]); }
    private static function number(mixed $n): void { if ((! is_int($n) && ! is_float($n)) || ! is_finite((float) $n) || $n < 0 || $n > 1) self::invalid(); }
    private static function keys(mixed $v, array $keys): void { if (! is_array($v) || array_diff(array_keys($v), $keys) || array_diff($keys, array_keys($v))) self::invalid(); }
    private static function invalid(): never { throw new InvalidArgumentException('Malformed bounded source-layout proxy.'); }
}
