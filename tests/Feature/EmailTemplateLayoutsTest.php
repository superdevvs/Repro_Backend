<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\EmailTemplateDocument;
use App\Services\Messaging\EmailTemplateSectionRenderer;
use App\Services\ReproAi\LlmClient;
use App\Services\ReproAi\SupportKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailTemplateLayoutsTest extends TestCase
{
    use RefreshDatabase;

    private function section(array $overrides = []): array
    {
        return array_replace(['id' => 'guide', 'type' => 'text', 'heading' => 'Review your invoice', 'body' => 'Open **Earnings**, then choose *View invoice*.', 'image_url' => '', 'alt' => '', 'url' => '', 'alignment' => 'left', 'spacing' => 32], $overrides);
    }

    public function test_editorial_layout_roundtrips_and_renders_real_side_by_side_images(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $sections = [
            $this->section(['id' => 'hero', 'type' => 'hero', 'eyebrow' => 'YOUR WEEKLY INVOICE']),
            $this->section(['id' => 'left', 'type' => 'feature', 'layout' => 'image-left', 'image_width' => 160, 'image_url' => 'https://example.com/invoice.png', 'heading' => 'Check each shoot']),
            $this->section(['id' => 'right', 'type' => 'feature', 'layout' => 'image-right', 'image_width' => 120, 'image_url' => 'https://example.com/submit.png', 'heading' => 'Submit to accounts']),
            $this->section(['id' => 'steps', 'type' => 'steps', 'tone' => 'soft', 'body' => "Open Earnings.\nChoose **Confirm invoice is correct**.\nWait for accounts review."]),
            $this->section(['id' => 'details', 'type' => 'details', 'tone' => 'accent', 'body' => "Review period | Sunday–Saturday\nSubmission | Accounts review"]),
        ];
        $document = ['version' => 1, 'format' => 'sections', 'preheader' => 'Review, correct, and submit.', 'sections' => $sections];
        $saved = $this->postJson('/api/messaging/templates', ['name' => 'Invoice guide', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'Photographer invoice guide', 'editor_document_json' => $document])->assertCreated();
        $saved->assertJsonPath('editor_document_json.sections.1.layout', 'image-left');
        $rendered = $this->postJson('/api/messaging/templates/'.$saved->json('id').'/preview')->assertOk()->json('html');
        $this->assertStringContainsString('data-email-layout="image-left"', $rendered);
        $this->assertStringContainsString('ws-feature-image', $rendered);
        $this->assertLessThan(strpos($rendered, 'Check each shoot'), strpos($rendered, 'invoice.png'));
        $this->assertLessThan(strpos($rendered, 'submit.png'), strpos($rendered, 'Submit to accounts'));
        $this->assertStringContainsString('Sunday–Saturday', $rendered);
        $this->assertStringContainsString('<strong>Earnings</strong>', $rendered);
        $this->assertStringContainsString('<em>View invoice</em>', $rendered);
        $this->assertStringNotContainsString('Everything you need is organized below', $rendered);
        $this->assertStringContainsString('YOUR WEEKLY INVOICE', $rendered);
        $this->getJson('/api/messaging/templates/'.$saved->json('id'))->assertOk()->assertJsonPath('editor_document_json.sections.4.tone', 'accent');
        $this->postJson('/api/messaging/templates/'.$saved->json('id').'/preview', ['theme' => 'dark'])->assertOk();
    }

    public function test_inline_formatting_cannot_inject_html_or_active_links(): void
    {
        $html = app(EmailTemplateDocument::class)->html([$this->section(['body' => '<script>bad()</script> **Safe** [No](javascript:alert) [Guide](https://reprodashboard.com/accounting)'])]);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>Safe</strong>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="https://reprodashboard.com/accounting"', $html);
    }

    public function test_robbie_receives_the_real_photographer_workflow_and_returns_bounded_artwork_suggestions(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $sections = [];
        for ($i = 0; $i < 4; $i++) $sections[] = $this->section(['id' => 'art-'.$i, 'type' => 'feature', 'layout' => 'image-left', 'image_width' => 160, 'tone' => 'plain', 'image_prompt' => 'A blue glass invoice with a check mark', 'artwork_key' => 'invoice']);
        $sections[] = $this->section(['id' => 'header', 'type' => 'hero', 'image_prompt' => 'Wide blue glass invoice workflow scene', 'artwork_key' => 'invoice']);
        $proposal = ['subject' => 'Review and submit your photographer invoice', 'preheader' => 'A guide to Earnings.', 'sections' => $sections, 'body_html' => '', 'body_text' => '', 'blocks' => []];
        $this->mock(LlmClient::class, function ($mock) use ($proposal) {
            $mock->shouldReceive('chatCompletion')->once()->withArgs(function ($messages, $tools, $stream, $options) {
                $context = json_decode($messages[1]['content'], true);
                $article = collect($context['dashboard_guides'])->firstWhere('id', 'photographer-invoice-review');
                $this->assertNotNull($article);
                $this->assertStringContainsString('Confirm invoice is correct', implode(' ', $article['steps']));
                $this->assertStringContainsString('Submit changes', implode(' ', $article['steps']));
                $this->assertStringContainsString('pending_approval', implode(' ', $article['steps']));
                $this->assertSame('gpt-6-astra', $options['model']);
                $this->assertStringContainsString('wide header image', $messages[0]['content']);
                $this->assertFalse($stream);
                return true;
            })->andReturn(['choices' => [['message' => ['content' => json_encode($proposal)]]]]);
        });
        $draft = ['name' => 'Invoice guide', 'channel' => 'EMAIL', 'subject' => 'Invoices', 'editor_document_json' => ['version' => 1, 'format' => 'sections', 'preheader' => '', 'sections' => [$this->section()]]];
        $response = $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'scope' => 'email', 'instruction' => 'Create an email about how photographers should review and submit their invoices'])->assertOk();
        $result = $response->json('draft.editor_document_json.sections');
        $this->assertCount(3, array_filter(array_column($result, 'artwork_id')));
        $this->assertNotEmpty($result[4]['artwork_id'], 'Reserve a generation slot for the header even when it follows other image sections.');
        $this->assertStringContainsString('/images/email-atelier/v6/invoice.png', $result[0]['image_url']);
        $response->assertJsonPath('context.model', 'gpt-6-astra');
        $this->assertContains('photographer-invoice-review', array_column($response->json('context.sources'), 'id'));
        Queue::assertNothingPushed();
    }

    public function test_photographer_workflow_is_not_exposed_as_a_client_permission(): void
    {
        $knowledge = app(SupportKnowledgeBase::class);
        $this->assertNull($knowledge->find('photographer-invoice-review', User::factory()->make(['role' => 'client'])));
        $this->assertNotNull($knowledge->find('photographer-invoice-review', User::factory()->make(['role' => 'photographer'])));
    }
    public function test_latest_text_model_uses_supported_reasoning_parameters(): void
    {
        config(['services.openai.api_key' => 'fixture-key']);
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode(['model' => 'gpt-6-astra', 'choices' => [['message' => ['content' => '{"subject":"Guide"}']]]])),
        ]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $client = app(LlmClient::class);
        $property = new \ReflectionProperty($client, 'client');
        $property->setValue($client, new \GuzzleHttp\Client(['handler' => $handler]));
        $client->chatCompletion([['role' => 'user', 'content' => 'Draft a guide']], [], false, ['model' => 'gpt-6-astra', 'max_tokens' => 14000, 'reasoning_effort' => 'medium']);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('gpt-6-astra', $payload['model']);
        $this->assertSame('medium', $payload['reasoning_effort']);
        $this->assertSame(14000, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function test_artwork_job_rejects_non_png_bytes_instead_of_saving_a_mislabelled_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $id = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('email_template_artworks')->insert(['id' => $id, 'user_id' => User::factory()->create()->id, 'kind' => 'icon', 'prompt' => 'Invoice', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $provider = $this->mock(\App\Services\Studio\Providers\OpenAiImageProvider::class);
        $provider->shouldReceive('artwork')->once()->withArgs(function ($reference, $prompt, $kind, $options) {
            $this->assertNotFalse(getimagesizefromstring($reference));
            return $kind === 'icon' && $options['model'] === 'gpt-image-2.5-sunburst';
        })->andReturn((string) \Intervention\Image\ImageManager::gd()->create(16, 16)->toJpeg());
        (new \App\Jobs\GenerateEmailTemplateArtwork($id))->handle($provider);
        $this->assertDatabaseHas('email_template_artworks', ['id' => $id, 'status' => 'failed', 'path' => null]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('email-template-artwork/'.$id.'.png');
    }


}
