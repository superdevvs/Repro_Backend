<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

class AutomationRunHistoryTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    public function test_resumed_failure_remains_visible_across_all_history_limits_without_rewriting_history(): void
    {
        Http::fake();
        Queue::fake();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->travelTo(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
        $rule = AutomationRule::create([
            'name' => 'Reminder history fixture', 'trigger_type' => 'CUSTOM_EVENT',
            'scope' => 'SYSTEM', 'is_active' => true,
        ]);
        $resumed = AutomationRun::create([
            'automation_rule_id' => $rule->id, 'trigger_type' => 'CUSTOM_EVENT',
            'status' => 'waiting', 'started_at' => now(),
        ]);
        $step = AutomationRunStep::create([
            'automation_run_id' => $resumed->id, 'automation_rule_id' => $rule->id,
            'node_id' => 'email', 'node_type' => 'action.email', 'status' => 'waiting',
            'started_at' => now(),
        ]);

        // More newer runs than either the list (3) or detail/history (20) limit.
        for ($day = 2; $day <= 26; $day++) {
            $this->travelTo(Carbon::create(2026, 9, $day, 10, 0, 0, 'UTC'));
            AutomationRun::create([
                'automation_rule_id' => $rule->id, 'trigger_type' => 'CUSTOM_EVENT',
                'status' => 'completed', 'started_at' => now(), 'completed_at' => now(),
            ]);
        }
        $this->travelTo(Carbon::parse('2026-09-27 10:00:00', 'UTC'));
        $rule->update(['name' => 'Updated reminder history fixture']);
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'UTC'));
        $resumed->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => 'private-history-canary']);
        $step->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => 'private-history-canary']);

        $historyBefore = $this->historySnapshot($rule);
        $this->assertSame($resumed->id, $rule->recentRuns()->firstOrFail()->id);

        $list = $this->getJson('/api/messaging/automations?trigger_type=CUSTOM_EVENT')->assertOk();
        $list->assertJsonCount(3, '0.recent_runs')
            ->assertJsonPath('0.recent_runs.0.id', $resumed->id)
            ->assertJsonPath('0.recent_runs.0.status', 'failed');
        $detail = $this->getJson('/api/messaging/automations/'.$rule->id)->assertOk();
        $detail->assertJsonCount(20, 'recent_runs')
            ->assertJsonPath('recent_runs.0.id', $resumed->id)
            ->assertJsonPath('recent_runs.0.steps.0.status', 'failed');
        $history = $this->getJson('/api/messaging/automations/'.$rule->id.'/runs')->assertOk();
        $history->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $resumed->id)
            ->assertJsonPath('data.0.status', 'failed');

        foreach ([$list, $detail, $history] as $response) {
            $this->assertStringNotContainsString('private-history-canary', $response->getContent());
        }
        $this->assertSame($historyBefore, $this->historySnapshot($rule));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_runs_with_equal_activity_timestamps_use_descending_ids(): void
    {
        $this->travelTo(Carbon::parse('2026-09-27 10:00:00', 'UTC'));
        $rule = AutomationRule::create([
            'name' => 'Same-second history fixture', 'trigger_type' => 'CUSTOM_EVENT',
            'scope' => 'SYSTEM', 'is_active' => true,
        ]);
        $first = AutomationRun::create(['automation_rule_id' => $rule->id, 'status' => 'completed']);
        $second = AutomationRun::create(['automation_rule_id' => $rule->id, 'status' => 'failed']);

        $this->assertSame([$second->id, $first->id], $rule->recentRuns()->pluck('id')->all());
    }

    private function historySnapshot(AutomationRule $rule): array
    {
        return [
            'rule' => $rule->fresh()->getRawOriginal(),
            'runs' => DB::table('automation_runs')->where('automation_rule_id', $rule->id)->orderBy('id')->get()->toJson(),
            'steps' => DB::table('automation_run_steps')->where('automation_rule_id', $rule->id)->orderBy('id')->get()->toJson(),
        ];
    }
}
