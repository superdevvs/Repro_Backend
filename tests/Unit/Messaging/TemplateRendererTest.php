<?php

namespace Tests\Unit\Messaging;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_booking_email_wrapper_is_theme_adaptive_and_strips_legacy_greeting_and_camera_art(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'BOOKING',
            'slug' => 'shoot-request-approved',
            'name' => 'Shoot Request Approved',
            'subject' => 'New Photo Shoot Requested (PENDING) - 10 Monroe St',
            'body_html' => '<p>Hi Priyanshu!</p><p>One of your requested photo shoots has been <strong style="color: #22c55e;">APPROVED</strong>.</p>',
            'body_text' => "Hi Priyanshu!\nOne of your requested photo shoots has been APPROVED.",
            'variables_json' => [],
        ]);

        $rendered = (new TemplateRenderer())->render($template, []);
        $html = $rendered['html'];

        $this->assertStringContainsString('color-scheme" content="light dark"', $html);
        $this->assertStringNotContainsString('hero-illustration', $html);
        $this->assertStringNotContainsString('Email Update', $html);
        $this->assertStringNotContainsString('Hi Priyanshu!', $html);
        $this->assertStringNotContainsString('#22c55e', $html);
        $this->assertStringContainsString('#1463ff', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-ogsc] body', $html);
        $this->assertStringNotContainsString('Hi Priyanshu!', $rendered['text']);
    }

    public function test_shoot_updated_subject_renders_small_lead_and_location_focused_hero(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'BOOKING',
            'slug' => 'shoot-updated',
            'name' => 'Shoot Updated',
            'subject' => 'Scheduled Photo Shoot for 2068 W Glenwood Ave, Philadelphia, PA, 19132 Updated',
            'body_html' => '<p>Latest details are below.</p>',
            'body_text' => 'Latest details are below.',
            'variables_json' => [],
        ]);

        $rendered = (new TemplateRenderer())->render($template, []);
        $html = $rendered['html'];

        $this->assertStringContainsString('hero-title-lead', $html);
        $this->assertStringContainsString('Scheduled Photo Shoot for', $html);
        $this->assertStringContainsString('hero-title-location', $html);
        $this->assertStringContainsString('2068 W Glenwood Ave, Philadelphia, PA, 19132', $html);
        $this->assertStringContainsString('font-weight: 200', $html);
        // Status badge intentionally removed from all templates: the hero no
        // longer renders a status element (e.g. "Updated").
        $this->assertStringNotContainsString('class="hero-title-status"', $html);
    }

    public function test_renderer_inlines_light_defaults_with_dark_overrides_and_mobile_footer_stacking(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'ACCOUNT',
            'slug' => 'account-created',
            'name' => 'Account Created',
            'subject' => 'New Account Information',
            'body_html' => <<<HTML
<p>Your account details are below.</p>
<div class="info-box">
    <div class="info-row"><span class="info-label">Email</span> test@example.com</div>
</div>
<div class="change-card">
    <div class="change-card-title">Updated Details</div>
    <p>One detail changed.</p>
</div>
HTML,
            'body_text' => 'Your account details are below.',
            'variables_json' => [],
        ]);

        $rendered = (new TemplateRenderer())->render($template, []);
        $html = $rendered['html'];

        $normalizedHtml = str_replace(' ', '', strtolower($html));

        $this->assertStringContainsString('class="body-card body-surface"', $html);
        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringContainsString('background-color:#ffffff;border:0;color:#47627f;', $normalizedHtml);
        $this->assertStringContainsString('class="info-box"', $html);
        $this->assertStringContainsString('background-color:#f7fbff', $normalizedHtml);
        $this->assertStringContainsString('margin:20px-32px;padding:18px52px;', $normalizedHtml);
        $this->assertStringContainsString('margin-left: -20px !important;', $html);
        $this->assertStringContainsString('class="change-card"', $html);
        $this->assertStringContainsString('color:#071223', $normalizedHtml);
        $this->assertStringContainsString('.footer-meta-cell {', $html);
        $this->assertStringContainsString('display: block !important;', $html);
        $this->assertStringContainsString('class="footer-meta-cell footer-meta-cell-last"', $html);
        $this->assertStringContainsString('background-color:#edf3fb;border:0;', $normalizedHtml);
        $this->assertStringContainsString('.dark-panel-surface {', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-ogsc] .dark-meta-surface,', $html);
        $this->assertStringContainsString('#131c2e', $html);
        $this->assertStringContainsString('linear-gradient(180deg, #18233a 0%, #121a2b 100%)', $html);
    }

    public function test_renderer_removes_top_level_photographer_row_when_services_already_show_assignment(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'BOOKING',
            'slug' => 'shoot-request-approved',
            'name' => 'Shoot Request Approved',
            'subject' => 'Scheduled Photo Shoot',
            'body_html' => <<<HTML
<div class="info-box">
    <div class="info-row"><span class="info-label">Location:</span> 6275 Kerrydale Drive</div>
    <div class="info-row"><span class="info-label">Photographer:</span> Jay Snap</div>
    <div class="info-row"><span class="info-label">Services:</span><br>[services_provided_html]</div>
</div>
HTML,
            'body_text' => "Location: 6275 Kerrydale Drive\nPhotographer: Jay Snap\n[services_provided]",
            'variables_json' => ['services_provided_html', 'services_provided'],
        ]);

        $rendered = (new TemplateRenderer())->render($template, [
            'services_provided_html' => '<ul><li>HDR Photos <div>Assigned photographer: Jay Snap</div></li></ul>',
            'services_provided' => '- HDR Photos (Photographer: Jay Snap)',
        ]);

        $this->assertStringNotContainsString('Photographer:</span> Jay Snap', $rendered['html']);
        $this->assertStringContainsString('Assigned photographer: Jay Snap', $rendered['html']);
        $this->assertDoesNotMatchRegularExpression('/^Photographer: Jay Snap$/m', $rendered['text']);
        $this->assertStringContainsString('(Photographer: Jay Snap)', $rendered['text']);
    }
}
