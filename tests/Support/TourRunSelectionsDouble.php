<?php

namespace Tests\Support;

use App\Services\IguideLab\LabTourView;

/**
 * Test double for WP3's LabTourSelections (spec 14.1), bound with app()->instance('App\Services\IguideLab\LabTourSelections', ...)
 * until WP3 merges. It ports only what WP4 reads: effective() (spec 7.3, tourContract.ts effectiveSelection) and the
 * canonical text and sha256 (spec 7.4, tourContract.ts selectionCanonical). The real sha256()/canonical() are static;
 * WP4 calls them on the resolved instance, which PHP allows for static methods.
 */
class TourRunSelectionsDouble
{
    /** @return array<string, list<string>> lowercase caseId => ticked ids, capture order */
    public function effective(LabTourView $view, string $preset): array
    {
        $changes = $view->analysis->selections[$preset]['changes'] ?? [];
        $out = [];
        foreach ($view->captures->sortBy('position') as $capture) {
            if ($capture->status !== 'surveyed') continue;
            $caseId = strtolower($capture->case_id);
            $own = array_values(array_filter($capture->candidates ?? [], fn ($c) => ($c['preset'] ?? null) === $preset));
            $set = array_values(array_map(fn ($c) => $c['id'], array_filter($own, fn ($c) => ($c['executable'] ?? false) && ($c['defaultSelected'] ?? false))));
            $entry = $changes[$caseId] ?? null;
            if (is_array($entry)) {
                $set = array_values(array_diff($set, $entry['remove'] ?? []));
                foreach ($entry['add'] ?? [] as $id) if (! in_array($id, $set, true)) $set[] = $id;
            }
            if ($set !== []) $out[$caseId] = $set;
        }

        return $out;
    }

    public static function canonical(string $preset, array $effective): string
    {
        $rows = [];
        foreach ($effective as $caseId => $ids) {
            if (! $ids) continue;
            $ids = array_values($ids);
            sort($ids, SORT_STRING);
            $rows[strtolower((string) $caseId)] = implode(',', $ids);
        }
        ksort($rows, SORT_STRING);
        $lines = ['tour-selection/1', 'preset='.$preset];
        foreach ($rows as $caseId => $ids) $lines[] = $caseId.'='.$ids;

        return implode("\n", $lines);
    }

    public static function sha256(string $preset, array $effective): string
    {
        return hash('sha256', self::canonical($preset, $effective));
    }
}
