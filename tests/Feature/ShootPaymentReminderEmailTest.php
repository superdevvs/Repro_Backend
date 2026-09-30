<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ShootPaymentReminderTemplate;
use App\Services\Messaging\SystemAutomationDefaults;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShootPaymentReminderEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_default_rule_uses_a_shoot_balance_template_with_a_real_pay_link(): void
    {
        AutomationRule::query()->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        app(SystemAutomationDefaults::class)->ensure();

        $template = MessageTemplate::where('slug', ShootPaymentReminderTemplate::SLUG)->firstOrFail();
        $rule = AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->firstOrFail();
        $this->assertSame($template->id, $rule->template_id);
        $emailNode = collect($rule->workflow_definition_json['nodes'])->firstWhere('type', 'action.email');
        $this->assertSame($template->id, $emailNode['config']['templateId']);

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.test']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'address' => '20 Oak Street',
            'total_quote' => 350,
            'payment_status' => 'partial',
            'bypass_paywall' => false,
        ]);
        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $context = app(TemplateVariableResolver::class)
            ->resolve(app(AutomationService::class)->buildShootContext($shoot->fresh()));
        $rendered = app(TemplateRenderer::class)->render($template, $context);

        $this->assertSame('250.00', $context['remaining_balance']);
        $this->assertNotEmpty($context['payment_link']);
        $this->assertNotEmpty($context['dashboard_link']);
        $this->assertStringContainsString((string) $context['payment_link'], $rendered['body_html']);
        $this->assertStringContainsString((string) $context['dashboard_link'], $rendered['body_html']);
        $this->assertStringContainsString('$250.00', $rendered['body_html']);
        $this->assertStringContainsString('20 Oak Street', $rendered['subject']);
        $this->assertStringNotContainsString('Invoice', $rendered['subject'].$rendered['body_text']);
        $this->assertStringNotContainsString('Due date', $rendered['body_text']);
        $this->assertStringNotContainsString('{{', $rendered['body_html'].$rendered['body_text']);
    }

    public function test_migration_changes_only_the_stock_rule_selection_and_keeps_rule_edits(): void
    {
        app(MessagingSystemSeeder::class)->run();
        $invoice = MessageTemplate::where('slug', 'payment-due-reminder')->firstOrFail();
        $shootTemplate = ShootPaymentReminderTemplate::installMissing();
        AutomationRule::query()->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        $workflow = [
            'nodes' => [
                ['id' => 'email', 'type' => 'action.email', 'config' => ['templateId' => $invoice->id, 'recipientRoles' => ['client']]],
                ['id' => 'sms', 'type' => 'action.sms', 'config' => ['bodyText' => 'Authored SMS']],
            ],
            'edges' => [['id' => 'email_sms', 'source' => 'email', 'target' => 'sms']],
            'meta' => ['system_default_key' => 'Shoot Payment Reminder'],
        ];
        $rule = AutomationRule::create([
            'scope' => 'SYSTEM', 'name' => 'Shoot Payment Reminder', 'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'template_id' => $invoice->id, 'is_active' => false,
            'schedule_json' => ['reminder_days' => [2, 5], 'time' => '11:15'],
            'recipients_json' => ['client', 'rep'], 'workflow_definition_json' => $workflow,
        ]);
        $originalInvoiceCopy = [$invoice->subject, $invoice->body_html, $invoice->body_text];

        $migration = require database_path('migrations/2026_09_30_200000_separate_shoot_payment_reminder_email.php');
        $migration->up();
        $migration->up();

        $rule->refresh();
        $this->assertSame($shootTemplate->id, $rule->template_id);
        $this->assertSame($shootTemplate->id, $rule->workflow_definition_json['nodes'][0]['config']['templateId']);
        $this->assertSame($workflow['nodes'][1], $rule->workflow_definition_json['nodes'][1]);
        $this->assertSame($workflow['edges'], $rule->workflow_definition_json['edges']);
        $this->assertSame(['reminder_days' => [2, 5], 'time' => '11:15'], $rule->schedule_json);
        $this->assertSame(['client', 'rep'], $rule->recipients_json);
        $this->assertFalse($rule->is_active);
        $invoice->refresh();
        $this->assertSame($originalInvoiceCopy, [$invoice->subject, $invoice->body_html, $invoice->body_text]);
    }

    public function test_migration_preserves_custom_template_and_inline_rule_copy(): void
    {
        app(MessagingSystemSeeder::class)->run();
        $invoice = MessageTemplate::where('slug', 'payment-due-reminder')->firstOrFail();
        $custom = MessageTemplate::create([
            'slug' => 'custom-shoot-balance', 'name' => 'Custom Shoot Balance', 'channel' => 'EMAIL',
            'scope' => 'SYSTEM', 'category' => 'PAYMENT', 'subject' => 'Custom subject',
            'body_html' => '<p>Custom balance email</p>', 'body_text' => 'Custom balance email',
            'variables_json' => [], 'is_system' => true, 'is_active' => true,
        ]);
        AutomationRule::query()->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        $rule = AutomationRule::create([
            'scope' => 'SYSTEM', 'name' => 'Shoot Payment Reminder', 'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'template_id' => $custom->id, 'is_active' => true,
            'workflow_definition_json' => ['nodes' => [
                ['id' => 'email', 'type' => 'action.email', 'config' => ['templateId' => $custom->id, 'recipientRoles' => ['client']]],
            ], 'edges' => []],
        ]);
        $inline = AutomationRule::create([
            'scope' => 'SYSTEM', 'name' => 'Shoot Payment Reminder', 'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'template_id' => $invoice->id, 'is_active' => false,
            'workflow_definition_json' => ['nodes' => [
                ['id' => 'email2', 'type' => 'action.email', 'config' => ['templateId' => $invoice->id, 'bodyHtml' => '<p>Authored copy</p>']],
            ], 'edges' => []],
        ]);
        $authoredTemplate = ShootPaymentReminderTemplate::installMissing();
        $authoredTemplate->update(['subject' => 'Saved subject', 'body_html' => '<p>Saved body</p>', 'is_active' => false]);

        $migration = require database_path('migrations/2026_09_30_200000_separate_shoot_payment_reminder_email.php');
        $migration->up();
        app(SystemAutomationDefaults::class)->ensure();

        $this->assertSame($custom->id, $rule->fresh()->template_id);
        $this->assertSame($custom->id, $rule->fresh()->workflow_definition_json['nodes'][0]['config']['templateId']);
        $this->assertSame($invoice->id, $inline->fresh()->template_id);
        $this->assertSame('<p>Authored copy</p>', $inline->fresh()->workflow_definition_json['nodes'][0]['config']['bodyHtml']);
        $this->assertSame('Saved subject', $authoredTemplate->fresh()->subject);
        $this->assertSame('<p>Saved body</p>', $authoredTemplate->fresh()->body_html);
        $this->assertFalse($authoredTemplate->fresh()->is_active);
    }
}
