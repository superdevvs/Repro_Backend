<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\EmailWorkspaceAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailWorkspaceReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        config(['queue.default' => 'sync']);
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_manual_upload_cannot_claim_an_artwork_generation_id(): void
    {
        $this->login();
        $generation = (string) Str::uuid();
        $asset = $this->post('/api/messaging/templates/assets', [
            'file' => UploadedFile::fake()->image('listing.jpg', 600, 400),
            'slot' => 'content',
            'artwork_id' => $generation,
            'unvalidated_metadata' => 'must not be persisted',
        ])->assertStatus(202)->json();

        $row = DB::table('email_template_assets')->find($asset['id']);
        $this->assertNull($row->artwork_id);
        $this->assertSame(['slot' => 'content'], json_decode($row->options, true));
    }

    public function test_only_internal_generated_sources_can_associate_an_artwork_id(): void
    {
        $user = $this->login();
        $service = app(EmailWorkspaceAssets::class);
        $bytes = UploadedFile::fake()->image('artwork.png', 100, 100)->get();
        $generation = (string) Str::uuid();

        $uploaded = $service->create($user->id, $bytes, 'Manual image', ['artwork_id' => $generation]);
        $this->assertNull($uploaded->artwork_id);

        $generated = $service->create($user->id, $bytes, 'Generated image', ['artwork_id' => $generation], 'generated');
        $this->assertSame($generation, $generated->artwork_id);
        $this->assertSame('image/png', $generated->mime);
    }

    public function test_vimeo_metadata_timeout_keeps_the_video_editable_with_a_custom_thumbnail(): void
    {
        $this->login();
        Http::preventStrayRequests();
        Http::fake(['https://vimeo.com/api/oembed.json*' => function () {
            throw new ConnectionException('Simulated provider timeout.');
        }]);

        $this->postJson('/api/messaging/templates/video', ['url' => 'https://vimeo.com/123456789'])
            ->assertOk()
            ->assertJsonPath('url', 'https://vimeo.com/123456789')
            ->assertJsonPath('provider', 'vimeo')
            ->assertJsonPath('asset', null)
            ->assertJsonPath('needs_thumbnail', true);
    }

    public function test_v2_markdown_links_keep_connected_fields_for_recipient_substitution(): void
    {
        $this->login();
        $draft = [
            'name' => 'Connected offer', 'channel' => 'EMAIL', 'scope' => 'USER',
            'subject' => 'Your offer', 'body_html' => '', 'body_text' => '',
            'editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [[
                'id' => (string) Str::uuid(), 'type' => 'offer', 'heading' => 'Ready for your next shoot?',
                'body' => '[Open your dashboard]({{dashboard_url}})',
            ]]],
        ];

        $response = $this->postJson('/api/messaging/templates/preview', [
            'template' => $draft, 'variables' => ['dashboard_url' => 'https://example.com/my-dashboard'],
        ])->assertOk();
        $this->assertStringContainsString('href="https://example.com/my-dashboard"', $response->json('html'));
        $this->assertStringNotContainsString('%7B%7Bdashboard_url%7D%7D', $response->json('html'));
    }

    public function test_explicit_typography_survives_email_presentation_and_keeps_rich_span_colors(): void
    {
        $this->login();
        $rich = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'A rich subheading']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Plain copy. '],
                ['type' => 'text', 'text' => 'Inline green.', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#008844']]]],
            ]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Keep the list readable.']]]]]]],
        ]];
        $draft = ['name' => 'Custom typography', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'Designed copy', 'body_html' => '', 'body_text' => '',
            'editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [
                ['id' => 'custom-text', 'type' => 'text', 'heading' => 'A clear heading', 'body' => 'Plain copy. Inline green.', 'rich_text' => $rich, 'styles' => ['color' => '#cc2233', 'font_size' => 22]],
                ['id' => 'custom-offer', 'type' => 'offer', 'heading' => 'A styled offer', 'body' => 'Offer copy.', 'styles' => ['color' => '#bb3344', 'font_size' => 20]],
                ['id' => 'custom-size-only', 'type' => 'signature', 'heading' => 'Size only', 'body' => '', 'rich_text' => $rich, 'styles' => ['font_size' => 22]],
            ]]];
        $html = $this->postJson('/api/messaging/templates/preview', ['template' => $draft, 'theme' => 'dark'])->assertOk()->json('html');
        $document = new \DOMDocument(); @$document->loadHTML($html); $xpath = new \DOMXPath($document);
        $paragraph = $xpath->query('//*[@data-email-section="custom-text"]//p[contains(., "Plain copy.")]')->item(0);
        $heading = $xpath->query('//*[@data-email-section="custom-text"]//h2')->item(0);
        $listItem = $xpath->query('//*[@data-email-section="custom-text"]//li')->item(0);
        $span = $xpath->query('//*[@data-email-section="custom-text"]//span[.="Inline green."]')->item(0);
        $offer = $xpath->query('//*[@data-email-section="custom-offer"]//p[.="Offer copy."]')->item(0);
        $richHeading = $xpath->query('//*[@data-email-section="custom-text"]//h3')->item(0);
        $sizeOnlyHeading = $xpath->query('//*[@data-email-section="custom-size-only"]//h3')->item(0);
        $this->assertMatchesRegularExpression('/color:\s*#cc2233\s*!important/i', $paragraph->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*22px\s*!important/i', $paragraph->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*22px\s*!important/i', $listItem->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*24px/i', $heading->getAttribute('style'));
        $this->assertMatchesRegularExpression('/color:\s*#cc2233\s*!important/i', $heading->getAttribute('style'));
        $this->assertMatchesRegularExpression('/color:\s*#008844/i', $span->getAttribute('style'));
        $this->assertMatchesRegularExpression('/color:\s*#bb3344\s*!important/i', $offer->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*20px\s*!important/i', $offer->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*24px/i', $richHeading->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*24px/i', $sizeOnlyHeading->getAttribute('style'));
    }

    public function test_row_typography_preserves_an_explicitly_styled_child_card(): void
    {
        $this->login();
        $draft = ['name' => 'Styled cards', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'Your services', 'body_html' => '', 'body_text' => '',
            'editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [[
                'id' => 'row', 'type' => 'columns', 'styles' => ['color' => '#aa3344', 'font_size' => 20], 'columns' => [
                    [['id' => 'custom-card', 'type' => 'text', 'heading' => 'Card one', 'body' => 'Custom card copy.', 'styles' => ['color' => '#2233cc', 'font_size' => 18]]],
                    [['id' => 'inherited-card', 'type' => 'text', 'heading' => 'Card two', 'body' => 'Inherited row copy.']],
                ],
            ]]]];
        $html = $this->postJson('/api/messaging/templates/preview', ['template' => $draft])->assertOk()->json('html');
        $document = new \DOMDocument(); @$document->loadHTML($html); $xpath = new \DOMXPath($document);
        $custom = $xpath->query('//*[@data-email-section="custom-card"]//p')->item(0);
        $inherited = $xpath->query('//*[@data-email-section="inherited-card"]//p')->item(0);
        $this->assertMatchesRegularExpression('/color:\s*#2233cc\s*!important/i', $custom->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*18px\s*!important/i', $custom->getAttribute('style'));
        $this->assertMatchesRegularExpression('/color:\s*#aa3344\s*!important/i', $inherited->getAttribute('style'));
        $this->assertMatchesRegularExpression('/font-size:\s*20px\s*!important/i', $inherited->getAttribute('style'));
    }

    public function test_robbie_can_preserve_change_and_reset_styles_without_losing_rich_text_or_media(): void
    {
        $this->login();
        $mediaId = (string) Str::uuid();
        $rich = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Existing copy.', 'marks' => [['type' => 'bold']]]]]]];
        $section = ['id' => 'design-card', 'type' => 'feature', 'heading' => 'A useful feature', 'body' => 'Existing copy.', 'image_url' => 'https://example.com/existing.png', 'media_id' => $mediaId, 'rich_text' => $rich, 'styles' => ['color' => '#112233', 'font_size' => 16]];
        $draft = ['name' => 'Design revision', 'channel' => 'EMAIL', 'scope' => 'USER', 'subject' => 'Your next listing', 'body_html' => '', 'body_text' => '', 'editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [$section]]];
        $allNull = array_fill_keys(['color','background','font_size','padding','radius','fit','aspect','focal_x','focal_y'], null);
        $variants = [null, 'missing', array_replace($allNull, ['color' => '#2255aa', 'font_size' => 21, 'padding' => 0]), $allNull];
        $responses = [];
        foreach ($variants as $styles) {
            $proposal = \Illuminate\Support\Arr::except($section, ['rich_text','media_id','styles']);
            if ($styles !== 'missing') $proposal['styles'] = $styles;
            $responses[] = ['choices' => [['message' => ['content' => json_encode(['message' => '', 'needs_input' => [], 'subject' => $draft['subject'], 'preheader' => '', 'sections' => [$proposal], 'blocks' => [], 'body_html' => '', 'body_text' => ''])]]]];
        }
        $this->mock(\App\Services\ReproAi\LlmClient::class)->shouldReceive('chatCompletion')->times(4)->withArgs(function ($messages, $tools, $stream, $options) {
            $sectionSchema = $options['response_format']['json_schema']['schema']['properties']['sections']['items']['properties'];
            $this->assertSame(['object','null'], $sectionSchema['styles']['type']);
            $this->assertSame($sectionSchema['styles'], $sectionSchema['columns']['items']['items']['properties']['styles']);
            $this->assertFalse($sectionSchema['styles']['additionalProperties']);
            return true;
        })->andReturn(...$responses);
        foreach ($variants as $index => $styles) {
            $response = $this->postJson('/api/messaging/templates/assist', ['draft' => $draft, 'instruction' => 'Update only this visual design. Keep the text and image.', 'scope' => 'section', 'section_id' => $section['id']])->assertOk();
            $result = $response->json('draft.editor_document_json.sections.0');
            $this->assertSame($rich, $result['rich_text']);
            $this->assertSame($mediaId, $result['media_id']);
            $this->assertSame($section['image_url'], $result['image_url']);
            $expected = $index < 2 ? $section['styles'] : ($index === 2 ? ['color' => '#2255aa', 'font_size' => 21, 'padding' => 0] : []);
            $this->assertSame($expected, $result['styles']);
        }
    }
}
