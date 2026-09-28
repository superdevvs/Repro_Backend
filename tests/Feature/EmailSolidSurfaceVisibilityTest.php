<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\EmailPresentation;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailSolidSurfaceVisibilityTest extends TestCase
{
    #[DataProvider('themes')]
    public function test_cancellation_policy_and_custom_surfaces_follow_text_in_dark_mode(?string $theme): void
    {
        $policy = app(TemplateVariableResolver::class)->resolve(['recipient_type' => 'client'])['cancellation_policy_html'];
        $body = $policy.'
            <div id="note" class="note">Payment terms remain unchanged.</div>
            <div id="custom" style="padding:18px;background-color:rgb(255, 250, 225) !important;border:1px solid #fde68a">Custom notice</div>
            <p id="paragraph" style="background:#fffbeb">Highlighted paragraph</p>
            <span id="highlight" style="background-color:#ffeecc">Highlighted label</span>
            <table><tr><td id="legacy" bgcolor="#ffeecc">Legacy notice</td></tr></table>';
        $template = $this->template($body);
        $html = app(TemplateRenderer::class)->render($template, [], $theme)['html'];
        $xpath = $this->xpath($html);
        $surfaces = $xpath->query('//*[@data-email-content]//*[contains(@class,"email-solid-surface")]');

        $this->assertCount(6, $surfaces);
        $palette = EmailPresentation::palette($theme);
        foreach ($surfaces as $surface) {
            $this->assertInstanceOf(DOMElement::class, $surface);
            $this->assertStringContainsString('color:'.$palette['body'].';', $surface->getAttribute('style'));
            if ($theme === 'dark') {
                $this->assertSame($palette['surface'], $surface->getAttribute('bgcolor'));
                $this->assertStringContainsString('background:'.$palette['surface'].';', $surface->getAttribute('style'));
                $this->assertStringContainsString('border-color:'.$palette['border'].';', $surface->getAttribute('style'));
            }
        }
        $policyBox = $surfaces->item(0);
        $heading = $policyBox->getElementsByTagName('strong')->item(0);
        $copy = $policyBox->getElementsByTagName('span')->item(0);
        $this->assertStringContainsString('color:'.$palette['ink'].';', $heading->getAttribute('style'));
        $this->assertStringContainsString('color:'.$palette['body'].';', $copy->getAttribute('style'));
        $this->assertSame('Cancellation Policy', $heading->textContent);
        $this->assertStringContainsString('a $60 cancellation fee may apply', $copy->textContent);
        $this->assertStringContainsString('at least 6 hours', $copy->textContent);
        $this->assertSame($body, $template->body_html, 'Presentation must not rewrite the editable template.');

        if ($theme !== 'dark') {
            $this->assertStringContainsString('background:#fffbeb;', $policyBox->getAttribute('style'));
            $customStyle = $xpath->query('//*[@id="custom"]')->item(0)->getAttribute('style');
            $this->assertStringContainsString('background-color:rgb(255, 250, 225)', $customStyle);
            $this->assertStringNotContainsString('background-color:rgb(255, 250, 225) !important', $customStyle);
            $this->assertSame('#ffeecc', $xpath->query('//*[@id="legacy"]')->item(0)->getAttribute('bgcolor'));
        }
        if ($theme === null) {
            $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:dark\)\s*\{.*?\.email-solid-surface\s*\{\s*background:#1b2a3e !important;/s', $html);
            $this->assertStringContainsString('[data-ogsc] .email-solid-surface { background:#1b2a3e !important;', $html);
        }
    }

    public static function themes(): array
    {
        return ['automatic' => [null], 'light preview' => ['light'], 'dark preview' => ['dark']];
    }

    #[DataProvider('themes')]
    public function test_native_authored_foreground_and_background_pairs_stay_readable(?string $theme): void
    {
        // Native Blade templates bypass TemplateRenderer's adaptive text tagging.
        // The category pill uses the same color pair as partials/shoot-summary.
        $html = EmailPresentation::format('
            <span id="pill" class="pill-bg" style="background-color:#edf4ff;border:1px solid #d6e5ff;color:#295391;">Exclusive Listing</span>
            <p id="paragraph" style="background-color:#edf4ff;color:#295391;">Authored paragraph</p>
            <div id="container" style="background-color:#edf4ff;color:#295391;">Authored notice</div>
            <span id="adaptive" class="dark-body" style="background-color:#edf4ff;color:#295391;">Adaptive label</span>
        ', $theme);
        $xpath = $this->xpath($html);

        foreach (['pill', 'paragraph', 'container'] as $id) {
            $node = $xpath->query('//*[@id="'.$id.'"]')->item(0);
            $this->assertStringNotContainsString('email-solid-surface', $node->getAttribute('class'));
            $this->assertStringContainsString('background-color:#edf4ff;', $node->getAttribute('style'));
            $this->assertStringContainsString('color:#295391;', $node->getAttribute('style'));
        }
        $adaptive = $xpath->query('//*[@id="adaptive"]')->item(0);
        $this->assertStringContainsString('email-solid-surface', $adaptive->getAttribute('class'));
        $this->assertStringContainsString('color:'.EmailPresentation::palette($theme)['body'].';', $adaptive->getAttribute('style'));
        if ($theme === 'dark') {
            $this->assertStringContainsString('background-color:#1b2a3e;', $adaptive->getAttribute('style'));
        }
    }

    #[DataProvider('themes')]
    public function test_solid_surface_repair_preserves_artwork_transparency_and_button_contrast(?string $theme): void
    {
        $html = app(TemplateRenderer::class)->render($this->template('
            <div id="photo" style="background-color:#ffffff;background-image:url(https://example.com/room.jpg)"><img src="https://example.com/room.jpg" alt="Property exterior"></div>
            <div id="gradient" style="background:linear-gradient(#ffffff,#dddddd)">Gradient art</div>
            <table background="https://example.com/room.jpg"><tr><td id="transparent" style="background:transparent">Overlay</td></tr></table>
            <table><tr><td id="cta-wrapper" bgcolor="#155bdd">
                <a id="cta" class="button" href="https://example.com/pay"><span style="background-color:#155bdd"><strong>Pay invoice</strong></span></a>
            </td></tr></table>
        '), [], $theme)['html'];
        $xpath = $this->xpath($html);

        foreach (['photo', 'gradient', 'transparent', 'cta-wrapper', 'cta'] as $id) {
            $node = $xpath->query('//*[@id="'.$id.'"]')->item(0);
            $this->assertStringNotContainsString('email-solid-surface', $node->getAttribute('class'), $id);
        }
        $this->assertStringContainsString('background-image:url(https://example.com/room.jpg)', $xpath->query('//*[@id="photo"]')->item(0)->getAttribute('style'));
        $this->assertStringContainsString('linear-gradient(#ffffff,#dddddd)', $xpath->query('//*[@id="gradient"]')->item(0)->getAttribute('style'));
        $this->assertCount(1, $xpath->query('//img[@src="https://example.com/room.jpg"]'));
        $button = $xpath->query('//*[@id="cta"]')->item(0);
        $this->assertSame('https://example.com/pay', $button->getAttribute('href'));
        $this->assertStringContainsString('color:#ffffff;', $button->getAttribute('style'));
        $this->assertStringContainsString('background-color:#155bdd;', $button->getAttribute('style'));
        foreach ($button->getElementsByTagName('*') as $copy) {
            $this->assertStringNotContainsString('email-solid-surface', $copy->getAttribute('class'));
            $this->assertStringContainsString('color:#ffffff;', $copy->getAttribute('style'));
        }
        $this->assertStringContainsString('background-color:#155bdd', $button->getElementsByTagName('span')->item(0)->getAttribute('style'));
        $this->assertStringContainsString('.body-inner .atelier-button * { color:#ffffff !important; }', $html);
    }

    private function template(string $body): MessageTemplate
    {
        return new MessageTemplate([
            'slug' => 'shoot-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Your upcoming shoot',
            'body_html' => $body,
            'body_text' => strip_tags($body),
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
