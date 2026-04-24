<?php

namespace Tests\Unit;

use App\Services\MmmXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmXmlBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_the_phase_one_vendor_xml_shape(): void
    {
        $xml = app(MmmXmlBuilder::class)->buildPunchoutSetupRequest([
            'duns' => 'Wqsw5cPn3Neo9Blz',
            'shared_secret' => 'XxdU9n5pP8bWb4DG',
            'user_agent' => 'REPro Photos Tests',
            'buyer_cookie' => '00000000-0000-0000-0000-000000000001',
            'cost_center_number' => 'Repro',
            'employee_email' => 'print-admin@example.com',
            'username' => 'print-admin@example.com',
            'first_name' => 'Print',
            'last_name' => 'Admin',
            'start_point' => 'category',
            'artwork_url' => 'https://cdn.example.test/artwork/property-flyer.pdf',
            'template_external_number' => null,
            'deployment_mode' => 'test',
            'url_return' => 'https://app.test/api/integrations/mmm/return',
            'address' => '250 MMM Lane, Baltimore, MD 21201',
            'pictures' => [
                [
                    'id' => '11',
                    'caption' => 'Front Exterior',
                    'filename' => 'front.jpg',
                    'url' => 'https://cdn.example.test/images/front.jpg',
                ],
                [
                    'id' => '12',
                    'caption' => 'Kitchen',
                    'filename' => 'kitchen.jpg',
                    'url' => 'https://cdn.example.test/images/kitchen.jpg',
                ],
            ],
        ]);

        $document = new \DOMDocument();
        $document->loadXML($xml);

        $extrinsics = [];
        foreach ($document->getElementsByTagName('Extrinsic') as $node) {
            $extrinsics[$node->getAttribute('name')] = $node->textContent;
        }

        $this->assertSame('Repro', $extrinsics['CostCenter'] ?? null);
        $this->assertSame('Print', $extrinsics['UserFirstName'] ?? null);
        $this->assertSame('Admin', $extrinsics['UserLastName'] ?? null);
        $this->assertSame('print-admin@example.com', $extrinsics['UserEmail'] ?? null);
        $this->assertSame('print-admin@example.com', $extrinsics['UniqueName'] ?? null);
        $this->assertSame('category', $extrinsics['StartPoint'] ?? null);
        $this->assertSame('https://cdn.example.test/artwork/property-flyer.pdf', $extrinsics['ArtworkURL'] ?? null);

        $this->assertArrayNotHasKey('FirstName', $extrinsics);
        $this->assertArrayNotHasKey('LastName', $extrinsics);

        $this->assertSame(1, $document->getElementsByTagName('Properties')->length);
        $this->assertSame(1, $document->getElementsByTagName('Property')->length);
        $this->assertSame('250 MMM Lane, Baltimore, MD 21201', $document->getElementsByTagName('Address')->item(0)?->textContent);
        $this->assertSame(1, $document->getElementsByTagName('Pictures')->length);
        $this->assertSame(2, $document->getElementsByTagName('Picture')->length);
        $this->assertSame('front.jpg', $document->getElementsByTagName('FileName')->item(0)?->textContent);
        $this->assertSame('https://cdn.example.test/images/front.jpg', $document->getElementsByTagName('URL')->item(1)?->textContent);

        $supplierPartId = $document->getElementsByTagName('SupplierPartID')->item(0);
        $this->assertNotNull($supplierPartId);
        $this->assertSame('', $supplierPartId->textContent);
    }

    #[Test]
    public function it_prefers_the_start_page_url_when_parsing_a_punchout_response(): void
    {
        $parsed = app(MmmXmlBuilder::class)->parsePunchoutSetupResponse(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE cXML SYSTEM "http://xml.cXML.org/schemas/cXML/1.2.007/cXML.dtd">
<cXML payloadID="response" timestamp="2026-04-17T00:00:00Z" version="1.0" xml:lang="en">
  <Response>
    <Status code="200" text="OK"/>
    <PunchOutSetupResponse>
      <StartPage>
        <URL>ProductCats.asp?cid=&amp;el=abc123</URL>
      </StartPage>
    </PunchOutSetupResponse>
  </Response>
</cXML>
XML);

        $this->assertSame('200', $parsed['status_code']);
        $this->assertSame('OK', $parsed['status_text']);
        $this->assertSame('ProductCats.asp?cid=&el=abc123', $parsed['redirect_url']);
        $this->assertTrue($parsed['success']);
    }
}
