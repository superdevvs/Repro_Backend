<?php

namespace Tests\Unit\Messaging;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_booking_email_wrapper_is_dark_mode_safe_and_strips_legacy_greeting_and_camera_art(): void
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
        $this->assertStringContainsString('hero-title-status', $html);
        $this->assertStringContainsString('Updated', $html);
    }

    public function test_renderer_inlines_dark_safe_surfaces_and_mobile_footer_stacking(): void
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

        $this->assertStringContainsString('class="body-card body-surface"', $html);
        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringContainsString('background-color:#111c2e;border:1pxsolid#24344d;color:#a9b8cb;', str_replace(' ', '', $html));
        $this->assertStringContainsString('class="info-box"', $html);
        $this->assertStringContainsString('background-color:#16233a', str_replace(' ', '', $html));
        $this->assertStringContainsString('class="change-card"', $html);
        $this->assertStringContainsString('color:#e8edf5', str_replace(' ', '', $html));
        $this->assertStringContainsString('.footer-meta-cell {', $html);
        $this->assertStringContainsString('display: block !important;', $html);
        $this->assertStringContainsString('class="footer-meta-cell footer-meta-cell-last"', $html);
        $this->assertStringContainsString('background-color:#142237;border:1pxsolid#2d4263;', str_replace(' ', '', $html));
        $this->assertStringContainsString('.dark-panel-surface {', $html);
        $this->assertStringContainsString('[data-ogsc] .dark-meta-surface {', $html);
    }
}
