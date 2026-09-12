<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\SystemEmails\EmailContextBuilder;
use App\Services\SystemEmails\EmailTypeRegistry;
use App\Services\SystemEmails\ProtectedEmailTemplates;
use App\Services\SystemEmails\SystemEmailBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtectedEmailLiveBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_protected_type_has_an_editor_row_without_enabling_or_resetting_overrides(): void
    {
        foreach (app(EmailTypeRegistry::class)->protectedAliases() as $alias) {
            $template = MessageTemplate::where('email_type', $alias)->firstOrFail();
            $this->assertFalse($template->override_enabled);
        }
        $template = MessageTemplate::where('email_type', 'CLIENT_EMAIL_VERIFIED')->firstOrFail();
        $template->update(['subject' => 'Saved subject', 'body_html' => '<p>Saved copy</p>', 'is_active' => false]);
        $count = MessageTemplate::count();
        ProtectedEmailTemplates::installMissing();
        $this->assertSame($count, MessageTemplate::count());
        $this->assertSame('Saved subject', $template->fresh()->subject);
        $this->assertSame('<p>Saved copy</p>', $template->fresh()->body_html);
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_edited_modified_request_keeps_only_the_scoped_change_details(): void
    {
        $template = MessageTemplate::where('email_type', 'SHOOT_REQUEST_MODIFIED')->firstOrFail();
        $template->update([
            'override_enabled' => true, 'subject' => 'Updated request for {{recipient_name}}',
            'body_html' => '<p>Office update</p>{{ system_body_html }}',
            'body_text' => "Office update\n{{system_body_text}}",
        ]);
        $payload = app(EmailContextBuilder::class)->build([
            'recipient' => ['name' => 'Casey Example', 'email' => 'casey@example.test'],
            'shoot' => ['id' => 42, 'location' => '12 Main Street', 'dashboard_url' => 'https://reprodashboard.com', 'services' => [['name' => 'Photography', 'formatted_total' => '$999.00']], 'formatted_grand_total' => '$999.00'],
            'meta' => ['recipient_type' => 'client', 'address' => '12 Main Street', 'changes_summary' => 'Schedule: Morning to Afternoon'],
        ]);
        $rendered = app(SystemEmailBuilder::class)->build('SHOOT_REQUEST_MODIFIED', $payload);
        $this->assertSame('Updated request for Casey Example', $rendered['subject']);
        $this->assertStringContainsString('Office update', $rendered['body_html']);
        $this->assertStringContainsString('Morning to Afternoon', $rendered['body_html']);
        $this->assertStringContainsString('Morning to Afternoon', $rendered['body_text']);
        $this->assertStringNotContainsString('$999.00', $rendered['body_html']);
        $this->assertSame(1, substr_count($rendered['body_html'], 'data-email-content="true"'));
        $this->assertStringNotContainsString('Preview example', $rendered['body_html']);
    }

    public function test_live_block_retains_admin_audience_and_payment_outcome_fallback(): void
    {
        $template = MessageTemplate::where('email_type', 'SHOOT_REQUESTED')->firstOrFail();
        $template->update(['override_enabled' => true, 'body_html' => '<p>Office note</p>{{system_body_html}}']);
        $payload = app(EmailContextBuilder::class)->build([
            'recipient' => ['name' => 'Office Admin', 'email' => 'office@example.test'],
            'shoot' => [
                'id' => 42, 'location' => '12 Main Street', 'date' => 'Sep 20, 2026', 'time' => '10:00 AM',
                'client_name' => 'Casey Example', 'client_email' => 'casey@example.test', 'photographers_label' => 'Assigned team', 'services' => [], 'property_highlights' => [], 'access_details' => [], 'notes_lines' => [],
                'dashboard_url' => 'https://reprodashboard.com', 'remaining_balance' => 0, 'payment_status' => 'paid',
            ],
            'meta' => ['recipient_type' => 'admin', 'is_admin' => true],
        ]);
        $rendered = app(SystemEmailBuilder::class)->build('SHOOT_REQUESTED', $payload);
        $this->assertStringContainsString('Office note', $rendered['body_html']);
        $this->assertStringContainsString('Please review this request in the dashboard.', $rendered['body_html']);
        $this->assertStringNotContainsString('No further action is needed right now.', $rendered['body_html']);
        $template->update(['body_html' => '<p>Pay now</p>{{system_body_html}}', 'body_text' => 'Pay now']);
        $fallback = app(SystemEmailBuilder::class)->build('SHOOT_REQUESTED', $payload);
        $this->assertStringNotContainsString('Pay now', $fallback['body_html']);
        $this->assertSame('unhealthy', $template->fresh()->override_health_status);
    }
}
