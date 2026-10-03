<?php

namespace Tests\Unit\Studio;

use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Services\ReproAi\LlmClient;
use App\Services\Studio\WorkspacePhotoEnhancement;
use App\Services\Studio\WorkspaceSceneClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceSceneClassifierTest extends TestCase
{
    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['studio_providers.scene_classifier.provider' => 'gemini',
            'studio_providers.scene_classifier.gemini_api_key' => 'test-only-secret',
            'studio_providers.scene_classifier.gemini_model' => 'gemini-3.5-flash-lite']);
        $image = imagecreatetruecolor(24, 16);
        ob_start();
        imagejpeg($image);
        $this->jpeg = ob_get_clean();
        imagedestroy($image);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldNotReceive('chatCompletion');
        $this->app->instance(LlmClient::class, $llm);
    }

    public function test_gemini_classifies_with_a_private_bounded_image_and_no_openai_call(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['thought' => true, 'text' => 'PRIVATE REASONING'],
                ['text' => '{"scene":"exterior"}'],
            ]]]]]);
        });
        $this->assertSame('exterior', app(WorkspaceSceneClassifier::class)->classify($this->jpeg));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
            && $r->hasHeader('x-goog-api-key', 'test-only-secret')
            && $r['generationConfig']['maxOutputTokens'] === 128
            && $r['generationConfig']['thinkingConfig']['thinkingLevel'] === 'MINIMAL'
            && $r['generationConfig']['responseJsonSchema']['properties']['scene']['enum'] === ['interior', 'exterior']
            && $r['contents'][0]['parts'][0]['inlineData']['mimeType'] === 'image/jpeg'
            && base64_decode($r['contents'][0]['parts'][0]['inlineData']['data'], true) !== false);
        Http::assertSentCount(1);
    }

    #[DataProvider('unusableResponses')]
    public function test_unusable_gemini_results_never_guess_an_interior_or_retry(int $status, array $body): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);
        try {
            app(WorkspaceSceneClassifier::class)->classify($this->jpeg);
            $this->fail('An unusable scene must stop before editing.');
        } catch (StudioProviderException $e) {
            $this->assertStringNotContainsString('test-only-secret', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE PROVIDER BODY', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public static function unusableResponses(): array
    {
        return [
            [401, ['error' => 'PRIVATE PROVIDER BODY']],
            [429, ['error' => 'PRIVATE PROVIDER BODY']],
            [503, ['error' => 'PRIVATE PROVIDER BODY']],
            [200, ['candidates' => [['finishReason' => 'MAX_TOKENS']]]],
            [200, ['candidates' => []]],
            [200, ['promptFeedback' => ['blockReason' => 'SAFETY']]],
            [200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"scene":"unknown"}']]]]]]],
            [200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'invalid json']]]]]]],
            [200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => 'invalid shape']]]]],
        ];
    }

    public function test_network_failure_is_actionable_without_leaking_the_request(): void
    {
        Http::fake(fn () => throw new ConnectionException('PRIVATE PROVIDER BODY test-only-secret'));
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('Gemini photo type detection could not be reached');
        app(WorkspaceSceneClassifier::class)->classify($this->jpeg);
    }

    public function test_manual_scene_does_not_need_either_api(): void
    {
        config(['studio_providers.scene_classifier.gemini_api_key' => null, 'services.openai.api_key' => null]);
        $w = new StudioWorkspace(['config' => ['adjustments' => ['sceneType' => 'exterior']]]);
        $this->assertSame('exterior', app(WorkspacePhotoEnhancement::class)->sceneType($w, 'not-needed'));
        Http::assertNothingSent();
    }

    public function test_missing_gemini_key_does_not_fall_back_to_openai(): void
    {
        config(['studio_providers.scene_classifier.gemini_api_key' => null]);
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('needs an API key');
        app(WorkspaceSceneClassifier::class)->classify($this->jpeg);
    }

    public function test_openai_quota_failure_is_explained_without_a_generic_worker_error(): void
    {
        config(['studio_providers.scene_classifier.provider' => 'openai', 'services.openai.api_key' => 'test-only-secret']);
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('chatCompletion')->once()->andThrow(new \Exception('credit_balance_exhausted PRIVATE PROVIDER BODY'));
        $this->app->instance(LlmClient::class, $llm);
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('OpenAI photo type detection is unavailable. Ask an administrator to check API credits and limits');
        app(WorkspaceSceneClassifier::class)->classify($this->jpeg);
    }
}
