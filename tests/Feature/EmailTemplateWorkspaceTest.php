<?php

namespace Tests\Feature;

use App\Jobs\GenerateEmailTemplateArtwork;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\Messaging\TemplateRenderer;
use App\Services\ReproAi\LlmClient;
use App\Services\Studio\Providers\OpenAiImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailTemplateWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function section(string $id = 'intro', string $body = 'Useful tips for your next shoot.'): array
    {
        return ['id' => $id, 'type' => 'text', 'heading' => 'Field notes', 'body' => $body, 'image_url' => '', 'alt' => '', 'url' => '', 'alignment' => 'left', 'spacing' => 24];
    }

    private function draft(): array
    {
        return ['name' => 'Photographer tips', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'A few useful tips',
            'body_html' => '', 'body_text' => '', 'editor_document_json' => ['version' => 1, 'format' => 'sections',
                'preheader' => 'A little preparation goes a long way.', 'sections' => [$this->section()]]];
    }

    public function test_sections_save_reopen_and_preview_without_booking_data(): void
    {
        $this->login();
        $draft = $this->draft();
        $draft['editor_document_json']['sections'][0]['body'] = 'Bring <strong>your</strong> batteries.';
        $saved = $this->postJson('/api/messaging/templates', $draft)->assertCreated();
        $id = $saved->json('id');
        $saved->assertJsonPath('editor_document_json.sections.0.body', 'Bring <strong>your</strong> batteries.');
        $this->assertStringContainsString('&lt;strong&gt;your&lt;/strong&gt;', $saved->json('body_html'));
        $this->getJson('/api/messaging/templates/'.$id)->assertOk()->assertJsonPath('editor_document_json.format', 'sections');
        $preview = $this->postJson('/api/messaging/templates/'.$id.'/preview')->assertOk();
        $this->assertStringContainsString('data-email-design="atelier-v6"', $preview->json('html'));
        $this->assertStringContainsString('A little preparation goes a long way.', $preview->json('html'));
        $this->assertStringContainsString('data-email-section="intro"', $preview->json('source_html'));
        $this->assertStringNotContainsString('123 Main', $preview->json('html'));
    }

    public function test_complete_html_is_preserved_through_save_preview_and_duplicate(): void
    {
        $this->login();
        $html = '<!doctype html><html><head><style>.offer{color:#ab1234}</style></head><body><!--[if mso]><table><![endif]--><table class="offer"><tr><td>Hello {{client_name}}</td></tr></table><!--[if mso]></table><![endif]--></body></html>';
        $draft = array_replace($this->draft(), ['body_html' => $html, 'body_text' => 'Hello {{client_name}}',
            'editor_document_json' => ['version' => 1, 'format' => 'html', 'preheader' => '', 'sections' => []]]);
        $saved = $this->postJson('/api/messaging/templates', $draft)->assertCreated();
        $this->assertSame($html, $saved->json('body_html'));
        $this->assertSame($html, $saved->json('editable_body_html'));
        $template = MessageTemplate::findOrFail($saved->json('id'));
        $rendered = app(TemplateRenderer::class)->render($template, ['client_name' => 'Morgan']);
        $this->assertSame(str_replace('{{client_name}}', 'Morgan', $html), $rendered['html']);
        $this->postJson('/api/messaging/templates/'.$template->id.'/duplicate')->assertCreated()->assertJsonPath('body_html', $html)->assertJsonPath('editor_document_json.format', 'html');
    }

    public function test_preview_compiles_unsaved_sections_without_persisting_them(): void
    {
        $this->login();
        $count = MessageTemplate::count();
        $this->postJson('/api/messaging/templates/preview', ['template' => $this->draft()])->assertOk()->assertJsonPath('subject', 'A few useful tips');
        $this->assertSame($count, MessageTemplate::count());
    }

    public function test_robbie_layout_keeps_editable_cards_and_personalizes_each_delivery(): void
    {
        $this->login();
        $draft = $this->draft();
        $draft['subject'] = '';
        $draft['editor_document_json'] = ['version' => 2, 'format' => 'sections', 'sections' => []];
        $video = 'https://vimeo.com/1243847479';
        $proposal = [
            'subject' => 'A Thanksgiving note',
            'preheader' => 'Thank you for a wonderful year.',
            'sections' => [
                ['id' => 'title', 'type' => 'hero', 'heading' => 'Happy Thanksgiving!', 'body' => 'A note of gratitude', 'styles' => ['background' => '#F8F3EB', 'color' => '#17324C', 'padding' => 24]],
                ['id' => 'letter', 'type' => 'text', 'body' => "Hi [First Name],\n\nThank you for a wonderful year."],
                ['id' => 'video', 'type' => 'video', 'video_url' => $video, 'image_url' => 'https://example.test/poster.jpg'],
                ['id' => 'cards', 'type' => 'columns', 'columns' => [
                    [['id' => 'dashboard', 'type' => 'text', 'heading' => 'Client dashboard', 'body' => 'See your orders.']],
                    [['id' => 'studio', 'type' => 'text', 'heading' => 'Listing Studio', 'body' => 'Polish a listing.']],
                ]],
                ['id' => 'signature', 'type' => 'signature', 'body' => 'The R/E Pro Photos team'],
            ],
        ];
        $this->mock(LlmClient::class)->shouldReceive('chatCompletion')->once()->withArgs(function (array $messages): bool {
            $context = json_decode($messages[1]['content'], true);

            return $context['new_custom_email']
                && $context['design_brief']['purpose'] === 'gratitude'
                && str_contains($messages[0]['content'], 'Hi {{recipient_first_name}},');
        })->andReturn(['choices' => [['message' => ['content' => json_encode($proposal)]]]]);

        $suggestion = $this->postJson('/api/messaging/templates/assist', [
            'draft' => $draft, 'instruction' => 'Create an editable Thanksgiving letter.', 'scope' => 'email',
            'video_urls' => [$video],
        ])->assertOk()->json('draft');
        $this->assertSame('A note of gratitude', $suggestion['editor_document_json']['sections'][0]['body']);
        $this->assertSame("Hi {{recipient_first_name}},\n\nThank you for a wonderful year.", $suggestion['editor_document_json']['sections'][1]['body']);
        $saved = $this->postJson('/api/messaging/templates', $suggestion)->assertCreated()
            ->assertJsonPath('editor_document_json.sections.3.columns.1.0.heading', 'Listing Studio')
            ->assertJsonPath('editor_document_json.sections.2.video_url', $video)->json();

        $preview = $this->postJson('/api/messaging/templates/'.$saved['id'].'/preview')->assertOk()->json();
        $this->assertSame(1, substr_count($preview['html'], 'Hi Jamie,'));
        $this->assertStringContainsString('ews-email-column', $preview['html']);
        $this->assertStringContainsString('#F8F3EB', $preview['html']);

        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Fixture email',
            'from_email' => 'team@example.test', 'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
        $payloads = [];
        $this->mock(LocalSmtpProvider::class)->shouldReceive('send')->twice()
            ->andReturnUsing(function (MessageChannel $channel, array $payload) use (&$payloads): string {
                $payloads[$payload['to']] = $payload;

                return 'fixture-email-'.count($payloads);
            });
        $alex = User::factory()->create(['role' => 'photographer', 'name' => 'Alex Morgan']);
        $priya = User::factory()->create(['role' => 'photographer', 'name' => 'Priya Shah']);
        $this->postJson('/api/messaging/email/compose', [
            'template_id' => $saved['id'], 'to' => [$alex->email, $priya->email],
            'subject' => $saved['subject'], 'body_html' => $saved['body_html'], 'body_text' => $saved['body_text'],
        ])->assertOk()->assertJsonPath('sent', 2);
        foreach ([$alex->email => 'Alex', $priya->email => 'Priya'] as $email => $firstName) {
            $this->assertStringContainsString('Hi '.$firstName.',', $payloads[$email]['html']);
            $this->assertStringContainsString('Hi '.$firstName.',', $payloads[$email]['text']);
            $this->assertStringContainsString('Listing Studio', $payloads[$email]['html']);
            $this->assertStringNotContainsString('[First Name]', $payloads[$email]['html']);
        }
    }

    public function test_section_links_and_document_shape_are_validated(): void
    {
        $this->login();
        $draft = $this->draft();
        $draft['editor_document_json']['sections'][0]['type'] = 'button';
        $draft['editor_document_json']['sections'][0]['url'] = 'javascript:alert(1)';
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
        $draft['editor_document_json']['sections'][0]['url'] = '{{portal_url}}';
        $draft['editor_document_json']['sections'][] = $draft['editor_document_json']['sections'][0];
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
    }

    public function test_subject_scoped_robbie_cannot_modify_the_email_body_or_save_it(): void
    {
        $this->login();
        $draft = $this->draft();
        $proposal = ['subject' => 'A better subject', 'preheader' => 'Better preview', 'sections' => [$this->section('intruder', 'Do not apply')], 'body_html' => 'Do not apply', 'body_text' => '', 'blocks' => []];
        $this->llm($proposal);
        $count = MessageTemplate::count();
        $response = $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Improve the subject', 'scope' => 'subject'])->assertOk();
        $response->assertJsonPath('draft.subject', 'A better subject')->assertJsonPath('draft.editor_document_json.sections.0.id', 'intro');
        $this->assertSame($count, MessageTemplate::count());
    }

    public function test_section_scoped_robbie_keeps_other_sections_and_subject(): void
    {
        $this->login();
        $draft = $this->draft();
        $draft['editor_document_json']['sections'][] = $this->section('closing', 'Keep this closing.');
        $this->llm(['subject' => 'Ignore this subject', 'preheader' => 'Ignore this preview', 'sections' => [$this->section('intro', 'Revised introduction.'), $this->section('closing', 'Wrong closing')], 'body_html' => '', 'body_text' => '', 'blocks' => []]);
        $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Shorten the intro', 'scope' => 'section', 'section_id' => 'intro'])->assertOk()
            ->assertJsonPath('draft.subject', $draft['subject'])->assertJsonPath('draft.editor_document_json.sections.0.body', 'Revised introduction.')
            ->assertJsonPath('draft.editor_document_json.sections.1.body', 'Keep this closing.');
    }

    public function test_ai_cannot_remove_connected_fields(): void
    {
        $this->login();
        $draft = $this->draft();
        $draft['editor_document_json']['sections'][0]['body'] = 'Hi {{client_name}}, welcome.';
        $this->llm(['subject' => 'Welcome', 'preheader' => '', 'sections' => [$this->section('intro', 'Hi stranger.')], 'body_html' => '', 'body_text' => '', 'blocks' => []]);
        $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Shorten the email', 'scope' => 'email'])->assertUnprocessable();
    }

    public function test_existing_system_runtime_field_cannot_be_replaced_with_sections_or_removed_from_html(): void
    {
        $this->login();
        $template = MessageTemplate::where('slug', 'account-created')->firstOrFail();
        $template->forceFill(['body_html' => '{{system_body_html}}', 'body_text' => '{{system_body_text}}', 'email_type' => 'ACCOUNT_CREATED'])->save();
        $this->assertNotEmpty(app(\App\Services\SystemEmails\EditableEmailContent::class)->blocks($template));
        $this->putJson('/api/messaging/templates/'.$template->id, $this->draft())->assertUnprocessable();
        $draft = $this->draft();
        $draft['editor_document_json']['format'] = 'legacy';
        $draft['body_html'] = '<p>Disconnected message.</p>';
        $this->putJson('/api/messaging/templates/'.$template->id, $draft)->assertUnprocessable();
    }

    public function test_artwork_request_is_idempotent_and_only_its_owner_can_poll(): void
    {
        $owner = $this->login(); Queue::fake(); config(['services.openai.api_key' => 'test-only']);
        $request = ['id' => (string) Str::uuid(), 'kind' => 'icon', 'prompt' => 'A camera lens'];
        $this->postJson('/api/messaging/templates/artwork', $request)->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->postJson('/api/messaging/templates/artwork', $request)->assertStatus(202);
        Queue::assertPushed(GenerateEmailTemplateArtwork::class, 1);
        $this->assertDatabaseHas('email_template_artworks', ['id' => $request['id'], 'user_id' => $owner->id]);
        $this->login();
        $this->getJson('/api/messaging/templates/artwork/'.$request['id'])->assertNotFound();
    }

    public function test_artwork_job_saves_permanent_asset_and_never_repeats_a_paid_call(): void
    {
        $owner = $this->login(); Storage::fake('public');
        $id = (string) Str::uuid();
        DB::table('email_template_artworks')->insert(['id' => $id, 'user_id' => $owner->id, 'kind' => 'illustration', 'prompt' => 'Camera equipment', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $provider = $this->mock(OpenAiImageProvider::class);
        $image = \Intervention\Image\ImageManager::gd()->create(1536, 1024);
        $image->place(\Intervention\Image\ImageManager::gd()->create(512, 512)->fill('195fe6'), 'center');
        $provider->shouldReceive('artwork')->once()->withArgs(fn ($bytes, $prompt, $kind, $options) => strlen($bytes) > 100 && str_contains($prompt, 'navy and cobalt blue') && str_contains($prompt, 'Camera equipment') && $kind === 'illustration' && $options['model'] === 'gpt-image-2.5-sunburst')->andReturn((string) $image->toPng());
        $job = new GenerateEmailTemplateArtwork($id);
        $job->handle($provider); $job->handle($provider);
        Storage::disk('public')->assertExists('email-template-artwork/'.$id.'.png');
        $this->getJson('/api/messaging/templates/artwork/'.$id)->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_client_photographer_and_editing_manager_cannot_use_template_ai_or_artwork(): void
    {
        Queue::fake();
        foreach (['client', 'photographer', 'editing_manager'] as $role) {
            $this->login($role);
            $this->postJson('/api/messaging/templates/assist', ['instruction' => 'Write an email'])->assertForbidden();
            $this->postJson('/api/messaging/templates/artwork', ['id' => (string) Str::uuid(), 'prompt' => 'Camera', 'kind' => 'icon'])->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    public function test_robbie_edits_existing_connected_copy_without_replacing_runtime_wrapper(): void
    {
        $this->login();
        $template = MessageTemplate::where('slug', 'account-created')->firstOrFail();
        $template->forceFill(['body_html' => '{{system_body_html}}', 'body_text' => '{{system_body_text}}', 'email_type' => 'ACCOUNT_CREATED'])->save();
        $block = app(\App\Services\SystemEmails\EditableEmailContent::class)->blocks($template)[0];
        $html = $block['body_html'].'<p>A warm welcome from the team.</p>';
        $this->llm(['subject' => 'Welcome', 'preheader' => '', 'sections' => [], 'body_html' => '<p>Must not replace wrapper.</p>', 'body_text' => '', 'blocks' => [['id' => $block['key'], 'body_html' => $html, 'body_text' => $block['body_text']]]]);
        $draft = ['name' => $template->name, 'subject' => $template->subject, 'body_html' => $template->body_html,
            'body_text' => $template->body_text, 'editor_document_json' => ['version' => 1, 'format' => 'legacy', 'preheader' => '', 'sections' => []]];
        $response = $this->postJson('/api/messaging/templates/assist', ['template_id' => $template->id, 'draft' => $draft,
            'instruction' => 'Add a warm welcome.', 'scope' => 'section', 'section_id' => $block['key']])->assertOk();
        $response->assertJsonPath('draft.body_html', '{{system_body_html}}');
        $this->assertSame($html, $response->json('draft.content_blocks_json')[$block['key']]['body_html']);
        $this->assertSame('{{system_body_html}}', $template->fresh()->body_html);
    }

    private function llm(array $proposal): void
    {
        $this->mock(LlmClient::class)->shouldReceive('chatCompletion')->once()->andReturn(['choices' => [['message' => ['content' => json_encode($proposal)]]]]);
    }
}
