<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailWorkspaceHtmlMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_html_media_survives_save_and_resolves_without_rewriting_the_source(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'admin']); Sanctum::actingAs($owner);
        $id = (string) Str::uuid();
        DB::table('email_template_assets')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => 'Uploaded photo', 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $marker = '<span data-email-media-id="'.$id.'" data-email-media-alt="House &quot;&lt;view&gt;&quot;">Image loading…</span>';
        $html = '<!doctype html><html><head><style>.custom{color:red}</style></head><body><!-- keep this --><table><tr><td class="custom">Hello {{client_name}}</td></tr></table>'.$marker.'</body></html>';
        $payload = ['channel' => 'EMAIL', 'scope' => 'GLOBAL', 'name' => 'Imported HTML', 'subject' => 'Hello', 'body_html' => $html, 'editor_document_json' => ['version' => 2, 'format' => 'html', 'sections' => []]];
        $saved = $this->postJson('/api/messaging/templates', $payload)->assertCreated()->json();
        $template = MessageTemplate::findOrFail($saved['id']);
        $this->assertSame($html, $template->body_html);
        $preview = $this->postJson('/api/messaging/templates/'.$template->id.'/preview', ['template' => $payload])->assertOk()->json('html');
        $this->assertStringContainsString($marker, $preview);
        try { app(TemplateRenderer::class)->render($template, ['client_name' => 'Alex']); $this->fail('Pending HTML images must not send.'); }
        catch (ValidationException $expected) { $this->assertStringContainsString('processing', $expected->getMessage()); }
        $path = 'email-template-assets/'.$id.'.png'; Storage::disk('public')->put($path, 'fixture');
        DB::table('email_template_assets')->where('id', $id)->update(['status' => 'ready', 'path' => $path]);
        $render = app(TemplateRenderer::class)->render($template, ['client_name' => 'Alex'])['html'];
        $this->assertStringContainsString('<style>.custom{color:red}</style>', $render);
        $this->assertStringContainsString('<!-- keep this -->', $render);
        $this->assertStringContainsString('Hello Alex', $render);
        $this->assertStringContainsString($path, $render);
        $this->assertStringContainsString('alt="House &quot;&lt;view&gt;&quot;"', $render);
        $this->assertStringNotContainsString('data-email-media-id', $render);
        $this->assertSame($html, $template->fresh()->body_html);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/messaging/templates/assets/'.$id)->assertNotFound();
        $this->getJson('/api/messaging/templates/assets/'.$id.'?template_id='.$template->id)->assertOk()->assertJsonMissingPath('original_path');
    }

    public function test_automated_override_cannot_activate_a_pending_html_image(): void
    {
        $owner = User::factory()->create(['role' => 'admin']); Sanctum::actingAs($owner);
        $id = (string) Str::uuid();
        DB::table('email_template_assets')->insert(['id' => $id, 'user_id' => $owner->id, 'name' => 'Pending photo', 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['channel' => 'EMAIL', 'scope' => 'USER', 'name' => 'Pending active content', 'subject' => 'Hello', 'body_html' => '<span data-email-media-id="'.$id.'">Image loading</span>', 'is_system' => true, 'editor_document_json' => ['version' => 2, 'format' => 'html', 'sections' => []]];
        $this->postJson('/api/messaging/templates', $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('message_templates', ['name' => 'Pending active content']);
    }

    public function test_rich_headings_keep_their_hierarchy_without_custom_styles(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $section = ['id' => 'rich-heading', 'type' => 'text', 'body' => 'Your benefits', 'rich_text' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Your benefits']]]]]];
        $payload = ['channel' => 'EMAIL', 'name' => 'Rich headings', 'scope' => 'USER', 'subject' => 'Benefits', 'editor_document_json' => ['version' => 2, 'format' => 'sections', 'sections' => [$section]]];
        $html = $this->postJson('/api/messaging/templates/preview', ['template' => $payload])->assertOk()->json('html');
        $dom = new \DOMDocument; @$dom->loadHTML($html);
        $heading = (new \DOMXPath($dom))->query('//h3[contains(text(), "Your benefits")]')->item(0);
        $this->assertNotNull($heading);
        $this->assertStringContainsString('font-size:24px', str_replace(' ', '', $heading->getAttribute('style')));
    }
}
