<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShootPaidEmailViewTest extends TestCase
{
    public function test_paid_email_renders_as_a_standalone_inline_styled_template(): void
    {
        $user = (object) [
            'first_name' => 'Shubh',
            'name' => 'Shubh Prasad',
        ];

        $shoot = (object) [
            'location' => '9412 Gwynndale Dr, Clinton, MD 20735',
            'date' => 'Mar 23, 2026 at 1:30 PM',
            'client_name' => 'Test User',
            'photographers_label' => 'Shubham ss, Priya b',
            'formatted_subtotal' => '$475.00',
            'tax' => 28.50,
            'formatted_tax' => '$28.50',
            'formatted_grand_total' => '$503.50',
            'dashboard_url' => 'https://reprodashboard.com',
            'website_url' => 'https://reprophotos.com',
            'review_url' => 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews',
            'support_email' => 'contact@reprophotos.com',
            'support_phone' => '202-868-1663',
            'property_highlights' => [
                ['label' => 'Bedrooms', 'value' => '3'],
                ['label' => 'Bathrooms', 'value' => '1.0'],
            ],
            'services' => [
                [
                    'display_name' => 'HDR Photos',
                    'meta' => 'Photos | $175.00 each',
                    'photographer_name' => 'Priya b',
                    'formatted_total' => '$175.00',
                ],
            ],
            'access_details' => [
                ['label' => 'Access Type', 'value' => 'Self'],
            ],
            'notes_lines' => ['test'],
        ];

        $html = view('emails.shoot_paid', [
            'user' => $user,
            'shoot' => $shoot,
            'amount' => 503.50,
        ])->render();

        $this->assertStringContainsString('This shoot has been marked as paid.', $html);
        $this->assertStringContainsString('$503.50', $html);
        $this->assertStringContainsString('https://reprodashboard.com', $html);
        $this->assertStringContainsString('9412 Gwynndale Dr, Clinton, MD 20735', $html);
        $this->assertStringContainsString('contact@reprophotos.com', $html);
        // QA #10: support phone must be the canonical number, never the obsolete one.
        $this->assertStringContainsString('202-868-1663', $html);
        $this->assertStringNotContainsString('202-868-1113', $html);

        $this->assertStringContainsString('width="120"', $html);
        $this->assertStringContainsString('max-width:720px', $html);
        $this->assertStringContainsString('display:inline-block; padding:14px 22px;', $html);
        $this->assertStringContainsString('border:0; border-radius:24px 24px 0 0;', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 640px)', $html);

        $this->assertStringContainsString('hero-card-bg', $html);
        $this->assertStringContainsString('section-card-bg', $html);
        $this->assertStringNotContainsString('@extends(', $html);
    }
}
