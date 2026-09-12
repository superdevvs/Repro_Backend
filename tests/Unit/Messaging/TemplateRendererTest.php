<?php

namespace Tests\Unit\Messaging;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_booking_email_uses_shared_theme_adaptive_artwork_and_preserves_normalization(): void
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

        $rendered = (new TemplateRenderer)->render($template, []);
        $html = $rendered['html'];

        $this->assertStringContainsString('color-scheme" content="light dark"', $html);
        $this->assertStringContainsString('shoot_request_approved.png', $html);
        $this->assertStringNotContainsString('Email Update', $html);
        $this->assertStringNotContainsString('Hi Priyanshu!', $html);
        $this->assertStringNotContainsString('#22c55e', $html);
        $this->assertStringContainsString('#155bdd', $html);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)/', $html);
        $this->assertStringContainsString('[data-ogsc] .body-bg', $html);
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

        $rendered = (new TemplateRenderer)->render($template, []);
        $html = $rendered['html'];

        $this->assertStringContainsString('hero-title-lead', $html);
        $this->assertStringContainsString('Scheduled Photo Shoot for', $html);
        $this->assertStringContainsString('hero-title-location', $html);
        $this->assertStringContainsString('2068 W Glenwood Ave, Philadelphia, PA, 19132', $html);
        $this->assertSame($template->subject, $rendered['subject']);
        // Status badge intentionally removed from all templates: the hero no
        // longer renders a status element (e.g. "Updated").
        $this->assertStringNotContainsString('class="hero-title-status"', $html);
    }

    public function test_renderer_keeps_details_inside_shared_content_and_inlines_atelier_defaults(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'ACCOUNT',
            'slug' => 'account-created',
            'name' => 'Account Created',
            'subject' => 'New Account Information',
            'body_html' => <<<'HTML'
<p>Your account details are below.</p>
<div class="info-box">
    <div class="info-row"><span class="info-label">Email</span> test@example.com</div>
</div>
<div class="change-card">
    <div class="change-card-title">Updated Details</div>
    <p>One detail changed.</p>
</div>
<a class="button" href="https://example.com/custom-next-step">My custom action</a>
HTML,
            'body_text' => 'Your account details are below.',
            'variables_json' => [],
        ]);

        $rendered = (new TemplateRenderer)->render($template, []);
        $html = $rendered['html'];

        $normalizedHtml = str_replace(' ', '', strtolower($html));

        $this->assertStringContainsString('data-email-content', $html);
        $this->assertStringContainsString('/images/email-atelier/v6/logo-light.png', $html);
        $this->assertMatchesRegularExpression('/class="info-box[^"\\n]*"[^>]*width:100%;[^>]*border-radius:12px;padding:24px;/s', $html);
        $this->assertStringNotContainsString('body-inner-after-wide', $html);
        $this->assertStringNotContainsString('padding:18px52px', $normalizedHtml);
        $this->assertStringContainsString('class="change-card', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*class="button atelier-button"[^>]*min-height:54px;[^>]*border-radius:8px;background-color:#155bdd;/s', $html);
        $this->assertStringContainsString('href="https://example.com/custom-next-step"', $html);
        $this->assertStringContainsString('My custom action', $html);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)/', $html);
        $this->assertSame(1, substr_count(strtolower($html), '<!doctype html'));
    }

    public function test_renderer_removes_top_level_photographer_row_when_services_already_show_assignment(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'BOOKING',
            'slug' => 'shoot-request-approved',
            'name' => 'Shoot Request Approved',
            'subject' => 'Scheduled Photo Shoot',
            'body_html' => <<<'HTML'
<div class="info-box">
    <div class="info-row"><span class="info-label">Location:</span> 6275 Kerrydale Drive</div>
    <div class="info-row"><span class="info-label">Photographer:</span> Jay Snap</div>
    <div class="info-row"><span class="info-label">Services:</span><br>[services_provided_html]</div>
</div>
HTML,
            'body_text' => "Location: 6275 Kerrydale Drive\nPhotographer: Jay Snap\n[services_provided]",
            'variables_json' => ['services_provided_html', 'services_provided'],
        ]);

        $rendered = (new TemplateRenderer)->render($template, [
            'services_provided_html' => '<ul><li>HDR Photos <div>Assigned photographer: Jay Snap</div></li></ul>',
            'services_provided' => '- HDR Photos (Photographer: Jay Snap)',
        ]);

        $this->assertStringNotContainsString('Photographer:</span> Jay Snap', $rendered['html']);
        $this->assertStringContainsString('Assigned photographer: Jay Snap', $rendered['html']);
        $this->assertDoesNotMatchRegularExpression('/^Photographer: Jay Snap$/m', $rendered['text']);
        $this->assertStringContainsString('(Photographer: Jay Snap)', $rendered['text']);
    }

    public function test_offline_payment_method_selects_distinct_cheque_art_without_changing_authored_copy(): void
    {
        $template = new MessageTemplate([
            'channel' => 'EMAIL',
            'category' => 'PAYMENT',
            'email_type' => 'OFFLINE_PAYMENT_INTENT_SUBMITTED',
            'name' => 'Custom offline review',
            'subject' => 'Please inspect {{payment_method_label}} payment',
            'body_html' => '<p>My review wording: [payment_method_label].</p>',
            'body_text' => 'My review wording: [payment_method_label].',
            'variables_json' => ['payment_method_label'],
        ]);

        $cheque = app(TemplateRenderer::class)->render($template, ['payment_method_label' => 'Cheque']);
        $cash = app(TemplateRenderer::class)->render($template, ['payment_method_label' => 'Cash']);

        $this->assertStringContainsString('offline_payment_intent_submitted__cheque.png', $cheque['html']);
        $this->assertStringContainsString('offline_payment_intent_submitted.png', $cash['html']);
        $this->assertStringNotContainsString('offline_payment_intent_submitted__cheque.png', $cash['html']);
        $this->assertSame('Please inspect Cheque payment', $cheque['subject']);
        $this->assertStringContainsString('My review wording: Cheque.', $cheque['html']);
        $this->assertSame('My review wording: Cash.', $cash['text']);
        $this->assertSame('Please inspect {{payment_method_label}} payment', $template->subject);
    }

    public function test_editable_body_extracts_shared_chrome_without_losing_nested_authored_content(): void
    {
        $document = '<!doctype html><html><head><title>Old shell</title></head><body>'
            .'<header>Old heading</header><main data-email-content="true">'
            .'<div><p>Authored detail</p><table><tr><td><a href="https://example.com/action">My action</a></td></tr></table></div>'
            .'</main><footer>Old footer</footer></body></html>';

        $body = app(TemplateRenderer::class)->editableBodyHtml($document);

        $this->assertStringContainsString('Authored detail', $body);
        $this->assertStringContainsString('href="https://example.com/action"', $body);
        $this->assertStringNotContainsString('Old heading', $body);
        $this->assertStringNotContainsString('Old footer', $body);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertSame($body, app(TemplateRenderer::class)->editableBodyHtml($body));
    }

    public function test_legacy_wide_cards_preserve_all_content_without_old_layout_padding(): void
    {
        $document = '<html><body><div class="hero-card">Old hero</div><div class="body-card body-surface">'
            .'<div class="body-inner body-inner-before-wide" style="padding:30px 32px"><p>Intro copy.</p></div>'
            .'<div class="info-box"><div class="info-row">Authored detail</div></div>'
            .'<div class="body-inner body-inner-after-wide"><a href="https://example.com/action">Authored action</a></div>'
            .'</div><div class="footer-wrap">Old footer</div></body></html>';

        $body = app(TemplateRenderer::class)->editableBodyHtml($document);

        $this->assertSame('<p>Intro copy.</p><div class="info-box"><div class="info-row">Authored detail</div></div><a href="https://example.com/action">Authored action</a>', $body);
        $this->assertStringNotContainsString('body-inner', $body);
        $this->assertStringNotContainsString('padding:30px', $body);
    }

    public function test_unknown_document_keeps_body_content_and_plain_fragments_are_unchanged(): void
    {
        $body = '<section><p>Custom &amp; personal content</p><img src="https://example.com/custom.png" alt="My image"></section>';
        $renderer = app(TemplateRenderer::class);

        $this->assertSame($body, $renderer->editableBodyHtml($body));
        $this->assertSame($body, $renderer->editableBodyHtml('<!doctype html><html><head><title>Unused</title></head><body>'.$body.'</body></html>'));
        $this->assertSame('', $renderer->editableBodyHtml('<html><body></body></html>'));
    }

    public function test_preview_reports_undeclared_placeholders_and_resolves_whitespace_variants(): void
    {
        $template = new MessageTemplate([
            'channel' => 'SMS',
            'subject' => 'Hello {{ client_first_name }} - Invoice {{ invoice_number }}',
            'body_html' => '<p>{{ unsupported_contact }} [amount_due]</p>',
            'body_text' => 'Hello {{ client_first_name }} [amount_due]',
            'variables_json' => [],
        ]);

        $result = app(TemplateRenderer::class)->render($template, [
            'client_first_name' => 'Jamie', 'invoice_number' => 'Invoice 00028', 'amount_due' => '$815.02',
        ]);

        $this->assertSame('Hello Jamie - Invoice 00028', $result['subject']);
        $this->assertSame('Hello Jamie $815.02', $result['text']);
        $this->assertSame(['unsupported_contact'], $result['missing']);
        $this->assertStringNotContainsString('{{', $result['html']);
    }
}
