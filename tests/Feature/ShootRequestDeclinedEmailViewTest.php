<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShootRequestDeclinedEmailViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_blade_declined_email_renders_the_reason_before_the_location_summary(): void
    {
        $user = (object) [
            'first_name' => 'Shubh',
            'name' => 'Shubh Prasad',
        ];

        $shoot = (object) [
            'location' => '9412 Gwynndale Dr, Clinton, MD 20735',
            'date' => 'Mar 23, 2026 at 1:30 PM',
            'time' => '1:30 PM',
            'client_name' => 'Test User',
            'photographers_label' => 'Priya Burma',
            'dashboard_url' => 'https://reprodashboard.com',
            'property_highlights' => [],
            'services' => [],
            'access_details' => [],
            'notes_lines' => [],
            'status_label' => 'Declined',
            'service_category' => 'Photography',
            'client_email' => 'test@example.com',
            'rep_name' => 'Account Rep',
            'photographers' => [],
            'primary_photographer' => null,
        ];

        $reason = 'Seller requested a different availability window.';

        $html = view('emails.shoot_request_declined', [
            'user' => $user,
            'shoot' => $shoot,
            'declineReason' => $reason,
        ])->render();

        $this->assertStringContainsString($reason, $html);
        $this->assertStringContainsString('9412 Gwynndale Dr, Clinton, MD 20735', $html);
        $this->assertTrue(
            strpos($html, $reason) < strpos($html, '9412 Gwynndale Dr, Clinton, MD 20735')
        );
    }

    public function test_system_declined_template_renders_the_reason_before_the_location_row(): void
    {
        app(MessagingSystemSeeder::class)->run();

        $template = MessageTemplate::query()->where('slug', 'shoot-request-declined')->firstOrFail();
        $reason = 'Seller requested a different availability window.';

        $rendered = app(TemplateRenderer::class)->render($template, [
            'greeting' => 'Hello',
            'realtor_first' => 'Shubh',
            'decline_reason' => $reason,
            'shoot_location' => '9412 Gwynndale Dr, Clinton, MD 20735',
            'shoot_date' => 'Mar 23, 2026',
            'shoot_time' => '1:30 PM',
            'photographer_first' => 'Priya',
            'photographer_last' => 'Burma',
            'services_provided' => 'HDR Photos',
            'services_provided_html' => '<ul><li>HDR Photos</li></ul>',
            'shoot_notes' => 'Please call on arrival.',
            'company_email' => 'contact@reprophotos.com',
        ]);

        $this->assertStringContainsString('Decline Reason:', $rendered['html']);
        $this->assertStringContainsString($reason, $rendered['html']);
        $this->assertStringContainsString('Location:', $rendered['html']);
        $this->assertTrue(
            strpos($rendered['html'], 'Decline Reason:') < strpos($rendered['html'], 'Location:')
        );

        $this->assertStringContainsString('Decline Reason: ' . $reason, $rendered['text']);
    }
}
