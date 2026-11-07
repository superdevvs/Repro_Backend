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
        Sanctum::actingAs(User::factory()->superAdmin()->create());

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

    public function test_completed_workflow_exposes_live_sms_failures_without_replaying_or_rewriting_history(): void
    {
        Http::fake();
        Queue::fake();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $rule = AutomationRule::create(['name' => 'SMS history', 'trigger_type' => 'CUSTOM_EVENT', 'scope' => 'SYSTEM', 'is_active' => true]);
        $run = AutomationRun::create(['automation_rule_id' => $rule->id, 'status' => 'completed', 'completed_at' => now()]);
        $failed = \App\Models\Message::create(['channel' => 'SMS', 'direction' => 'OUTBOUND', 'status' => 'FAILED',
            'to_address' => '+12025550123', 'body_text' => 'Fixture',
            'error_message' => \App\Services\Messaging\SmsDeliveryFailure::message(['40010'])]);
        $pending = \App\Models\Message::create(['channel' => 'SMS', 'direction' => 'OUTBOUND', 'status' => 'SENT',
            'to_address' => '+12025550456', 'body_text' => 'Fixture']);
        AutomationRunStep::create(['automation_run_id' => $run->id, 'automation_rule_id' => $rule->id,
            'node_id' => 'sms', 'node_type' => 'action.sms', 'status' => 'completed',
            'output_json' => ['channel' => 'sms', 'message_ids' => [$failed->id, $pending->id, $failed->id, 999999]]]);
        $before = $this->historySnapshot($rule);
        foreach ([['/api/messaging/automations?trigger_type=CUSTOM_EVENT', '0.recent_runs.0'],
            ['/api/messaging/automations/'.$rule->id, 'recent_runs.0'],
            ['/api/messaging/automations/'.$rule->id.'/runs', 'data.0']] as [$url, $path]) {
            $this->getJson($url)->assertOk()->assertJsonPath($path.'.status', 'completed')
                ->assertJsonPath($path.'.sms_delivery.status', 'failed')
                ->assertJsonPath($path.'.sms_delivery.total', 3)
                ->assertJsonPath($path.'.sms_delivery.failed', 1)
                ->assertJsonPath($path.'.sms_delivery.pending', 1)
                ->assertJsonPath($path.'.sms_delivery.unknown', 1)
                ->assertJsonPath($path.'.sms_delivery.errors.0', $failed->error_message);
        }
        $failed->update(['error_message' => 'private-delivery-canary']);
        $response = $this->getJson('/api/messaging/automations/'.$rule->id.'/runs')->assertOk();
        $this->assertStringNotContainsString('private-delivery-canary', $response->getContent());
        $this->assertSame($before, $this->historySnapshot($rule));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
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
