<?php

namespace Tests\Support\TourAnalysis;

use App\Models\IguideLabTourAnalysis;
use Illuminate\Support\Facades\DB;

/** Test double of WP4's LabTourRuns::cancelWaiting (14.1): records the transaction level of each call. */
class FakeTourRuns
{
    /** @var list<int> DB::transactionLevel() at each cancelWaiting() call */
    public array $levels = [];
    /** @var array{changed:bool,cancelWorkflowIds:list<string>} */
    public array $result = ['changed' => false, 'cancelWorkflowIds' => []];

    /** @return array{changed:bool,cancelWorkflowIds:list<string>} */
    public function cancelWaiting(IguideLabTourAnalysis $analysis): array
    {
        $this->levels[] = DB::transactionLevel();

        return $this->result;
    }
}
