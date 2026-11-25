<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * automation_runs and automation_run_steps grow without limit. The daily model:prune removes
 * finished runs (and their steps) older than messaging.automation_runs_retention_days. The age is
 * the owner's choice, so nothing is pruned until it is set.
 */
class AutomationRunPruningTest extends TestCase
{
    use RefreshDatabase;

    private AutomationRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'UTC'));
        $this->rule = AutomationRule::create([
            'name' => 'Pruning fixture', 'trigger_type' => 'CUSTOM_EVENT',
            'scope' => 'SYSTEM', 'is_active' => true,
        ]);
    }

    public function test_nothing_is_pruned_until_an_age_is_set(): void
    {
        $this->assertNull((require config_path('messaging.php'))['automation_runs_retention_days'],
            'The shipped default keeps every run until the owner picks an age.');
        config(['messaging.automation_runs_retention_days' => null]);
        $run = $this->runAgedDays(400, 'completed', ['completed']);

        $this->prune();

        $this->assertTrue(AutomationRun::query()->whereKey($run->id)->exists());
        $this->assertSame(1, AutomationRunStep::query()->count());
    }

    public function test_finished_runs_older_than_the_age_go_with_their_steps(): void
    {
        config(['messaging.automation_runs_retention_days' => 90]);
        $oldCompleted = $this->runAgedDays(100, 'completed', ['completed', 'completed']);
        $oldFailed = $this->runAgedDays(100, 'failed', ['failed']);
        $oldCancelled = $this->runAgedDays(100, 'cancelled', ['cancelled']);
        $recentCompleted = $this->runAgedDays(30, 'completed', ['completed']);
        $oldRunning = $this->runAgedDays(100, 'running', ['running']);
        $oldWaiting = $this->runAgedDays(100, 'waiting', ['waiting']);
        $oldFinishedWithWaitingStep = $this->runAgedDays(100, 'completed', ['completed', 'waiting']);

        $this->prune();

        $remaining = AutomationRun::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame([
            $recentCompleted->id, $oldRunning->id, $oldWaiting->id, $oldFinishedWithWaitingStep->id,
        ], $remaining);
        $this->assertSame(0, AutomationRunStep::query()
            ->whereIn('automation_run_id', [$oldCompleted->id, $oldFailed->id, $oldCancelled->id])->count());
        $this->assertSame(5, AutomationRunStep::query()->count(), 'Steps of kept runs stay.');
    }

    public function test_an_age_below_thirty_days_is_raised_to_thirty(): void
    {
        config(['messaging.automation_runs_retention_days' => 7]);
        $tenDaysOld = $this->runAgedDays(10, 'completed', []);
        $this->runAgedDays(31, 'completed', []);

        $this->prune();

        $this->assertSame([$tenDaysOld->id], AutomationRun::query()->pluck('id')->all());
    }

    public function test_pruning_works_in_chunks(): void
    {
        config(['messaging.automation_runs_retention_days' => 90]);
        foreach (range(1, 5) as $ignored) {
            $this->runAgedDays(100, 'completed', ['completed']);
        }

        $this->artisan('model:prune', ['--model' => [AutomationRun::class], '--chunk' => 2])->assertSuccessful();

        $this->assertSame(0, AutomationRun::query()->count());
        $this->assertSame(0, AutomationRunStep::query()->count());
    }

    /**
     * @param  list<string>  $stepStatuses
     */
    private function runAgedDays(int $days, string $status, array $stepStatuses): AutomationRun
    {
        $createdAt = now()->subDays($days);
        $run = AutomationRun::query()->forceCreate([
            'automation_rule_id' => $this->rule->id, 'trigger_type' => 'CUSTOM_EVENT', 'status' => $status,
            'started_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        foreach ($stepStatuses as $index => $stepStatus) {
            AutomationRunStep::query()->forceCreate([
                'automation_run_id' => $run->id, 'automation_rule_id' => $this->rule->id,
                'node_id' => 'node-'.$index, 'node_type' => 'action.email', 'status' => $stepStatus,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        return $run;
    }

    private function prune(): void
    {
        $this->artisan('model:prune', ['--model' => [AutomationRun::class]])->assertSuccessful();
    }
}
