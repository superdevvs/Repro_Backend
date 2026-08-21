<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Shoot;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\EmailContextBuilder;
use App\Services\SystemEmails\SystemEmailBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtectedEmailOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_modified_request_has_locked_subject_and_changed_fields_only_body(): void
    {
        $payload = app(EmailContextBuilder::class)->build([
            'recipient' => ['id' => 3, 'name' => 'Client', 'email' => 'client@example.test'],
            'shoot' => [
                'id' => 42,
                'location' => '12 Main St, Baltimore, MD',
                'dashboard_url' => 'https://reprodashboard.com',
                'services' => [['name' => 'Photography', 'formatted_total' => '$999.00']],
                'formatted_grand_total' => '$999.00',
            ],
            'meta' => [
                'recipient_type' => 'client',
                'address' => '12 Main St, Baltimore, MD',
                'changes_summary' => 'Schedule: Morning → Afternoon',
            ],
        ]);

        $built = app(SystemEmailBuilder::class)->build('SHOOT_REQUEST_MODIFIED', $payload);

        $this->assertSame('Shoot request updated — 12 Main St, Baltimore, MD', $built['subject']);
        $this->assertStringContainsString('Schedule', $built['body_html']);
        $this->assertStringNotContainsString('$999.00', $built['body_html']);
        $this->assertStringNotContainsString('Booked deliverables', $built['body_html']);
    }

    public function test_modified_request_subject_falls_back_to_shoot_number(): void
    {
        $payload = app(EmailContextBuilder::class)->build([
            'recipient' => ['name' => 'Client'],
            'shoot' => ['id' => 77, 'dashboard_url' => 'https://reprodashboard.com'],
            'meta' => ['recipient_type' => 'client', 'changes_summary' => 'Schedule: updated'],
        ]);

        $built = app(SystemEmailBuilder::class)->build('SHOOT_REQUEST_MODIFIED', $payload);
        $this->assertSame('Shoot request updated — Shoot #77', $built['subject']);
    }

    public function test_manual_template_resolution_never_creates_a_link_for_paid_or_bypass_shoots(): void
    {
        $resolver = app(TemplateVariableResolver::class);
        $shoot = Shoot::factory()->create([
            'total_quote' => 100,
            'bypass_paywall' => false,
        ]);

        $this->assertNotSame('', $resolver->resolve(['shoot' => $shoot])['payment_link']);

        Payment::query()->create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        $this->assertSame('', (string) $resolver->resolve(['shoot' => $shoot->fresh()])['payment_link']);

        $bypass = Shoot::factory()->create([
            'total_quote' => 100,
            'bypass_paywall' => true,
        ]);
        $this->assertSame('', (string) $resolver->resolve(['shoot' => $bypass])['payment_link']);
    }
}
