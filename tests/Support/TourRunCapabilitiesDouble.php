<?php

namespace Tests\Support;

use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabWorkflowPolicy;

/**
 * Test double for WP5's LabTourCapabilities (spec 14.1, 10.5), bound with
 * app()->instance('App\Services\IguideLab\LabTourCapabilities', ...) until WP5 merges.
 *
 * runnable() follows 10.5: golden-hour and twilight are runnable when the policy recipe is ready; grass and declutter
 * need their (D1 hard-wired off) run flags. Like the real class it calls LabWorkflowPolicy::capabilities(), so the
 * policy spy sees the call and its transaction level. $ready overrides the answer per preset (and per fresh/cached
 * call), for example ['twilight' => false] or ['grass' => true] to emulate a misreported capability.
 */
class TourRunCapabilitiesDouble
{
    /** @var array<string, bool> preset => forced answer */
    public array $ready = [];

    /** @var array<string, bool> preset => forced answer for fresh calls only (the run monitor) */
    public array $freshReady = [];

    /** @var list<array{preset:string,fresh:bool}> */
    public array $runnableCalls = [];

    public function runnable(string $preset, bool $fresh = false): bool
    {
        $this->runnableCalls[] = ['preset' => $preset, 'fresh' => $fresh];
        if ($fresh && array_key_exists($preset, $this->freshReady)) return $this->freshReady[$preset];
        if (array_key_exists($preset, $this->ready)) return $this->ready[$preset];
        $enabled = match ($preset) {
            'golden-hour', 'twilight' => true,
            'grass' => LabTourConfig::grassRunsEnabled(),
            'declutter' => LabTourConfig::declutterRunsEnabled(),
            default => false,
        };
        if (! $enabled) return false;
        $recipe = collect(app(LabWorkflowPolicy::class)->capabilities()['recipes'])->firstWhere('preset', $preset);

        return ($recipe['ready'] ?? false) === true;
    }

    /** Same shape as the real policyFacts() (spec 8.1). */
    public function policyFacts(): array
    {
        $snapshot = app(LabWorkflowPolicy::class)->snapshot();
        $limits = [];
        foreach (['golden-hour', 'twilight', 'grass', 'declutter'] as $preset) $limits[$preset] = app(\App\Services\IguideLab\LabWorkflowSteps::class)->limits($snapshot, $preset);

        return ['schedulerEnabled' => config('iguide-lab.tour_scheduler_enabled', false) === true,
            'tourBudgetEnabled' => config('iguide-lab.tour_processing_budget_enabled', false) === true,
            'policyKeys' => ['lightingRepair' => isset($snapshot['lightingRepair']), 'grassAssessment' => isset($snapshot['grassAssessment']),
                'grassSourceAdmission' => isset($snapshot['grassSourceAdmission']), 'declutterRemoval' => isset($snapshot['declutterRemoval'])],
            'limits' => $limits];
    }
}
