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
        $this->assertStringStartsWith("Hi {{recipient_first_name}},\n\n", $response->json('body'));
        $this->assertStringNotContainsString('Hi Jamie', $response->json('body'));
    }

    public function test_from_shoot_inserts_a_recipient_field_without_exposing_an_example_name(): void
    {
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletion')->once()->andReturnUsing(function ($messages) {
                $this->assertStringContainsString('Hi {{recipient_first_name}}', $messages[0]['content']);
                $this->assertStringContainsString('Never invent a name', $messages[0]['content']);

                return ['choices' => [['message' => ['content' => json_encode([
                    'subject' => 'Your shoot update', 'body' => "Your shoot is ready.\n\nThank you!",
                ])]]]];
            });
        });
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/messaging/email/assist', ['mode' => 'from_shoot'])
            ->assertOk()->assertJsonPath('body', "Hi {{recipient_first_name}},\n\nYour shoot is ready.\n\nThank you!");
    }

    public function test_rewriting_preserves_the_existing_greeting_instead_of_inserting_a_second_name(): void
    {
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletion')->once()->andReturn([
                'choices' => [['message' => ['content' => json_encode([
                    'subject' => 'A warmer note', 'body' => "Hello Example,\n\nThank you for your wonderful work.",
                ])]]],
            ]);
        });
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/messaging/email/assist', [
            'mode' => 'warmer', 'subject' => 'Thanks', 'body' => "Dear {{recipient_first_name}},\n\nThank you.",
        ])->assertOk()->assertJsonPath('body', "Dear {{recipient_first_name}},\n\nThank you for your wonderful work.");
    }

    public function test_new_draft_can_explicitly_omit_the_greeting(): void
    {
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletion')->once()->andReturn([
                'choices' => [['message' => ['content' => json_encode([
                    'subject' => 'A quick update', 'body' => "Hi Jamie,\n\nThe office will reopen Monday.",
                ])]]],
            ]);
        });
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/messaging/email/assist', [
            'mode' => 'write', 'instruction' => 'Write an office closure update without a greeting.',
        ])->assertOk()->assertJsonPath('body', 'The office will reopen Monday.');
    }

    public function test_subject_mode_keeps_the_original_body_even_if_the_model_rewrites_it(): void
    {
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletion')->once()->andReturn([
                'choices' => [['message' => ['content' => json_encode([
                    'subject' => 'A better subject', 'body' => "Hi Example,\n\nRewritten content.",
                ])]]],
            ]);
        });
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $body = "Dear {{recipient_first_name}},\n\nKeep this exact message.";

        $this->postJson('/api/messaging/email/assist', [
            'mode' => 'subject', 'subject' => 'Old subject', 'body' => $body,
        ])->assertOk()->assertJsonPath('subject', 'A better subject')->assertJsonPath('body', $body);
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
