<?php

namespace Tests\Feature;

use App\Models\AutomationDispatch;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\User;
use App\Services\Messaging\AutomationWorkflowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

class AutomationErrorPrivacyTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_historical_runs_steps_and_latest_dispatch_exclude_raw_diagnostics_and_execution_secrets(): void
    {
        $rule = AutomationRule::create(['name' => 'Privacy fixture', 'trigger_type' => 'CUSTOM_EVENT', 'scope' => 'SYSTEM', 'is_active' => true]);
        $run = AutomationRun::create(['automation_rule_id' => $rule->id, 'trigger_type' => 'CUSTOM_EVENT', 'status' => 'failed',
            'context_json' => ['password_reset_link' => 'secret-canary'], 'error_message' => 'secret-canary']);
        $step = AutomationRunStep::create(['automation_run_id' => $run->id, 'automation_rule_id' => $rule->id, 'node_id' => 'email', 'node_type' => 'action.email',
            'status' => 'failed', 'input_json' => ['token' => 'secret-canary'], 'error_message' => 'secret-canary',
            'output_json' => ['output' => 'secret-canary', 'response' => ['token' => 'secret-canary'], 'channel' => 'email', 'sent_to' => ['recipient@example.test']]]);
        AutomationDispatch::create(['automation_rule_id' => $rule->id, 'trigger_type' => 'CUSTOM_EVENT', 'period_key' => 'privacy',
            'scheduled_for' => now(), 'command' => 'privacy:fixture', 'status' => 'failed', 'output' => 'secret-canary', 'error_message' => 'secret-canary']);
        $serialized = $rule->fresh()->load(['recentRuns.steps', 'latestDispatch'])->toJson();
        $this->assertStringNotContainsString('secret-canary', $serialized);
        $this->assertStringContainsString('recipient@example.test', $serialized);
        $this->assertSame('secret-canary', $run->getAttribute('context_json')['password_reset_link']);
        $this->assertSame('secret-canary', $step->getAttribute('output_json')['output']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->getJson('/api/messaging/automations/'.$rule->id.'/runs')->assertOk();
        $response->assertJsonPath('data.0.status', 'failed');
        $this->assertStringNotContainsString('secret-canary', $response->getContent());
    }

    public function test_system_run_failures_store_safe_text_while_rethrowing_the_original_exception(): void
    {
        $rule = AutomationRule::create(['name' => 'Privacy fixture', 'trigger_type' => 'CUSTOM_EVENT', 'scope' => 'SYSTEM', 'is_active' => true]);
        $exception = new \RuntimeException('provider secret-canary');
        try {
            app(AutomationWorkflowExecutor::class)->createSystemRun($rule, [], 'privacy:fixture', now(), fn () => throw $exception);
            $this->fail('The internal failure should be propagated.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        foreach ([AutomationRun::firstOrFail(), AutomationRunStep::firstOrFail()] as $model) {
            $this->assertSame('failed', $model->status);
            $this->assertStringNotContainsString('secret-canary', $model->getRawOriginal('error_message'));
        }
    }
}
