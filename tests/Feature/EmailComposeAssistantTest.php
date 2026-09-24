<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReproAi\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailComposeAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_returns_the_drafted_email_instead_of_the_instruction(): void
    {
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletion')
                ->once()
                ->andReturn([
                    'choices' => [[
                        'message' => [
                            'content' => '{"subject":"Files still needed for the new shoot","body":"Hi Jamie,\n\nWe still need the property files before the new shoot can be scheduled.\n\nPlease send them when you can."}',
                        ],
                    ]],
                ]);
        });

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/messaging/email/assist', [
            'mode' => 'write',
            'instruction' => 'suggest an email about missing files in new shoot',
            'subject' => '',
            'body' => '',
            'variables' => ['client_name' => 'Jamie'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('subject', 'Files still needed for the new shoot');
        $this->assertStringContainsString('property files', $response->json('body'));
        $this->assertStringNotContainsString('suggest an email', $response->json('body'));
    }

    public function test_write_requires_an_instruction(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/messaging/email/assist', [
            'mode' => 'write',
            'instruction' => '   ',
        ])->assertStatus(422);
    }
}
