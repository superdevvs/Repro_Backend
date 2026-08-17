<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Tests\TestCase;

class InvoiceReferenceRenderingTest extends TestCase
{
    public function test_hyphenated_invoice_identifier_is_preserved_in_email_subject_html_and_text(): void
    {
        $template = new MessageTemplate([
            'slug' => 'invoice-identifier-regression',
            'channel' => 'EMAIL',
            'subject' => 'Invoice [invoice_number] ready',
            'body_html' => '<p>Invoice Number: [invoice_number]</p><p>[invoice_number]</p>',
            'body_text' => "Invoice Number: [invoice_number]\n[invoice_number]",
            'variables_json' => ['invoice_number'],
        ]);

        $result = app(TemplateRenderer::class)->render($template, [
            'invoice_number' => 'INVOICE-1001',
        ]);
        $visibleHtml = html_entity_decode(strip_tags($result['html']));

        $this->assertSame('Invoice INVOICE-1001 ready', $result['subject']);
        $this->assertStringContainsString('Invoice Number: INVOICE-1001', $visibleHtml);
        $this->assertStringContainsString('Invoice INVOICE-1001', $visibleHtml);
        $this->assertSame("Invoice Number: INVOICE-1001\nInvoice INVOICE-1001", $result['text']);
        $this->assertStringNotContainsString('Invoice Number: -1001', $result['html'].$result['text']);
    }
}
