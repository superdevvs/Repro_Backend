<?php

namespace Tests\Feature;

use App\Services\SystemEmails\SystemEmailBuilder;
use Tests\TestCase;

class PhotographerEquipmentRejectedRenderingTest extends TestCase
{
    public function test_public_builder_preserves_equipment_rejection_details_and_update_link(): void
    {
        $email = app(SystemEmailBuilder::class)->build('PHOTOGRAPHER_EQUIPMENT_REJECTED', [
            'recipient' => ['name' => 'Test Photographer', 'email' => 'photographer@example.com'],
            'account' => [],
            'links' => [
                'dashboard' => 'https://example.com/dashboard',
                'equipment' => 'https://example.com/equipment/42',
            ],
            'branding' => [],
            'meta' => [
                'recipient_type' => 'photographer',
                'equipment_name' => 'Mirrorless Camera',
                'equipment_serial_number' => 'CAM-42',
                'rejected_at' => '2026-09-12T10:15:00+00:00',
                'rejection_reason' => 'Please upload a clearer serial number photo.',
            ],
        ]);

        $this->assertSame('Equipment Verification Rejected', $email['subject']);
        $this->assertStringContainsString('Mirrorless Camera', $email['body_html']);
        $this->assertStringContainsString('CAM-42', $email['body_html']);
        $this->assertStringContainsString('Sep 12, 2026 10:15 AM', $email['body_html']);
        $this->assertStringContainsString('Please upload a clearer serial number photo.', $email['body_html']);
        $this->assertStringContainsString('href="https://example.com/equipment/42"', $email['body_html']);
        $this->assertSame('CAM-42', $email['view_data']['equipmentSerialNumber']);
    }
}
