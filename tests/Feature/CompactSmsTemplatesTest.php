<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\SmsTemplateContent;
use App\Services\Messaging\SystemAutomationDefaults;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompactSmsTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_REMINDER = 'Shoot {{shoot_date}} {{shoot_time}}: {{shoot_address}}. Map: {{map_link}}. Contact: {{property_contact_name}} {{property_contact_phone}}. Access: {{access_instructions}}. Services: {{services_provided}}. Details: {{dashboard_link}}';

    public function test_all_default_sms_templates_render_compact_copy_with_one_link(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $variables = app(TemplateVariableResolver::class)->resolve([
            'shoot_address' => '108 James Street Ext, Woodsboro, MD, 21798',
            'shoot_date' => 'Sep 28, 2026', 'shoot_time' => '10:00 AM EDT',
            'property_contact_name' => 'Lauren', 'property_contact_phone' => '4435045965',
            'dashboard_link' => 'https://reprodashboard.com/shoots/104',
            'portal_url' => 'https://reprodashboard.com', 'assignment_status' => 'Removed from shoot',
            'map_link' => 'https://maps.example.com/very-long-map-link',
            'access_instructions' => str_repeat('Private access instruction. ', 40),
            'shoot_change_summary' => str_repeat('Detailed change. ', 40),
            'services_provided' => str_repeat('Long service list. ', 40),
        ]);

        $templates = MessageTemplate::where('channel', 'SMS')->where('scope', 'SYSTEM')->get();
        $this->assertCount(20, $templates);
        foreach ($templates as $template) {
            $rendered = app(TemplateRenderer::class)->render($template, $variables);
            $body = $rendered['body_text'];
            $this->assertSame([], $rendered['missing'], $template->slug);
            $this->assertSame(1, preg_match_all('~https?://~', $body), $template->slug);
            $this->assertLessThan(260, strlen($body), $template->slug);
            $this->assertStringNotContainsString('{{', $body, $template->slug);
            $this->assertDoesNotMatchRegularExpression('/Map:|Access:|Services:|Private access|Detailed change|Long service/', $body);
            if ($template->slug !== 'automation-account-created-sms') {
                $this->assertStringContainsString("108 James Street Ext, Woodsboro, MD, 21798\nSep 28, 2026 10:00 AM EDT\nContact: Lauren 4435045965\nDetails: https://reprodashboard.com/shoots/104", $body, $template->slug);
            }
        }
    }

    public function test_contact_falls_back_to_client_when_access_fields_are_empty(): void
    {
        $resolver = app(TemplateVariableResolver::class);
        $variables = $resolver->resolve([
            'property_contact_name' => ' ', 'property_contact_phone' => '',
            'client' => ['name' => 'Jamie Example', 'phonenumber' => '2025550142'],
        ]);
        $this->assertSame('Jamie Example 2025550142', $variables['sms_contact']);
        $this->assertSame('See shoot details', $resolver->resolve([])['sms_contact']);
        $this->assertSame(' ', $variables['property_contact_name']); // Existing email values stay intact.
        $this->assertSame('Jamie Example 2025550142', $resolver->resolve([
            'property_contact_name' => 'Lauren',
            'client' => ['name' => 'Jamie Example', 'phonenumber' => '2025550142'],
        ])['sms_contact']);
        $this->assertSame('4435045965', $resolver->resolve([
            'property_contact_phone' => '4435045965',
            'client' => ['name' => 'Jamie Example', 'phonenumber' => '2025550142'],
        ])['sms_contact']);
        $this->assertSame('Authored preview contact', $resolver->resolve(['sms_contact' => 'Authored preview contact'])['sms_contact']);
        $template = new MessageTemplate(['channel' => 'SMS', 'body_text' => '{{sms_contact}}']);
        $preview = app(\App\Services\Messaging\EmailPreviewVariables::class)->apply($template, $resolver->resolve([]));
        $this->assertSame('Morgan Example 202-555-0144', $preview['sms_contact']);
        $client = \App\Models\User::factory()->create(['name' => 'Jamie Example', 'phonenumber' => '2025550142']);
        $shoot = \App\Models\Shoot::factory()->create([
            'client_id' => $client->id,
            'property_details' => ['accessContactName' => 'Lauren', 'accessContactPhone' => ''],
        ]);
        $shootContext = app(\App\Services\Messaging\AutomationService::class)->buildShootContext($shoot);
        $this->assertSame('Jamie Example 2025550142', $resolver->resolve($shootContext)['sms_contact']);
    }

    public function test_upgrade_changes_saved_stock_sms_but_preserves_email_and_operator_edits(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $reminder = MessageTemplate::where('slug', 'automation-photographer-shoot-reminder-sms')->firstOrFail();
        $reminder->update(['body_text' => self::LEGACY_REMINDER, 'is_active' => false, 'variables_json' => ['map_link', 'access_instructions']]);
        $custom = MessageTemplate::where('slug', 'automation-shoot-updated-sms')->firstOrFail();
        $custom->update(['body_text' => 'Custom saved SMS. Keep my wording.', 'is_active' => false]);
        $email = MessageTemplate::where('slug', 'photographer-shoot-reminder')->firstOrFail();
        $emailBefore = $email->getAttributes();
        $rulesBefore = AutomationRule::all()->map->getAttributes()->all();

        $this->upgrade();

        $this->assertSame(SmsTemplateContent::forSlug($reminder->slug), $reminder->fresh()->body_text);
        $this->assertNotContains('map_link', $reminder->fresh()->variables_json);
        $this->assertFalse($reminder->fresh()->is_active);
        $this->assertSame('Custom saved SMS. Keep my wording.', $custom->fresh()->body_text);
        $this->assertFalse($custom->fresh()->is_active);
        $this->assertSame($emailBefore, $email->fresh()->getAttributes());
        $this->assertSame($rulesBefore, AutomationRule::all()->map->getAttributes()->all());

        $reminder->update(['body_text' => 'Operator edit after upgrade']);
        $this->upgrade();
        $this->seed(MessagingSystemSeeder::class);
        app(SystemAutomationDefaults::class)->ensure();
        $this->assertSame('Operator edit after upgrade', $reminder->fresh()->body_text);
        $this->assertFalse($reminder->fresh()->is_active);
    }

    public function test_upgrade_compacts_stock_inline_sms_without_changing_workflow_configuration(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'stock_sms', 'type' => 'action.sms', 'config' => ['bodyText' => self::LEGACY_REMINDER, 'recipientMode' => 'roles', 'recipientRoles' => ['photographer']]];
        $workflow['nodes'][] = ['id' => 'authored_sms', 'type' => 'action.sms', 'config' => ['bodyText' => 'Keep custom inline text']];
        $rule->update(['workflow_definition_json' => $workflow, 'is_active' => false, 'schedule_json' => ['offset' => '-3h']]);
        $expected = $rule->fresh()->getAttributes();

        $this->upgrade();

        $expectedWorkflow = $workflow;
        $expectedWorkflow['nodes'][count($workflow['nodes']) - 2]['config']['bodyText'] = SmsTemplateContent::forSlug('automation-photographer-shoot-reminder-sms');
        $this->assertSame($expectedWorkflow, $rule->fresh()->workflow_definition_json);
        $actual = $rule->fresh()->getAttributes();
        unset($expected['workflow_definition_json'], $expected['updated_at'], $actual['workflow_definition_json'], $actual['updated_at']);
        $this->assertSame($expected, $actual);
    }

    public function test_upgrade_removes_the_duplicate_link_from_saved_property_access_sms(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $template = MessageTemplate::where('slug', 'property-contact-reminder-sms')->firstOrFail();
        $original = 'REPRO: Action required for shoot at {{shoot_location}} on {{shoot_date}} at {{shoot_time}}. Please provide property access details (who will be at property or lockbox info). Update: {{portal_url}}';
        $template->update(['body_html' => $original, 'body_text' => $original."\n\n{{access_warning}} Update: {{dashboard_link}}"]);

        $this->upgrade();

        $this->assertSame(SmsTemplateContent::forSlug($template->slug), $template->fresh()->body_text);
        $this->assertSame('', $template->fresh()->body_html);
        $this->assertNotContains('access_warning', $template->fresh()->variables_json);
    }

    private function upgrade(): void
    {
        (require database_path('migrations/2026_09_28_180000_compact_sms_template_content.php'))->up();
    }
}
