<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailWorkspaceV2Test extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        config(['queue.default' => 'sync']);
        Storage::fake('local'); Storage::fake('public');
        $user = User::factory()->create(['role' => 'admin']); Sanctum::actingAs($user); return $user;
    }

    private function section(string $type = 'text'): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'heading' => 'Your next listing', 'body' => 'A useful message.', 'image_url' => '', 'alt' => '', 'url' => '', 'alignment' => 'left', 'spacing' => 24];
    }

    private function draft(): array
    {
        return ['name' => 'Sales email', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'Your next listing', 'body_html' => '', 'body_text' => '', 'editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [$this->section()]]];
    }

    public function test_upload_is_optimized_and_owner_scoped_without_exposing_original(): void
    {
        $this->login();
        $row = $this->post('/api/messaging/templates/assets', ['file' => UploadedFile::fake()->image('listing.jpg', 2000, 1400), 'slot' => 'content'])->assertStatus(202)->json();
        $this->assertSame('ready', $row['status']); $this->assertSame(1200, $row['width']); $this->assertSame('image/jpeg', $row['mime']);
        $this->assertArrayNotHasKey('original_path', $row);
        Storage::disk('public')->assertExists('email-template-assets/'.$row['id'].'.jpg');
        $this->deleteJson('/api/messaging/templates/assets/'.$row['id'])->assertOk();
        Storage::disk('public')->assertExists('email-template-assets/'.$row['id'].'.jpg');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/messaging/templates/assets/'.$row['id'])->assertNotFound();
    }

    public function test_uploaded_png_retains_alpha_and_crop_creates_new_asset(): void
    {
        $this->login();
        $image = imagecreatetruecolor(400, 200); imagealphablending($image, false); imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 255, 127)); imagefilledrectangle($image, 100, 50, 200, 150, imagecolorallocatealpha($image, 0, 20, 255, 0));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $row = $this->post('/api/messaging/templates/assets', ['file' => UploadedFile::fake()->createWithContent('icon.png', $bytes)])->assertStatus(202)->json();
        $this->assertSame('image/png', $row['mime']);
        $decoded = imagecreatefromstring(Storage::disk('public')->get('email-template-assets/'.$row['id'].'.png'));
        $this->assertSame(127, (imagecolorat($decoded, 0, 0) >> 24) & 127); imagedestroy($decoded);
        $crop = $this->postJson('/api/messaging/templates/assets/'.$row['id'].'/variants', ['crop' => '1:1', 'slot' => 'icon'])->assertStatus(202)->json();
        $this->assertNotSame($row['id'], $crop['id']); $this->assertSame($crop['width'], $crop['height']);
    }

    public function test_columns_rich_text_and_video_render_through_normal_preview(): void
    {
        $this->login(); $draft = $this->draft();
        $rich = $this->section(); $rich['rich_text'] = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Real benefit', 'marks' => [['type' => 'bold']]]]]]];
        $video = $this->section('video'); $video['video_url'] = 'https://www.youtube.com/watch?v=abcdefghijk'; $video['image_url'] = 'https://example.com/poster.png';
        $draft['editor_document_json']['sections'] = [array_merge($this->section('columns'), ['columns' => [[$rich], [$video]]])];
        $response = $this->postJson('/api/messaging/templates/preview', ['template' => $draft])->assertOk();
        $this->assertStringContainsString('<strong>Real benefit</strong>', $response->json('html'));
        $this->assertStringContainsString('Watch video', $response->json('html'));
        $this->assertStringContainsString('ews-email-column', $response->json('html'));
        $this->assertStringNotContainsString('<iframe', $response->json('html'));
    }

    public function test_nested_columns_and_unsafe_styles_are_rejected(): void
    {
        $this->login(); $draft = $this->draft(); $child = array_merge($this->section('columns'), ['columns' => [[$this->section()], [$this->section()]]]);
        $draft['editor_document_json']['sections'] = [array_merge($this->section('columns'), ['columns' => [[$child], [$this->section()]]])];
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
        $draft = $this->draft(); $draft['editor_document_json']['sections'][0]['styles'] = ['background' => 'url(javascript:alert(1))'];
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
    }

    public function test_draft_autosave_is_separate_from_templates_and_detects_conflicts(): void
    {
        $this->login(); $count = MessageTemplate::count(); $id = (string) Str::uuid(); $payload = ['base_revision' => 0, 'draft' => $this->draft()];
        $this->putJson('/api/messaging/templates/drafts/'.$id, $payload)->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame($count, MessageTemplate::count());
        $this->putJson('/api/messaging/templates/drafts/'.$id, $payload)->assertConflict();
        $payload['base_revision'] = 1;
        $this->putJson('/api/messaging/templates/drafts/'.$id, $payload)->assertOk()->assertJsonPath('revision', 2);
        $this->getJson('/api/messaging/templates/drafts')->assertOk()->assertJsonPath('id', $id);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/messaging/templates/drafts')->assertOk()->assertExactJson([]);
    }

    public function test_saved_revisions_and_template_concurrency_are_preserved(): void
    {
        $this->login(); $draft = $this->draft(); $saved = $this->postJson('/api/messaging/templates', $draft)->assertCreated()->json();
        $draft['workspace_revision'] = 0; $draft['subject'] = 'Edited offer';
        $this->putJson('/api/messaging/templates/'.$saved['id'], $draft)->assertOk()->assertJsonPath('workspace_revision', 1);
        $this->putJson('/api/messaging/templates/'.$saved['id'], $draft)->assertConflict();
        $this->getJson('/api/messaging/templates/'.$saved['id'].'/revisions')->assertOk()->assertJsonCount(2);
    }

    public function test_unknown_video_urls_are_not_fetched_and_require_custom_poster(): void
    {
        $this->login(); Http::preventStrayRequests();
        $this->postJson('/api/messaging/templates/video', ['url' => 'https://example.com/walkthrough.mp4'])->assertOk()->assertJsonPath('needs_thumbnail', true);
        $this->postJson('/api/messaging/templates/video', ['url' => 'http://127.0.0.1/private'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_robbie_revises_a_nested_card_without_replacing_its_sibling(): void
    {
        $this->login(); $draft = $this->draft(); $first = $this->section(); $second = $this->section(); $second['body'] = 'Keep this sibling.';
        $row = array_merge($this->section('columns'), ['columns' => [[$first], [$second]]]);
        $draft['editor_document_json']['sections'] = [$row];
        $proposal = $row; $proposal['columns'][0][0]['body'] = 'A stronger benefit.'; $proposal['columns'][1][0]['body'] = 'Do not replace this.';
        $this->mock(\App\Services\ReproAi\LlmClient::class)->shouldReceive('chatCompletion')->once()->andReturn(['choices' => [['message' => ['content' => json_encode(['subject' => 'Ignored subject', 'preheader' => '', 'sections' => [$proposal], 'blocks' => [], 'body_html' => '', 'body_text' => ''])]]]]);
        $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Improve this card', 'scope' => 'section', 'section_id' => $first['id']])->assertOk()
            ->assertJsonPath('draft.editor_document_json.sections.0.columns.0.0.body', 'A stronger benefit.')
            ->assertJsonPath('draft.editor_document_json.sections.0.columns.1.0.body', 'Keep this sibling.');
    }

    public function test_robbie_links_a_processing_video_poster_and_reports_missing_facts(): void
    {
        $user = $this->login(); $draft = $this->draft(); $id = (string) Str::uuid(); $url = 'https://www.youtube.com/watch?v=abcdefghijk';
        DB::table('email_template_assets')->insert(['id' => $id, 'user_id' => $user->id, 'name' => 'Video thumbnail', 'source' => 'video-thumbnail', 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $video = array_merge($this->section('video'), ['video_url' => $url]);
        $this->mock(\App\Services\ReproAi\LlmClient::class)->shouldReceive('chatCompletion')->once()->andReturn(['choices' => [['message' => ['content' => json_encode(['message' => 'What is the approved offer?', 'needs_input' => ['Confirm the offer.'], 'subject' => 'Your video', 'preheader' => '', 'sections' => [$video], 'blocks' => [], 'body_html' => '', 'body_text' => ''])]]]]);
        $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Add this video with an offer', 'scope' => 'email', 'asset_ids' => [$id], 'videos' => [['url' => $url, 'asset_id' => $id]], 'video_urls' => [$url]])->assertOk()
            ->assertJsonPath('draft.editor_document_json.sections.0.media_id', $id)->assertJsonPath('context.needs_input.0', 'Confirm the offer.');
    }

    public function test_authoring_flag_and_malformed_rich_text_are_handled(): void
    {
        $this->login(); $this->artisan('email-workspace:enable')->assertSuccessful();
        $this->getJson('/api/messaging/templates/capabilities')->assertOk()->assertJsonPath('enabled', true);
        $this->artisan('email-workspace:enable --disable')->assertSuccessful();
        $this->getJson('/api/messaging/templates/capabilities')->assertOk()->assertJsonPath('enabled', false);
        $draft = $this->draft(); $draft['editor_document_json']['sections'][0]['rich_text'] = ['type' => 'doc', 'content' => 'invalid'];
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
    }

    public function test_shared_template_assets_are_readable_but_their_private_originals_are_not_shared(): void
    {
        $this->login();
        $asset = $this->post('/api/messaging/templates/assets', ['file' => UploadedFile::fake()->image('shared.jpg', 1200, 800)])->assertStatus(202)->json();
        $draft = $this->draft(); $draft['scope'] = 'GLOBAL';
        $draft['editor_document_json']['sections'][0] = array_merge($this->section('image'), ['media_id' => $asset['id'], 'image_url' => $asset['url']]);
        $template = $this->postJson('/api/messaging/templates', $draft)->assertCreated()->json();
        $other = User::factory()->create(['role' => 'admin']); Sanctum::actingAs($other);
        $this->getJson('/api/messaging/templates/assets/'.$asset['id'])->assertNotFound();
        $shared = $this->getJson('/api/messaging/templates/assets/'.$asset['id'].'?template_id='.$template['id'])->assertOk()->json();
        $this->assertArrayNotHasKey('original_path', $shared);
        $copy = $this->postJson('/api/messaging/templates/assets/'.$asset['id'].'/variants', ['template_id' => $template['id'], 'crop' => '1:1'])->assertStatus(202)->json();
        $this->assertNotSame($asset['id'], $copy['id']);
        $this->assertDatabaseHas('email_template_assets', ['id' => $copy['id'], 'user_id' => $other->id]);
        $this->deleteJson('/api/messaging/templates/assets/'.$asset['id'])->assertNotFound();
    }

    public function test_stalled_processing_is_reported_without_deleting_the_asset(): void
    {
        $user = $this->login(); $id = (string) Str::uuid();
        DB::table('email_template_assets')->insert(['id' => $id, 'user_id' => $user->id, 'name' => 'Stalled image', 'status' => 'optimizing', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)]);
        $this->getJson('/api/messaging/templates/assets/'.$id)->assertOk()->assertJsonPath('status', 'failed');
        $this->assertDatabaseHas('email_template_assets', ['id' => $id]);
    }

    public function test_unfinished_sales_drafts_autosave_and_preview_without_publishing(): void
    {
        $this->login(); $draft = $this->draft();
        $draft['editor_document_json']['sections'][] = $this->section('button');
        $draft['editor_document_json']['sections'][] = $this->section('image');
        $draft['editor_document_json']['sections'][] = $this->section('video');
        $this->putJson('/api/messaging/templates/drafts/'.Str::uuid(), ['base_revision' => 0, 'draft' => $draft])->assertOk();
        $this->postJson('/api/messaging/templates/preview', ['template' => $draft])->assertOk();
        $this->postJson('/api/messaging/templates', $draft)->assertUnprocessable();
    }

    public function test_saved_media_references_resolve_after_the_editor_closes_and_block_pending_send(): void
    {
        $user = $this->login(); $id = (string) Str::uuid(); $draft = $this->draft();
        DB::table('email_template_assets')->insert(['id' => $id, 'user_id' => $user->id, 'name' => 'Pending photo', 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        $draft['editor_document_json']['sections'][0] = array_merge($this->section('image'), ['media_id' => $id]);
        $saved = $this->postJson('/api/messaging/templates', $draft)->assertCreated()->json();
        $template = MessageTemplate::findOrFail($saved['id']);
        $storedUrl = $template->editor_document_json['sections'][0]['image_url'];
        try { app(\App\Services\Messaging\TemplateRenderer::class)->render($template, []); $this->fail('Pending media must not send.'); }
        catch (\Illuminate\Validation\ValidationException $expected) { $this->assertStringContainsString('processing', $expected->getMessage()); }
        $path = 'email-template-assets/'.$id.'.png'; Storage::disk('public')->put($path, 'fixture');
        DB::table('email_template_assets')->where('id', $id)->update(['status' => 'ready', 'path' => $path]);
        $rendered = app(\App\Services\Messaging\TemplateRenderer::class)->render($template, []);
        $this->assertStringContainsString($path, $rendered['html']);
        $this->assertSame($storedUrl, $template->fresh()->editor_document_json['sections'][0]['image_url']);
    }

    public function test_non_admin_cannot_upload_or_read_workspace_media(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer']));
        $this->getJson('/api/messaging/templates/assets')->assertForbidden();
    }
}
