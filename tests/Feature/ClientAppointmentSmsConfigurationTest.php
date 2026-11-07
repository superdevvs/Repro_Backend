<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\SmsNumber;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\SmsTemplateContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientAppointmentSmsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_does_not_write_and_apply_preserves_schedule_disabled_state_and_existing_actions(): void
    {
        Http::fake();
        Queue::fake();
        [$rule, $sender] = $this->fixture();
        $before = $rule->refresh()->getRawOriginal();
        $templateCount = MessageTemplate::count();
        $args = ['rule' => $rule->id, '--sender' => $sender->id];
        $this->artisan('messaging:configure-client-reminder-sms', $args)->assertSuccessful();
        $this->assertSame($before, $rule->fresh()->getRawOriginal());
        $this->assertSame($templateCount, MessageTemplate::count());
        $original = $rule->workflow_definition_json;
        $this->artisan('messaging:configure-client-reminder-sms', $args + ['--apply' => true])->assertSuccessful();
        $rule->refresh();
        $this->assertFalse($rule->is_active);
        $this->assertSame(json_decode($before['schedule_json'], true), $rule->schedule_json);
        $this->assertSame($original['nodes'], array_slice($rule->workflow_definition_json['nodes'], 0, count($original['nodes'])));
        $sms = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
        $this->assertSame(['client'], $sms['config']['recipientRoles']);
        $this->assertSame($sender->id, $sms['config']['smsNumberId']);
        $template = MessageTemplate::findOrFail($sms['config']['templateId']);
        $this->assertSame(SmsTemplateContent::forSlug('automation-photographer-shoot-reminder-sms'), $template->body_text);
        $this->assertStringNotContainsString('{{dashboard_link}}', $template->body_text);
        $snapshot = $rule->getRawOriginal();
        $template->update(['body_text' => 'Saved operator copy', 'is_active' => false]);
        $this->artisan('messaging:configure-client-reminder-sms', $args + ['--apply' => true])->assertSuccessful();
        $this->assertSame($snapshot, $rule->fresh()->getRawOriginal());
        $this->assertSame('Saved operator copy', $template->fresh()->body_text);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_plain_text_option_is_explicit_and_invalid_workflow_is_untouched(): void
    {
        [$rule, $sender] = $this->fixture();
        $args = ['rule' => $rule->id, '--sender' => $sender->id, '--without-link' => true, '--apply' => true];
        $this->artisan('messaging:configure-client-reminder-sms', $args)->assertSuccessful();
        $template = MessageTemplate::where('slug', 'client-appointment-reminder-plain-sms')->firstOrFail();
        $this->assertStringNotContainsString('{{dashboard_link}}', $template->body_text);
        $this->assertStringContainsString('R/E Pro Photos', $template->body_text);
        $this->assertStringContainsString('Reply STOP', $template->body_text);
        $rule->update(['workflow_definition_json' => ['nodes' => [], 'edges' => []]]);
        $before = $rule->refresh()->getRawOriginal();
        $this->artisan('messaging:configure-client-reminder-sms', $args)->assertFailed();
        $this->assertSame($before, $rule->fresh()->getRawOriginal());
    }

    public function test_adding_a_step_preserves_an_existing_disabled_custom_template(): void
    {
        Http::fake();
        Queue::fake();
        [$rule, $sender] = $this->fixture();
        $template = MessageTemplate::create([
            'slug' => 'client-appointment-reminder-sms', 'name' => 'Saved client reminder',
            'channel' => 'SMS', 'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => false,
            'body_text' => 'Saved operator copy {{dashboard_link}}', 'variables_json' => ['dashboard_link'],
        ]);
        $before = $template->fresh()->getRawOriginal();

        $this->artisan('messaging:configure-client-reminder-sms', [
            'rule' => $rule->id, '--sender' => $sender->id, '--apply' => true,
        ])->assertSuccessful();

        $sms = collect($rule->fresh()->workflow_definition_json['nodes'])->firstWhere('type', 'action.sms');
        $this->assertSame($template->id, $sms['config']['templateId']);
        $this->assertSame($before, $template->fresh()->getRawOriginal());
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function fixture(): array
    {
        $template = MessageTemplate::create(['name' => 'Appointment', 'slug' => 'test-client-appointment', 'channel' => 'EMAIL',
            'scope' => 'SYSTEM', 'is_active' => true, 'subject' => 'Reminder', 'body_text' => '{{shoot_address}}']);
        $rule = AutomationRule::create(['name' => 'Client reminders', 'trigger_type' => 'SHOOT_REMINDER', 'scope' => 'SYSTEM',
            'is_active' => false, 'template_id' => $template->id, 'recipients_json' => ['client', 'photographer'],
            'schedule_json' => ['offset' => '-24h', 'client_email_schedule' => ['previous_day_time' => '09:30']]]);
        $rule->update(['workflow_definition_json' => app(AutomationWorkflowConverter::class)->buildLegacyWorkflow($rule)]);
        $sender = SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+18885550123', 'owner_type' => 'GLOBAL']);

        return [$rule, $sender];
    }
}
