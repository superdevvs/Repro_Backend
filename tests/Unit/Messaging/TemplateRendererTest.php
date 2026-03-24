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
}
