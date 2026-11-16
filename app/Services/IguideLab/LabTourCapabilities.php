<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use Illuminate\Support\Facades\Cache;

/**
 * Tour capabilities and runnability (spec 10.5, D18).
 *
 * LabWorkflowPolicy reports prose reasons and hashes files on every snapshot(), so its recipe readiness and the
 * policy facts the live estimate needs (8.1) are cached together for capabilities_cache_seconds (30) under one key.
 * runnable(), policyFacts() and liveFacts() may call LabWorkflowPolicy and write that cache, so callers evaluate them
 * before any lab write transaction or read snapshot (conventions) and pass the result in as facts.
 */
class LabTourCapabilities
{
    public const VERSION = 'tour-analysis/1';
    public const SURVEY_VERSION = 'tour-survey/1';
    public const DEFAULTS_VERSION = 'tour-defaults/1';
    /** TOUR_PRESETS order (tourContract.ts:28). */
    public const PRESETS = ['golden-hour', 'twilight', 'grass', 'declutter'];
    public const CACHE_KEY = 'iguide-lab-tour:capabilities:v1';
    /** Snapshot keys that change route-actual worst cases (8.1, 8.2). */
    public const POLICY_KEYS = ['lightingRepair', 'grassAssessment', 'grassSourceAdmission', 'declutterRemoval'];

    /** tourCapabilitiesResponse (tourContract.ts:512-534). */
    public function dto(IguideLabProject $project): array
    {
        $enabled = LabTourConfig::analysisEnabled();
        $presets = [];
        $recipeReady = $this->recipeReady(fresh: false);
        foreach (self::PRESETS as $preset) {
            // While the layer is off LabWorkflowPolicy is not called at all (10.5).
            $state = $enabled ? $this->state($preset, $recipeReady) : ['ready' => false, 'reason' => 'tour_disabled'];
            $presets[] = ['preset' => $preset] + $state;
        }
        $surveys = app(LabTourSurveys::class);

        return [
            'capabilities' => [
                'version' => self::VERSION,
                'enabled' => $enabled,
                'runsPaused' => LabTourConfig::runsPaused(),
                'surveyVersion' => self::SURVEY_VERSION,
                'defaultsVersion' => self::DEFAULTS_VERSION,
                'presets' => $presets,
                'limits' => [
                    'maxCaptures' => LabTourConfig::maxCaptures(),
                    'workflowChunk' => LabTourConfig::chunkSize(),
                    // 5.11: clamp(cap - used, 0, reader maximum).
                    'surveyCallsLeftProject' => max(0, min(240, LabTourConfig::surveyCallsPerProject() - (int) $surveys->attemptsUsedByProject($project->id))),
                    'surveyCallsLeftToday' => max(0, min(160, LabTourConfig::surveyCallsPerDay() - (int) $surveys->attemptsUsedToday())),
                ],
                'timing' => [
                    'surveyWorkers' => LabTourConfig::workers(),
                    'surveyMinutesPerCapture' => LabTourConfig::surveyMinutesPerCapture(),
                    'editMinutesPerRoom' => LabTourConfig::editMinutesPerRoom(),
                ],
            ],
            'analyses' => $enabled ? app(LabTourRecords::class)->summaryDtos($project) : [],
        ];
    }

    /** The preset's `ready` of dto(); $fresh (run monitor, 9.4) uses an uncached LabWorkflowPolicy::capabilities(). Never inside a transaction. */
    public function runnable(string $preset, bool $fresh = false): bool
    {
        if (! LabTourConfig::analysisEnabled() || ! in_array($preset, self::PRESETS, true)) return false;

        return $this->state($preset, $this->recipeReady($fresh))['ready'];
    }

    /**
     * Policy facts of the live estimate (8.1), from the cached snapshot. Never inside a transaction.
     *
     * @return array{schedulerEnabled:bool,tourBudgetEnabled:bool,policyKeys:array<string,bool>,limits:array<string,array<string,int>>}
     */
    public function policyFacts(): array
    {
        $cached = $this->cached();

        return [
            // Flags are read live (cheap config reads, strict style of LabWorkflowScheduler.php:15).
            'schedulerEnabled' => config('iguide-lab.tour_scheduler_enabled', false) === true,
            'tourBudgetEnabled' => config('iguide-lab.tour_processing_budget_enabled', false) === true,
            'policyKeys' => $cached['policyKeys'],
            'limits' => $cached['limits'],
        ];
    }

