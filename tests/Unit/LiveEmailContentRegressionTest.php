<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\EditableEmailContent;
use App\Services\SystemEmails\EmailPreviewContext;
use App\Services\SystemEmails\EmailTypeRegistry;
use App\Services\SystemEmails\SystemEmailRenderer;
use Tests\TestCase;

class LiveEmailContentRegressionTest extends TestCase
{
    public function test_legacy_note_and_relation_copy_appear_once_without_exposing_internal_notes(): void
    {
        $shoot = new Shoot(['shoot_notes' => "Front door access.\nPlease photograph the kitchen."]);
        $shoot->setRelation('notes', collect([
            (object) ['content' => " Front door access.\nPlease photograph the kitchen. ", 'visibility' => 'client_visible'],
            (object) ['content' => 'Include the garage.', 'visibility' => 'client_visible'],
            (object) ['content' => 'Private compensation details.', 'visibility' => 'internal'],
            (object) ['content' => '   ', 'visibility' => 'client_visible'],
        ]));

        foreach ([MailService::class => 'formatNotes', AutomationService::class => 'formatShootNotes', TemplateVariableResolver::class => 'formatShootNotes'] as $class => $method) {
            $reflection = new \ReflectionClass($class);
            $formatted = $reflection->getMethod($method)->invoke($reflection->newInstanceWithoutConstructor(), $shoot);
            $this->assertSame("Front door access.\nPlease photograph the kitchen.\nInclude the garage.", $formatted, $class);
        }
    }

    public function test_plain_email_body_removes_css_and_metadata_but_preserves_details_and_line_breaks(): void
    {
        $html = '<!doctype html><html><head><title>Internal title</title><style>:root { color-scheme: light dark; }</style></head><body><style>.email { color: blue; }</style><h1>New Shoot Scheduled</h1><p>315 Kahler Way<br>September 14, 2026 at 8:00 AM</p><p>HDR &amp; Exterior Photos: $291.50</p><script>alert("hidden")</script></body></html>';
        $this->assertSame("New Shoot Scheduled\n315 Kahler Way\nSeptember 14, 2026 at 8:00 AM\nHDR & Exterior Photos: $291.50", EditableEmailContent::plainText($html));
    }

    public function test_canonical_scheduled_email_plain_text_keeps_both_services_without_styles_for_each_recipient(): void
    {
        foreach (['client', 'photographer'] as $role) {
            $payload = EmailPreviewContext::protectedPayload('SHOOT_SCHEDULED');
            $payload['meta']['recipient_type'] = $role;
            $payload['meta']['recipient_role'] = $role;
            $payload['meta']['is_photographer'] = $role === 'photographer';
            $rendered = app(SystemEmailRenderer::class)->render(app(EmailTypeRegistry::class)->definition('SHOOT_SCHEDULED'), $payload, 'New Shoot Scheduled');
            foreach (['Premium Photo Package', 'Aerial Drone Add-on', '123 Example Lane'] as $detail) {
                $this->assertStringContainsString($detail, $rendered['body_text'], $role);
            }
            foreach ([':root', 'color-scheme:', '@media', '<style'] as $css) {
                $this->assertStringNotContainsString($css, $rendered['body_text'], $role);
            }
        }
    }
}
