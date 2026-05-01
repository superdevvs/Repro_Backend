<?php

namespace Tests\Unit;

use App\Services\MmmXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmXmlBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_the_mmm_vendor_property_prefill_xml_shape(): void
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
            'template_external_number' => null,
            'deployment_mode' => 'test',
            'url_return' => 'https://app.test/api/integrations/mmm/return',
            'property' => [
                'id' => 'WAFGX96178',
                'price' => '1225000',
                'address' => '123 Development Dr',
                'city' => 'Gaithersburg',
                'state' => 'MD',
                'zip' => '20987',
                'description' => 'Welcome to 123 Development Dr.',
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
            ],
        ]);

        $document = new \DOMDocument();
        $document->loadXML($xml);

        $extrinsics = [];
        foreach ($document->getElementsByTagName('Extrinsic') as $node) {
            $extrinsics[$node->getAttribute('name')] = $node->textContent;
        }

        $this->assertSame('Repro', $extrinsics['CostCenter'] ?? null);
        $this->assertSame('print-admin@example.com', $extrinsics['UserEmail'] ?? null);
        $this->assertSame('print-admin@example.com', $extrinsics['UniqueName'] ?? null);
        $this->assertSame('Print', $extrinsics['FirstName'] ?? null);
        $this->assertSame('Admin', $extrinsics['LastName'] ?? null);
        $this->assertSame('category', $extrinsics['StartPoint'] ?? null);

        $this->assertArrayNotHasKey('UserFirstName', $extrinsics);
        $this->assertArrayNotHasKey('UserLastName', $extrinsics);
        $this->assertArrayNotHasKey('ArtworkURL', $extrinsics);

        $this->assertSame(1, $document->getElementsByTagName('Properties')->length);
        $this->assertSame(1, $document->getElementsByTagName('Property')->length);
        $this->assertSame('WAFGX96178', $document->getElementsByTagName('ID')->item(0)?->textContent);
        $this->assertSame('1225000', $document->getElementsByTagName('Price')->item(0)?->textContent);
        $this->assertSame('123 Development Dr', $document->getElementsByTagName('Address')->item(0)?->textContent);
        $this->assertSame('Gaithersburg', $document->getElementsByTagName('City')->item(0)?->textContent);
        $this->assertSame('MD', $document->getElementsByTagName('State')->item(0)?->textContent);
        $this->assertSame('20987', $document->getElementsByTagName('Zip')->item(0)?->textContent);
        $this->assertSame('Welcome to 123 Development Dr.', $document->getElementsByTagName('Description')->item(0)?->textContent);
        $this->assertSame(1, $document->getElementsByTagName('Pictures')->length);
        $this->assertSame(2, $document->getElementsByTagName('Picture')->length);
        $this->assertSame('front.jpg', $document->getElementsByTagName('FileName')->item(0)?->textContent);
        $this->assertSame('https://cdn.example.test/images/front.jpg', $document->getElementsByTagName('URL')->item(0)?->textContent);

        $this->assertSame(0, $document->getElementsByTagName('BrowserFormPost')->length);
        $this->assertSame(0, $document->getElementsByTagName('SelectedItem')->length);
        $this->assertSame(0, $document->getElementsByTagName('SupplierPartID')->length);
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