    /**
     * The $facts a live estimate of $preset takes (8: {policy, runnable, schedulerEnabled, tourBudgetEnabled}), from the
     * cached policy facts. Never inside a transaction. $policyFacts reuses a policyFacts() result for several presets.
     *
     * @return array{policy:array,runnable:bool,schedulerEnabled:bool,tourBudgetEnabled:bool}
     */
    public function liveFacts(string $preset, ?array $policyFacts = null): array
    {
        $policyFacts ??= $this->policyFacts();

        return ['policy' => $policyFacts, 'runnable' => $this->runnable($preset), 'schedulerEnabled' => $policyFacts['schedulerEnabled'],
            'tourBudgetEnabled' => $policyFacts['tourBudgetEnabled']];
    }

    /** Grass and declutter runs are hard-wired off in D1 (3.2); D3/D5 replace the accessors with their drivers. */
    protected function presetRunsEnabled(string $preset): bool
    {
        return match ($preset) {
            'grass' => LabTourConfig::grassRunsEnabled(),
            'declutter' => LabTourConfig::declutterRunsEnabled(),
            default => true,
        };
    }

    /**
     * D18 reason codes for one preset while the layer is on.
     *
     * @param callable(string): bool $recipeReady
     * @return array{ready:bool,reason:?string}
     */
    private function state(string $preset, callable $recipeReady): array
    {
        if (! in_array($preset, ['golden-hour', 'twilight'], true)) {
            if (! $this->presetRunsEnabled($preset)) return ['ready' => false, 'reason' => 'runs_not_enabled'];
            if (config('iguide-lab.'.$preset.'_workflow_enabled', false) !== true) return ['ready' => false, 'reason' => 'preset_disabled'];
        }

        return $recipeReady($preset) ? ['ready' => true, 'reason' => null] : ['ready' => false, 'reason' => 'recipe_unavailable'];
    }

    /** @return callable(string): bool reads the recipe list at most once, and only when a preset needs it */
    private function recipeReady(bool $fresh): callable
    {
        $recipes = null;

        return function (string $preset) use (&$recipes, $fresh): bool {
            $recipes ??= $fresh ? $this->recipesFrom(app(LabWorkflowPolicy::class)->capabilities()) : $this->cached()['recipes'];

            return $recipes[$preset] ?? false;
        };
    }

    /**
     * One cache entry holds the recipe readiness of LabWorkflowPolicy::capabilities() (base readiness,
     * LabWorkflowPolicy.php:63-70) and the policy facts of one snapshot() (8.1).
     *
     * @return array{recipes:array<string,bool>,policyKeys:array<string,bool>,limits:array<string,array<string,int>>}
     */
    private function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, LabTourConfig::capabilitiesCacheSeconds(), function (): array {
            $policy = app(LabWorkflowPolicy::class);
            $recipes = $this->recipesFrom($policy->capabilities());
            $snapshot = $policy->snapshot();
            $steps = app(LabWorkflowSteps::class);
            $limits = [];
            foreach (self::PRESETS as $preset) $limits[$preset] = array_map('intval', $steps->limits($snapshot, $preset));
            $keys = [];
            foreach (self::POLICY_KEYS as $key) $keys[$key] = isset($snapshot[$key]);

            return ['recipes' => $recipes, 'policyKeys' => $keys, 'limits' => $limits];
        });
    }

    /** @return array<string,bool> tour preset => recipe ready (a preset missing from the list is not ready) */
    private function recipesFrom(array $capabilities): array
    {
        $ready = array_fill_keys(self::PRESETS, false);
        foreach ($capabilities['recipes'] ?? [] as $recipe) {
            $preset = is_array($recipe) ? ($recipe['preset'] ?? null) : null;
            if (is_string($preset) && array_key_exists($preset, $ready)) $ready[$preset] = ($recipe['ready'] ?? false) === true;
        }

        return $ready;
    }
}
