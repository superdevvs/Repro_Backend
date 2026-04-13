<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientEmailVerificationViewTest extends TestCase
{
    public function test_client_email_verification_email_uses_the_shared_branded_layout(): void
    {
        $user = (object) [
            'name' => 'Shubham Prasad',
            'email' => 'shubham@example.com',
        ];

        $html = view('emails.client_email_verification', [
            'user' => $user,
            'verificationLink' => 'https://api.reprodashboard.com/api/email/verify/1/hash?expires=123&signature=abc',
            'dashboardUrl' => 'https://reprodashboard.com',
        ])->render();

        $this->assertStringContainsString('Verify your email to unlock normal updates.', $html);
        $this->assertStringContainsString('shubham@example.com', $html);
        $this->assertStringContainsString('Verify Email', $html);
        $this->assertStringContainsString('Open Dashboard', $html);
        $this->assertStringContainsString('hero-card-bg', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('max-width:720px', $html);
        $this->assertStringContainsString('https://reprodashboard.com', $html);
        $this->assertStringContainsString('https://api.reprodashboard.com/api/email/verify/1/hash', $html);
        $this->assertStringNotContainsString('@extends(', $html);
    }
}
