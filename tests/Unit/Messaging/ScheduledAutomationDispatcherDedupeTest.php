<?php

namespace Tests\Unit\Messaging;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Services\Messaging\ScheduledAutomationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledAutomationDispatcherDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function rule(): AutomationRule
    {
        $template = MessageTemplate::create([
            'name' => 'Property contact SMS',
            'channel' => 'SMS',
            'scope' => 'SYSTEM',
            'is_active' => true,
            'subject' => null,
            'body_text' => 'Confirm property access',
            'variables_json' => [],
        ]);

        return AutomationRule::create([
            'name' => 'Property Contact Reminder SMS - Shoot Day',
            'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
            'scope' => 'SYSTEM',
            'is_active' => true,
            'is_system_locked' => false,
            'template_id' => $template->id,
            'recipients_json' => ['client'],
            'schedule_json' => ['days_before' => 0, 'time' => '09:00'],
        ]);
    }

    #[Test]
    public function failed_run_for_schedule_key_counts_as_already_dispatched(): void
    {
        $rule = $this->rule();
        $key = 'shoot:386:appointment:2026-09-30T10:00:00-04:00:day:2026-09-30';

        AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'trigger_type' => $rule->trigger_type,
            'status' => 'failed',
            'context_json' => [
                'schedule_dispatch_key' => $key,
                'shoot_id' => 386,
            ],
            'error_message' => 'HTTP 409 Invalid destination region',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $dispatcher = app(ScheduledAutomationDispatcher::class);

        $this->assertTrue($dispatcher->alreadyDispatched($rule, $key));
        $this->assertFalse($dispatcher->dispatch($rule, ['shoot_id' => 386], $key));
    }

    #[Test]
    public function completed_waiting_and_running_runs_also_block_redispatch(): void
    {
        $rule = $this->rule();
        $dispatcher = app(ScheduledAutomationDispatcher::class);

        foreach (['completed', 'waiting', 'running'] as $status) {
            $key = 'shoot:1:day:2026-09-30:'.$status;
            AutomationRun::create([
                'automation_rule_id' => $rule->id,
                'trigger_type' => $rule->trigger_type,
                'status' => $status,
                'context_json' => ['schedule_dispatch_key' => $key],
                'started_at' => now(),
            ]);
            $this->assertTrue($dispatcher->alreadyDispatched($rule, $key), $status);
        }
    }

    #[Test]
    public function existing_tagged_message_blocks_redispatch_without_run_row(): void
    {
        $rule = $this->rule();
        $key = 'shoot:165:appointment:2026-09-30T12:30:00-04:00:day:2026-09-30';
        $tag = 'SCHEDULED_AUTOMATION:'.$rule->id.':'.$key;

        Message::create([
            'channel' => 'SMS',
            'direction' => 'OUTBOUND',
            'provider' => 'TELNYX',
            'status' => 'FAILED',
            'send_source' => 'AUTOMATION',
            'to_address' => '(240) 997-0198',
            'body_text' => 'Confirm property access',
            'tags_json' => [$tag],
            'related_shoot_id' => 165,
        ]);

        $dispatcher = app(ScheduledAutomationDispatcher::class);
        $this->assertTrue($dispatcher->alreadyDispatched($rule, $key));
        $this->assertFalse($dispatcher->dispatch($rule, [], $key));
    }

    #[Test]
    public function fresh_key_is_not_already_dispatched(): void
    {
        $rule = $this->rule();
        $dispatcher = app(ScheduledAutomationDispatcher::class);

        $this->assertFalse($dispatcher->alreadyDispatched(
            $rule,
            'shoot:999:appointment:2026-09-30T10:00:00-04:00:day:2026-09-30'
        ));
    }
}
