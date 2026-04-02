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
}
