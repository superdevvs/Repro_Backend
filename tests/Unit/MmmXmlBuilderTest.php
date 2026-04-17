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
            'template_external_number' => null,
            'deployment_mode' => 'test',
            'url_return' => 'https://app.test/api/integrations/mmm/return',
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

        $this->assertArrayNotHasKey('FirstName', $extrinsics);
        $this->assertArrayNotHasKey('LastName', $extrinsics);
        $this->assertArrayNotHasKey('ArtworkURL', $extrinsics);
        $this->assertSame(0, $document->getElementsByTagName('Properties')->length);
        $this->assertSame(0, $document->getElementsByTagName('Property')->length);
        $this->assertSame(0, $document->getElementsByTagName('Pictures')->length);

        $supplierPartId = $document->getElementsByTagName('SupplierPartID')->item(0);
        $this->assertNotNull($supplierPartId);
        $this->assertSame('', $supplierPartId->textContent);
    }
}
