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
                'category' => 'PAYMENT',
                'scope' => 'SYSTEM',
                'email_type' => 'OFFLINE_PAYMENT_INTENT_SUBMITTED',
                'override_enabled' => true,
                'subject' => 'Draft Subject',
                'body_html' => '<p>{{greeting}}</p><p>Draft body</p>',
                'body_text' => 'Draft body',
            ],
            'variables' => ['payment_method_label' => 'Cheque'],
        ]);

        $response->assertOk()->assertJson(['status' => 'sent']);

        $this->assertIsArray($capturedPayload);
        $this->assertSame('preview@example.com', $capturedPayload['to']);
        $this->assertSame('Draft Subject', $capturedPayload['subject']);
        $this->assertStringContainsString('Draft body', $capturedPayload['body_html']);
        $this->assertStringNotContainsString('Original body', $capturedPayload['body_html']);
        $this->assertStringContainsString('offline_payment_intent_submitted__cheque.png', $capturedPayload['body_html']);
        $this->assertSame('Draft body', $capturedPayload['body_text']);
        $this->assertSame('Original Subject', $template->fresh()->subject);
        $this->assertNull($template->fresh()->email_type);
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

    public function test_unsaved_template_preview_renders_without_persisting_or_sending(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $beforeCount = MessageTemplate::count();
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendEmail'));

        $response = $this->postJson('/api/messaging/templates/preview', [
            'template' => [
                'channel' => 'EMAIL',
                'name' => 'Unsaved preview',
                'scope' => 'USER',
                'category' => 'GENERAL',
                'subject' => 'My draft subject',
                'body_html' => '<p>My unsaved details.</p>',
                'body_text' => 'My unsaved details.',
            ],
            'theme' => 'dark',
        ]);

        $response->assertOk()->assertJsonPath('subject', 'My draft subject');
        $this->assertStringContainsString('My unsaved details.', $response->json('html'));
        $this->assertSame('My unsaved details.', $response->json('text'));
        $this->assertSame($beforeCount, MessageTemplate::count());
    }

    public function test_saved_preview_uses_complete_draft_metadata_and_does_not_change_saved_template(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $template = $this->makeEditableTemplate($user, [
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => false,
        ]);
        $before = $template->fresh()->getRawOriginal();
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendEmail'));

        $response = $this->postJson("/api/messaging/templates/{$template->id}/preview", [
            'template' => [
                'name' => 'Draft cheque review',
                'category' => 'PAYMENT',
                'scope' => 'SYSTEM',
                'email_type' => 'OFFLINE_PAYMENT_INTENT_SUBMITTED',
                'override_enabled' => true,
                'subject' => 'Review {{payment_method_label}}',
                'body_html' => '<p>Draft payment: [payment_method_label].</p>',
                'body_text' => 'Draft payment: [payment_method_label].',
                'variables_json' => ['payment_method_label'],
            ],
            'variables' => ['payment_method_label' => 'Cheque'],
            'theme' => 'light',
        ]);

        $response->assertOk()->assertJsonPath('subject', 'Review Cheque');
        $this->assertStringContainsString('Draft payment: Cheque.', $response->json('html'));
        $this->assertStringContainsString('offline_payment_intent_submitted__cheque.png', $response->json('html'));
        $this->assertSame($before, $template->fresh()->getRawOriginal());
    }

    public function test_template_reads_expose_editable_body_without_rewriting_stored_document(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $document = '<!doctype html><html><body><header>Old masthead</header>'
            .'<div data-email-content="true"><p>My authored body.</p></div><footer>Old footer</footer></body></html>';
        $template = $this->makeEditableTemplate($user, ['body_html' => $document]);

        $this->getJson("/api/messaging/templates/{$template->id}")
            ->assertOk()
            ->assertJsonPath('body_html', $document)
            ->assertJsonPath('editable_body_html', '<p>My authored body.</p>');
        $response = $this->getJson('/api/messaging/templates')->assertOk();
        $listed = collect($response->json())->firstWhere('id', $template->id);
        $this->assertSame('<p>My authored body.</p>', $listed['editable_body_html']);
        $this->assertSame($document, $template->fresh()->body_html);
    }

    public function test_explicit_editor_save_stores_the_authored_fragment_without_nested_chrome(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $template = $this->makeEditableTemplate($user);
        $body = '<p>Saved details.</p><a href="https://example.com/my-route">Saved action</a>';

        $this->putJson("/api/messaging/templates/{$template->id}", [
            'channel' => 'EMAIL',
            'name' => 'My saved edit',
            'scope' => 'USER',
            'subject' => 'My saved subject',
            'body_html' => '<html><body><div data-email-content="true">'.$body.'</div><footer>Old footer</footer></body></html>',
            'body_text' => 'Saved details.',
        ])->assertOk()->assertJsonPath('editable_body_html', $body);

        $this->assertSame($body, $template->fresh()->body_html);
        $this->assertSame('My saved subject', $template->fresh()->subject);
    }

    public function test_draft_preview_validates_theme_and_routing_metadata(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/messaging/templates/preview', [
            'template' => [
                'channel' => 'EMAIL',
                'name' => 'Draft',
                'email_type' => 'NOT_REGISTERED',
            ],
            'theme' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['theme', 'template.email_type']);
    }

    public function test_direct_report_preview_supplies_labeled_examples_without_persisting_or_sending(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $template = MessageTemplate::where('slug', 'payout-report')->firstOrFail();
        $before = $template->getRawOriginal();
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendEmail'));

        $response = $this->postJson("/api/messaging/templates/{$template->id}/preview", ['theme' => 'dark']);

        $response->assertOk()->assertJsonPath('subject', 'Payout report — preview example');
        $this->assertStringContainsString('Preview example', $response->json('html'));
        $this->assertStringContainsString('Live recipient details are inserted here when this email is sent.', $response->json('html'));
        $this->assertStringContainsString('Jamie Example', $response->json('html'));
        $this->assertStringNotContainsString('{{payout_report_html}}', $response->json('html'));
        $this->assertSame($before, $template->fresh()->getRawOriginal());
    }

    public function test_protected_runtime_block_preview_does_not_enable_or_save_the_override(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $template = MessageTemplate::where('email_type', 'ROLE_CHANGED')->firstOrFail();
        $before = $template->getRawOriginal();

        $response = $this->postJson("/api/messaging/templates/{$template->id}/preview", ['theme' => 'light']);

        $response->assertOk()->assertJsonPath('subject', 'Role Changed');
        $this->assertStringContainsString('Preview example', $response->json('html'));
        $this->assertStringContainsString('Live role changed details', $response->json('text'));
        $this->assertStringNotContainsString('{{system_body_html}}', $response->json('html'));
        $this->assertSame($before, $template->fresh()->getRawOriginal());
        $this->assertFalse($template->fresh()->override_enabled);
    }

    public function test_new_draft_infers_known_preview_values_from_shortcodes_without_a_declared_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $draft = ['channel' => 'EMAIL', 'name' => 'Untitled email', 'subject' => 'Hello {{client_first_name}}',
            'body_html' => '<p>Hi {{ client_first_name }}.</p><p>{{unsupported_contact}}</p>',
            'body_text' => 'Client: {{client_first_name}}'];

        $response = $this->postJson('/api/messaging/templates/preview', ['template' => $draft]);
        $response->assertOk()->assertJsonPath('subject', 'Hello Jamie')->assertJsonPath('text', 'Client: Jamie');
        $this->assertContains('unsupported_contact', $response->json('missing'));
        $this->assertNotContains('client_first_name', $response->json('missing'));

        $this->postJson('/api/messaging/templates/preview', [
            'template' => $draft, 'variables' => ['client_first_name' => 'Actual provided name'],
        ])->assertOk()->assertJsonPath('subject', 'Hello Actual provided name');
    }

    private function makeEditableTemplate(User $user, array $attributes = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'channel' => 'EMAIL',
            'name' => 'Saved template',
            'category' => 'GENERAL',
            'subject' => 'Saved subject',
            'body_html' => '<p>Saved body</p>',
            'body_text' => 'Saved body',
            'scope' => 'USER',
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_system' => false,
            'is_active' => true,
        ], $attributes));
    }
}
