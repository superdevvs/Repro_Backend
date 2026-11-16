<?php

namespace Tests\Support;

use App\Services\IguideLab\LabCatalog;
use App\Services\IguideLab\LabWorkflowPolicy;
use Illuminate\Support\Facades\DB;

/**
 * LabWorkflowPolicy for tour tests: canned recipe readiness and a canned snapshot (with lightingRepair), call counts,
 * and the transaction level of every call, so a test can prove the policy is only read at its baseline level
 * (spec conventions; 10.5 "never inside a transaction"). Named apart from the WP4 SpyLabWorkflowPolicy (13.1).
 */
class TourPolicySpy extends LabWorkflowPolicy
{
    public int $capabilitiesCalls = 0;
    public int $snapshotCalls = 0;
    /** @var list<int> */
    public array $levels = [];
    /** @var array<string, bool> */
    public array $ready = ['golden-hour' => true, 'twilight' => true, 'grass' => true, 'declutter' => true];

    public function __construct(public readonly int $baseline) {}

    public function snapshot(): array
    {
        $this->snapshotCalls++;
        $this->levels[] = DB::transactionLevel();

        return ['version' => self::VERSION, 'modelId' => 'gpt-image-2.6-sunburst', 'maxImageAttemptsPerItem' => 3, 'maxPlanRevisionsPerItem' => 2,
            'lightingRepair' => ['version' => 'fixture']];
    }

    public function capabilities(): array
    {
        $this->capabilitiesCalls++;
        $this->levels[] = DB::transactionLevel();

        return ['supported' => true, 'ready' => true, 'version' => self::VERSION, 'recipes' => array_map(fn ($preset) => ['preset' => $preset,
            'ready' => $this->ready[$preset] ?? false, 'reason' => ($this->ready[$preset] ?? false) ? null : 'Required automatic checking is not available yet.'],
            array_keys(LabCatalog::PRESETS))];
    }
}
