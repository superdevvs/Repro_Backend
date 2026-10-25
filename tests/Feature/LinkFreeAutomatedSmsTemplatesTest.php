<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\Messaging\SmsTemplateContent;
use App\Services\Messaging\SystemAutomationDefaults;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkFreeAutomatedSmsTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUS = "Shoot reminder\n{{shoot_address}}\n{{shoot_date}} {{shoot_time}}\nContact: {{sms_contact}}\nDetails: {{dashboard_link}}";

    public function test_upgrade_preserves_email_manual_custom_and_disabled_settings(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $template = MessageTemplate::where('slug', 'automation-photographer-shoot-reminder-sms')->firstOrFail();
        $template->update(['body_text' => self::PREVIOUS, 'body_html' => self::PREVIOUS, 'is_active' => false]);
        $custom = MessageTemplate::where('slug', 'automation-shoot-updated-sms')->firstOrFail();
        $custom->update(['body_text' => 'Operator wording: {{dashboard_link}}']);
        $emailBefore = MessageTemplate::where('channel', 'EMAIL')->get()->map->getAttributes()->all();
        $manual = MessageTemplate::where('slug', 'shoot-scheduled-sms')->firstOrFail();
        $manualBefore = $manual->getAttributes();

        $this->upgrade();

        $this->assertSame(SmsTemplateContent::forSlug($template->slug), $template->fresh()->body_text);
        $this->assertSame('', $template->fresh()->body_html);
        $this->assertNotContains('dashboard_link', $template->fresh()->variables_json);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame('Operator wording: {{dashboard_link}}', $custom->fresh()->body_text);
        $this->assertSame($manualBefore, $manual->fresh()->getAttributes());
        $this->assertSame($emailBefore, MessageTemplate::where('channel', 'EMAIL')->get()->map->getAttributes()->all());

        $template->update(['body_text' => 'Later operator edit']);
        $this->upgrade();
        $this->seed(MessagingSystemSeeder::class);
        app(SystemAutomationDefaults::class)->ensure();
        $this->assertSame('Later operator edit', $template->fresh()->body_text);
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_inline_upgrade_only_changes_stock_sms_and_preserves_email_links_and_routing(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $rule = AutomationRule::where('trigger_type', 'PHOTOGRAPHER_SHOOT_REMINDER')->firstOrFail();
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'stock', 'type' => 'action.sms', 'config' => ['bodyText' => self::PREVIOUS, 'smsNumberId' => 3, 'recipientRoles' => ['photographer']]];
        $workflow['nodes'][] = ['id' => 'custom', 'type' => 'action.sms', 'config' => ['bodyText' => 'Custom {{dashboard_link}}']];
        $workflow['nodes'][] = ['id' => 'email', 'type' => 'action.email', 'config' => ['bodyText' => self::PREVIOUS]];
        $rule->update(['workflow_definition_json' => $workflow, 'is_active' => false, 'schedule_json' => ['offset' => '-2h']]);
        $before = $rule->fresh()->getAttributes();

        $this->upgrade();

        $expected = $workflow;
        $expected['nodes'][count($workflow['nodes']) - 3]['config']['bodyText'] = SmsTemplateContent::forSlug('automation-photographer-shoot-reminder-sms');
        $this->assertSame($expected, $rule->fresh()->workflow_definition_json);
        $after = $rule->fresh()->getAttributes();
        unset($before['workflow_definition_json'], $before['updated_at'], $after['workflow_definition_json'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->upgrade();
        $this->assertSame($expected, $rule->fresh()->workflow_definition_json);
    }

    public function test_account_setup_moves_to_email_without_removing_setup_email_links(): void
    {
        $this->seed(MessagingSystemSeeder::class);
        $template = MessageTemplate::where('slug', 'automation-account-created-sms')->firstOrFail();
        $template->update(['body_text' => 'R/E Pro Photos: Account ready. Check your email for setup. Sign in: {{portal_url}}']);
        $this->upgrade();
        $this->assertSame('R/E Pro Photos: Account ready. Check your email for setup.', $template->fresh()->body_text);
        $this->assertSame([], $template->fresh()->variables_json);
    }

    private function upgrade(): void
    {
        (require database_path('migrations/2026_10_25_160000_make_automated_sms_defaults_link_free.php'))->up();
    }
}
