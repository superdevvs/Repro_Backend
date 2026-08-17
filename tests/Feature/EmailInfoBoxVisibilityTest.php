<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\EmailBrandingConfig;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Tests\TestCase;

class EmailInfoBoxVisibilityTest extends TestCase
{
    public function test_payment_reminder_values_have_readable_rendered_fallbacks_and_dark_mode_rules(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'category' => 'INVOICE',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - Invoice [invoice_number]',
            'body_html' => '
                <p>This invoice still has an outstanding balance.</p>
                <div class="info-box">
                    <div class="info-row"><span class="info-label">Invoice Number:</span> [invoice_number]</div>
                    <div class="info-row"><span class="info-label">Amount Due:</span> <strong style="font-size: 18px; color: #dc2626;">$[amount_due]</strong></div>
                    <div class="info-row"><span class="info-label">Due Date:</span> [due_date]</div>
                </div>',
            'body_text' => "Invoice Number: [invoice_number]\nAmount Due: $[amount_due]\nDue Date: [due_date]",
            'variables_json' => ['invoice_number', 'amount_due', 'due_date'],
        ]);

        $html = app(TemplateRenderer::class)->render($template, [
            'invoice_number' => 'Invoice 00028',
            'amount_due' => '815.02',
            'due_date' => '2026-06-15',
        ])['html'];

        $branding = app(EmailBrandingConfig::class)->defaults();
        $xpath = $this->xpath($html);
        $infoBox = $this->singleElement($xpath, "//div[contains(concat(' ', normalize-space(@class), ' '), ' info-box ')]");
        $rows = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' info-row ')]", $infoBox);

        $this->assertNotFalse($rows);
        $this->assertSame(3, $rows->length);

        $invoiceRow = $this->elementAt($rows, 0);
        $amountRow = $this->elementAt($rows, 1);
        $dueDateRow = $this->elementAt($rows, 2);
        $amount = $this->singleElement($xpath, './/strong', $amountRow);

        $this->assertStringContainsString('00028', $this->directText($invoiceRow));
        $this->assertSame('$815.02', trim($amount->textContent));
        $this->assertSame('2026-06-15', $this->directText($dueDateRow));

        foreach ([$invoiceRow, $amountRow, $dueDateRow] as $row) {
            $this->assertSame($branding['body_color_light'], $this->lastInlineColor($row));
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($this->lastInlineColor($row), $branding['section_surface_light'])
            );
        }

        // This asserts the final stabilized inline style, not the original
        // red amount style stored in an editable database template.
        $this->assertSame($branding['heading_color_light'], $this->lastInlineColor($amount));
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio($this->lastInlineColor($amount), $branding['section_surface_light'])
        );

        $this->assertDarkRule(
            $html,
            ['.info-box'],
            $branding['body_color_dark']
        );
        $this->assertDarkRule(
            $html,
            ['.info-box .info-row'],
            $branding['body_color_dark']
        );
        $this->assertDarkRule(
            $html,
            ['.info-box strong', '.info-box b'],
            $branding['heading_color_dark']
        );
        $this->assertDarkRule(
            $html,
            ['[data-ogsc] .info-box'],
            $branding['body_color_dark']
        );
        $this->assertDarkRule(
            $html,
            ['[data-ogsc] .info-box .info-row'],
            $branding['body_color_dark']
        );
        $this->assertDarkRule(
            $html,
            ['[data-ogsc] .info-box strong', '[data-ogsc] .info-box b'],
            $branding['heading_color_dark']
        );

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio($branding['body_color_dark'], $branding['section_surface_dark'])
        );
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio($branding['heading_color_dark'], $branding['section_surface_dark'])
        );
    }

    /**
     * @param  list<string>  $selectors
     */
    private function assertDarkRule(string $html, array $selectors, string $color): void
    {
        $selectorPattern = implode(
            '\\s*,\\s*',
            array_map(static fn (string $selector): string => preg_quote($selector, '/'), $selectors)
        );

        $escapedColor = preg_quote($color, '/');
        $colorPattern = '/(?:^|;)\\s*color:\\s*'.$escapedColor.'\\s*!important\\s*;/i';
        $fillPattern = '/(?:^|;)\\s*-webkit-text-fill-color:\\s*'.$escapedColor.'\\s*!important\\s*;/i';
        $matched = preg_match_all(
            '/(?:^|\\R)\\s*'.$selectorPattern.'\\s*\\{(?<declarations>[^}]*)\\}/s',
            $html,
            $matches
        );
        $this->assertNotFalse($matched);

        $declarations = collect($matches['declarations'] ?? [])->first(
            static fn (string $candidate): bool => preg_match($colorPattern, $candidate) === 1
                && preg_match($fillPattern, $candidate) === 1
        );
        $this->assertIsString(
            $declarations,
            'The rendered email is missing a dark-mode value selector that matches its final DOM.'
        );

        $this->assertMatchesRegularExpression(
            $colorPattern,
            $declarations
        );
        $this->assertMatchesRegularExpression(
            $fillPattern,
            $declarations
        );
    }

    private function directText(DOMElement $element): string
    {
        $text = '';

        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            }
        }

        return trim(preg_replace('/\\s+/', ' ', $text) ?? $text);
    }

    private function lastInlineColor(DOMElement $element): string
    {
        preg_match_all(
            '/(?<![-\\w])color:\\s*(#[0-9a-f]{6})\\s*(?:!important)?/i',
            $element->getAttribute('style'),
            $matches
        );

        $this->assertNotEmpty($matches[1], 'Expected the rendered value element to have an inline fallback color.');

        return strtolower($matches[1][array_key_last($matches[1])]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded);

        return new DOMXPath($dom);
    }

    private function singleElement(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMElement
    {
        $nodes = $xpath->query($expression, $context);

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected exactly one node for {$expression}.");

        return $this->elementAt($nodes, 0);
    }

    private function elementAt(\DOMNodeList $nodes, int $index): DOMElement
    {
        $element = $nodes->item($index);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            static fn (int $offset): float => hexdec(substr($hex, $offset, 2)) / 255,
            [0, 2, 4]
        );
        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
