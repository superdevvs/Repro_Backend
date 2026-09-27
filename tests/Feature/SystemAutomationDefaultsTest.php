<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\AutomationWorkflowValidator;
use App\Services\Messaging\SystemAutomationDefaults;
use Database\Seeders\MessagingSystemSeeder;
use Database\Seeders\SystemAutomationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAutomationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_inventory_rules_have_valid_editable_actions(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rules = AutomationRule::where('scope', 'SYSTEM')->get();
        $this->assertCount(32, $rules); // CSV's 26 plus six formerly hidden or unwired senders.
        foreach ($rules as $rule) {
            $this->assertFalse($rule->is_system_locked, $rule->name);
            $result = app(AutomationWorkflowValidator::class)->validate($rule->workflow_definition_json);
            $this->assertTrue($result['valid'], $rule->name.': '.json_encode($result['errors']));
            $this->assertGreaterThan(0, $result['summary']['reachable_action_count'], $rule->name);
        }
        $this->assertSame('-2h', $rules->firstWhere('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->schedule_json['offset']);
        $this->assertFalse($rules->firstWhere('name', 'Property Contact Reminder SMS - 2 Days Before')->is_active);
        $this->assertSame(['client', 'rep'], $rules->firstWhere('name', 'Property Contact Reminder - Shoot Day')->recipients_json);
        $this->assertSame(['client', 'rep'], $rules->firstWhere('name', 'Property Contact Reminder - 1 Day Before')->recipients_json);
    }

    public function test_repeated_seed_and_ensure_preserve_operator_changes_and_disabled_state(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'WEEKLY_SALES_REPORT')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][0]['config']['schedule']['time'] = '15:45';
        $rule->update(['name' => 'My custom report', 'is_active' => false, 'schedule_json' => ['type' => 'weekly', 'time' => '15:45', 'day_of_week' => 3], 'workflow_definition_json' => $workflow]);
        $expected = $rule->fresh()->getAttributes();
        $event = AutomationRule::where('trigger_type', 'SHOOT_UPDATED')->firstOrFail();
        $event->update(['name' => 'Custom material change notice', 'is_active' => false]);
        $property = AutomationRule::where('name', 'Property Contact Reminder - 2 Days Before')->firstOrFail();
        $property->update(['name' => 'My early access reminder', 'schedule_json' => ['days_before' => 4, 'time' => '10:10'], 'condition_json' => ['days_before' => 4]]);
        $this->seed([MessagingSystemSeeder::class, SystemAutomationsSeeder::class]);
        $this->artisan('automations:ensure-system')->assertSuccessful();
        $this->assertSame($expected, $rule->fresh()->getAttributes());
        $this->assertSame(1, AutomationRule::where('trigger_type', 'WEEKLY_SALES_REPORT')->count());
        $this->assertSame(1, AutomationRule::where('trigger_type', 'SHOOT_UPDATED')->count());
        $this->assertFalse($event->fresh()->is_active);
        $this->assertSame(6, AutomationRule::where('trigger_type', 'PROPERTY_CONTACT_REMINDER')->count());
        $this->assertSame(4, $property->fresh()->schedule_json['days_before']);
    }

    public function test_live_stock_cancellation_template_mismatch_is_repaired_without_reactivating_the_rule(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'SHOOT_CANCELED')->firstOrFail();
        $deleted = MessageTemplate::where('slug', 'shoot-deleted')->firstOrFail();
        $rule->update(['template_id' => $deleted->id, 'is_active' => false]);
        $rule->unsetRelation('template');
        $workflow = app(\App\Services\Messaging\AutomationWorkflowConverter::class)->buildLegacyWorkflow($rule);
        unset($workflow['nodes'][0]['config']['schedule']); // Observed live converted shape.
        $rule->update(['workflow_definition_json' => $workflow]);
        (require database_path('migrations/2026_09_27_010000_repair_editable_system_automations.php'))->up();
        $rule->refresh();
        $this->assertSame('shoot-cancelled', $rule->template->slug);
        $email = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
        $this->assertSame($rule->template_id, $email['config']['templateId']);
        $this->assertFalse($rule->is_active);
    }

    public function test_cancellation_rule_keeps_an_authored_deletion_template_selection(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'SHOOT_CANCELED')->firstOrFail();
        $deleted = MessageTemplate::where('slug', 'shoot-deleted')->firstOrFail();
        $deleted->update(['body_text' => 'Operator-authored cancellation copy']);
        $rule->update(['template_id' => $deleted->id]);
        $rule->unsetRelation('template');
        $rule->update(['workflow_definition_json' => app(\App\Services\Messaging\AutomationWorkflowConverter::class)->buildLegacyWorkflow($rule)]);
        (require database_path('migrations/2026_09_27_010000_repair_editable_system_automations.php'))->up();
        $this->assertSame($deleted->id, $rule->fresh()->template_id);
        $this->assertSame('Operator-authored cancellation copy', $deleted->fresh()->body_text);
    }

    public function test_historical_factory_cancellation_selection_is_repaired_without_changing_saved_template_copy(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $seeder = app(MessagingSystemSeeder::class);
        $text = (new \ReflectionMethod($seeder, 'getShootDeletedPlainText'))->invoke($seeder);
        $normalized = (new \ReflectionMethod($seeder, 'normalizeTemplateDefinition'))->invoke($seeder, ['body_text' => $text]);
        $deleted = MessageTemplate::where('slug', 'shoot-deleted')->firstOrFail();
        $html = file_get_contents(base_path('tests/Fixtures/messaging/shoot-deleted-legacy.html'));
        $deleted->update(['body_html' => $html, 'body_text' => $normalized['body_text'], 'override_enabled' => true]);
        $rule = AutomationRule::where('trigger_type', 'SHOOT_CANCELED')->firstOrFail();
        $rule->update(['template_id' => $deleted->id]);
        $rule->unsetRelation('template');
        $workflow = app(\App\Services\Messaging\AutomationWorkflowConverter::class)->buildLegacyWorkflow($rule);
        unset($workflow['nodes'][0]['config']['schedule']);
        $rule->update(['workflow_definition_json' => $workflow]);

        (require database_path('migrations/2026_09_27_010000_repair_editable_system_automations.php'))->up();

        $this->assertSame('shoot-cancelled', $rule->fresh()->template->slug);
        $this->assertSame($html, $deleted->fresh()->body_html);
        $this->assertSame($normalized['body_text'], $deleted->fresh()->body_text);
        $this->assertTrue($deleted->fresh()->override_enabled);
    }

    public function test_photographer_reminder_adds_sms_when_a_real_sending_number_is_available(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $number = \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550101', 'is_default' => true]);
        $rule = AutomationRule::where('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->firstOrFail();
        $rule->update(['workflow_definition_json' => $this->unrepaired($rule->workflow_definition_json)]);
        app(SystemAutomationDefaults::class)->repair();
        $rule->refresh();
        $sms = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
        $this->assertSame($number->id, $sms['config']['smsNumberId']);
        $this->assertSame(['photographer'], $sms['config']['recipientRoles']);
        $this->assertTrue(app(AutomationWorkflowValidator::class)->validate($rule->workflow_definition_json)['valid']);
    }

    public function test_booking_change_delivery_and_cancellation_defaults_have_editable_sms_companions(): void
    {
        \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550103', 'is_default' => true]);
        AutomationRule::query()->delete();
        $this->seed(MessagingSystemSeeder::class);
        foreach (['SHOOT_SCHEDULED', 'SHOOT_BOOKED', 'PHOTOGRAPHER_ASSIGNED', 'SHOOT_REQUEST_APPROVED',
            'SHOOT_REQUEST_MODIFIED', 'SHOOT_UPDATED', 'PHOTOGRAPHER_CHANGED', 'SHOOT_COMPLETED', 'SHOOT_CANCELED'] as $trigger) {
            $rule = AutomationRule::where('trigger_type', $trigger)->firstOrFail();
            $nodes = collect($rule->workflow_definition_json['nodes']);
            $this->assertNotNull($nodes->firstWhere('type', 'action.email'), $trigger);
            $sms = $nodes->firstWhere('type', 'action.sms');
            $this->assertNotNull($sms, $trigger);
            $this->assertSame($rule->recipients_json, $sms['config']['recipientRoles'], $trigger);
            $this->assertSame('SMS', MessageTemplate::findOrFail($sms['config']['templateId'])->channel);
        }
        $removed = AutomationRule::where('trigger_type', 'SHOOT_REMOVED')->firstOrFail();
        $this->assertFalse(collect($removed->workflow_definition_json['nodes'])->contains('type', 'action.sms'));
        $edited = MessageTemplate::where('slug', 'automation-shoot-updated-sms')->firstOrFail();
        $edited->update(['body_text' => 'My saved change text', 'is_active' => false]);
        $this->seed(MessagingSystemSeeder::class);
        $this->assertSame('My saved change text', $edited->fresh()->body_text);
        $this->assertFalse($edited->fresh()->is_active);
    }

    public function test_factory_account_sms_is_visible_when_a_default_sender_exists(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $number = \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550102', 'is_default' => true]);
        $rule = AutomationRule::where('trigger_type', 'ACCOUNT_CREATED')->firstOrFail();
        $rule->update(['workflow_definition_json' => $this->unrepaired($rule->workflow_definition_json)]);
        app(SystemAutomationDefaults::class)->repair();
        $sms = collect($rule->fresh()->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
        $this->assertSame($number->id, $sms['config']['smsNumberId']);
        $this->assertSame(['account'], $sms['config']['recipientRoles']);
        $this->assertStringContainsString('{{portal_url}}', MessageTemplate::findOrFail($sms['config']['templateId'])->body_text);
    }

    public function test_one_time_repair_fixes_broken_workflows_and_preserves_custom_workflow_and_templates(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $photographer = AutomationRule::where('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->firstOrFail();
        $photographer->update(['trigger_type' => 'SHOOT_REMINDER', 'template_id' => null, 'is_active' => false, 'is_system_locked' => true, 'schedule_json' => ['offset' => '-24h'], 'workflow_definition_json' => ['nodes' => [], 'edges' => []]]);
        $sales = AutomationRule::where('trigger_type', 'WEEKLY_SALES_REPORT')->firstOrFail();
        $sales->update(['template_id' => null, 'recipients_json' => ['type' => 'role', 'roles' => ['salesRep']],
            'condition_json' => ['schedule' => 'weekly', 'day' => 'monday', 'time' => '02:00', 'command' => 'reports:sales:weekly'],
            'workflow_definition_json' => ['nodes' => [], 'edges' => []]]);
        $custom = AutomationRule::where('trigger_type', 'SHOOT_UPDATED')->firstOrFail();
        $workflow = $custom->workflow_definition_json;
        foreach ($workflow['nodes'] as &$node) {
            if ($node['type'] === 'action.email') {
                $node['config'] = ['recipientMode' => 'custom', 'customEmails' => ['ops@example.com'], 'subject' => 'Custom update', 'bodyHtml' => '<p>Keep my copy</p>'];
            }
        }
        unset($node);
        $custom->update(['workflow_definition_json' => $workflow]);
        $template = MessageTemplate::where('slug', 'shoot-updated')->firstOrFail();
        $template->update(['body_html' => '<p>Edited by operator</p>']);
        (require database_path('migrations/2026_09_27_010000_repair_editable_system_automations.php'))->up();
        $photographer->refresh();
        $this->assertSame('PHOTOGRAPHER_SHOOT_REMINDER', $photographer->trigger_type);
        $this->assertSame('-2h', $photographer->schedule_json['offset']);
        $this->assertFalse($photographer->is_active);
        $this->assertNotNull($photographer->template_id);
        $this->assertTrue(app(AutomationWorkflowValidator::class)->validate($sales->fresh()->workflow_definition_json)['valid']);
        $salesAction = collect($sales->fresh()->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
        $this->assertSame(['rep'], $salesAction['config']['recipientRoles']);
        $action = collect($custom->fresh()->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
        $this->assertSame('<p>Keep my copy</p>', $action['config']['bodyHtml']);
        $this->assertSame('<p>Edited by operator</p>', $template->fresh()->body_html);
        $photographer->update(['schedule_json' => ['offset' => '-3h']]);
        app(SystemAutomationDefaults::class)->repair();
        $this->assertSame('-3h', $photographer->fresh()->schedule_json['offset']);
    }

    public function test_repair_recognizes_renamed_sms_rules_by_their_saved_delivery_channel(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $linked = AutomationRule::where('name', 'Property Contact Reminder SMS - 1 Day Before')->firstOrFail();
        $linked->update(['name' => 'Urgent access text', 'workflow_definition_json' => $this->unrepaired($linked->workflow_definition_json)]);
        $inline = AutomationRule::where('name', 'Property Contact Reminder SMS - Shoot Day')->firstOrFail();
        $workflow = $this->unrepaired($inline->workflow_definition_json);
        foreach ($workflow['nodes'] as &$node) {
            if ($node['type'] === 'action.sms') {
                unset($node['config']['templateId']);
                $node['config']['bodyText'] = 'My saved access text';
            }
        }
        unset($node);
        $inline->update(['name' => 'Final access text', 'template_id' => null, 'workflow_definition_json' => $workflow]);

        app(SystemAutomationDefaults::class)->repair();

        foreach ([$linked->fresh(), $inline->fresh()] as $rule) {
            $this->assertSame(['client'], $rule->recipients_json);
            $this->assertSame('SMS', $rule->template->channel);
            $action = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
            $this->assertSame(['client'], $action['config']['recipientRoles']);
        }
        $inlineAction = collect($inline->fresh()->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
        $this->assertSame('My saved access text', $inlineAction['config']['bodyText']);
    }

    public function test_repair_preserves_authored_visual_schedule_when_legacy_columns_are_stale(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'WEEKLY_SALES_REPORT')->firstOrFail();
        $workflow = $this->unrepaired($rule->workflow_definition_json);
        $schedule = ['type' => 'weekly', 'day_of_week' => 4, 'time' => '16:45'];
        $workflow['nodes'][0]['config']['schedule'] = $schedule;
        $rule->update(['workflow_definition_json' => $workflow]);

        app(SystemAutomationDefaults::class)->repair();

        $rule->refresh();
        $this->assertSame($schedule, $rule->schedule_json);
        $this->assertSame($schedule, $rule->workflow_definition_json['nodes'][0]['config']['schedule']);
        $this->assertSame($schedule, $rule->entry_trigger_json['config']['schedule']);
    }

    public function test_repair_promotes_custom_legacy_wait_timing_into_the_reminder_schedule(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'SHOOT_REMINDER')->firstOrFail();
        $workflow = $this->unrepaired($rule->workflow_definition_json);
        $next = $workflow['edges'][0]['target'];
        $workflow['edges'][0]['target'] = 'operator_wait';
        $workflow['nodes'][] = ['id' => 'operator_wait', 'type' => 'wait.datetime_offset', 'config' => [
            'referenceField' => 'shoot_datetime', 'direction' => 'before', 'amount' => 3, 'unit' => 'hours',
        ]];
        $workflow['edges'][] = ['id' => 'operator_wait_action', 'source' => 'operator_wait', 'target' => $next];
        $rule->update(['workflow_definition_json' => $workflow]);

        app(SystemAutomationDefaults::class)->repair();

        $rule->refresh();
        $this->assertSame('-3h', $rule->schedule_json['offset']);
        $this->assertSame('-3h', $rule->workflow_definition_json['nodes'][0]['config']['schedule']['offset']);
        $wait = collect($rule->workflow_definition_json['nodes'])->firstWhere('id', 'operator_wait');
        $this->assertSame(3, $wait['config']['amount']);
        $this->assertTrue(app(AutomationWorkflowValidator::class)->validate($rule->workflow_definition_json)['valid']);
    }

    public function test_repair_does_not_append_sms_to_an_authored_photographer_workflow(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550101', 'is_default' => true]);
        $rule = AutomationRule::where('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->firstOrFail();
        $workflow = $this->unrepaired($rule->workflow_definition_json);
        foreach ($workflow['nodes'] as &$node) {
            if ($node['type'] === 'action.email') {
                $node['config'] = ['recipientMode' => 'roles', 'recipientRoles' => ['photographer'],
                    'subject' => 'My operational reminder', 'bodyText' => 'My saved email-only workflow'];
            }
        }
        unset($node);
        $rule->update(['workflow_definition_json' => $workflow]);

        app(SystemAutomationDefaults::class)->repair();

        $nodes = collect($rule->fresh()->workflow_definition_json['nodes']);
        $this->assertFalse($nodes->contains('type', 'action.sms'));
        $this->assertSame('My operational reminder', $nodes->firstWhere('type', 'action.email')['config']['subject']);
    }

    private function unrepaired(array $workflow): array
    {
        unset($workflow['meta']['defaults_repaired_20260927']);

        return $workflow;
    }
}
