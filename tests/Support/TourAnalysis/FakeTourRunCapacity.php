<?php

namespace Tests\Support\TourAnalysis;

use App\Services\IguideLab\LabTourSelections;
use App\Services\IguideLab\LabTourView;

/**
 * Test double of WP4's LabTourRunCapacity::estimate (14.1): exactly the 8 estimateSchema keys, derived from the same
 * loaded view so the selection response stays consistent (tourContract.ts:246-264, 566-574).
 */
class FakeTourRunCapacity
{
    /** @var list<array{preset:string,revision:int}> */
    public array $calls = [];

    public function estimate(LabTourView $view, string $preset, array $facts, ?string $excludeRunId = null, ?array $fit = null): array
    {
        $effective = app(LabTourSelections::class)->effective($view, $preset);
        $rooms = count($effective);
        $revision = (int) $view->analysis->selections[$preset]['revision'];
        $this->calls[] = ['preset' => $preset, 'revision' => $revision];

        return ['selectionRevision' => $revision, 'roomsSelected' => $rooms, 'itemsSelected' => array_sum(array_map('count', $effective)),
            'roomsThatFit' => $rooms, 'worstCaseImages' => $rooms * 3, 'limitingReason' => null,
            'estimatedMinutes' => ['min' => $rooms * 3, 'max' => $rooms * 5], 'computedAt' => now()->toIso8601String()];
    }
}
