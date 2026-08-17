<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class MessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_send_uses_editor_draft_overrides_when_provided(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $template = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Original Template',
            'description' => 'Original description',
            'category' => 'GENERAL',
            'subject' => 'Original Subject',
            'body_html' => '<p>Original body</p>',
            'body_text' => 'Original body',
            'scope' => 'USER',
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_system' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $capturedPayload = null;

        $this->mock(MessagingService::class, function (MockInterface $mock) use (&$capturedPayload): void {
            $mock->shouldReceive('sendEmail')
                ->once()
                ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                    $capturedPayload = $payload;

                    return true;
                })
                ->andReturn(Message::make([
                    'channel' => 'EMAIL',
                    'to_address' => 'preview@example.com',
                    'status' => 'queued',
                ]));
        });

        $response = $this->postJson("/api/messaging/templates/{$template->id}/test-send", [
            'to' => 'preview@example.com',
            'template' => [
                'name' => 'Draft Template',
                'category' => 'ACCOUNT',
                'subject' => 'Draft Subject',
                'body_html' => '<p>{{greeting}}</p><p>Draft body</p>',
                'body_text' => 'Draft body',
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'sent']);

        $this->assertIsArray($capturedPayload);
        $this->assertSame('preview@example.com', $capturedPayload['to']);
        $this->assertSame('Draft Subject', $capturedPayload['subject']);
        $this->assertStringContainsString('Draft body', $capturedPayload['body_html']);
        $this->assertStringNotContainsString('Original body', $capturedPayload['body_html']);
        $this->assertSame('Draft body', $capturedPayload['body_text']);
    }

    public function test_enabling_override_atomically_disables_previous_template_for_alias(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $first = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'First Account Override',
            'slug' => 'first-account-override',
            'category' => 'ACCOUNT',
            'subject' => 'First subject',
            'body_html' => '<p>First body</p>',
            'body_text' => 'First body',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => true,
        ]);
        $second = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Second Account Override',
            'slug' => 'second-account-override',
            'category' => 'ACCOUNT',
            'subject' => 'Second subject',
            'body_html' => '<p>Second body</p>',
            'body_text' => 'Second body',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => null,
            'override_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/messaging/templates/{$second->id}", [
            'channel' => 'EMAIL',
            'name' => $second->name,
            'category' => 'ACCOUNT',
            'subject' => 'Second edited subject',
            'body_html' => '<p>Second edited body</p>',
            'body_text' => 'Second edited body',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', $second->id)
            ->assertJsonPath('email_type', 'ACCOUNT_CREATED')
            ->assertJsonPath('override_enabled', true);

        $this->assertFalse($first->fresh()->override_enabled);
        $this->assertTrue($second->fresh()->override_enabled);
        $this->assertSame($user->id, $second->fresh()->updated_by);
    }

    public function test_override_email_type_must_be_a_registered_protected_alias(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->postJson('/api/messaging/templates', [
            'channel' => 'EMAIL',
            'name' => 'Invalid Override',
            'category' => 'GENERAL',
            'subject' => 'Invalid subject',
            'body_html' => '<p>Invalid body</p>',
            'body_text' => 'Invalid body',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'NOT_A_REAL_EMAIL_TYPE',
            'override_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('email_type');
    }
}
