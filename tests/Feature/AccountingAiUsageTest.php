<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Accounting\AiUsageRecorder;
use App\Services\ReproAi\LlmClient;
use App\Services\Studio\Providers\OpenAiImageProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class AccountingAiUsageTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $responses): LlmClient
    {
        config(['services.openai.api_key' => 'fixture-only', 'services.openai.model' => 'gpt-4o']);
        $client = new LlmClient;
        (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['base_uri' => 'https://api.openai.com/v1/', 'handler' => HandlerStack::create(new MockHandler($responses))]));
        return $client;
    }

    #[DataProvider('deniedRoles')]
    public function test_only_superadmins_can_view_usage(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-01&end=2026-10-10')->assertForbidden();
    }

    public static function deniedRoles(): array
    {
        return array_map(fn ($role) => [$role], ['admin', 'client', 'photographer', 'editor', 'editing_manager', 'salesRep']);
    }

    public function test_guest_is_denied(): void
    {
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-01&end=2026-10-10')->assertUnauthorized();
    }

    public function test_chat_records_real_usage_and_cached_standard_price_without_customer_content(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        $client = $this->client([new Response(200, ['x-request-id' => 'req_fixture'], json_encode([
            'model' => 'gpt-4o-2024-11-20', 'choices' => [['message' => ['content' => 'private response']]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 200, 'prompt_tokens_details' => ['cached_tokens' => 400]],
        ]))]);
        $client->chatCompletion([['role' => 'user', 'content' => 'private customer prompt']], [], false, ['usage_feature' => 'robbie_chat']);
        $row = DB::table('ai_provider_usage')->first();
        $this->assertSame('success', $row->status);
        $this->assertSame('req_fixture', $row->request_id);
        $this->assertSame(1000, $row->input_tokens);
        $this->assertSame(400, $row->cached_tokens);
        $this->assertEqualsWithDelta(0.004, $row->estimated_cost_usd, 0.0000001);
        $this->assertStringNotContainsString('private', json_encode($row));
        $this->assertStringNotContainsString('fixture-only', json_encode($row));
    }

    public function test_failed_request_is_counted_once_without_zero_cost_or_retry(): void
    {
        $client = $this->client([new Response(429, ['x-request-id' => 'req_limited'], '{"error":{"code":"rate_limit"}}')]);
        try { $client->chatCompletion([['role' => 'user', 'content' => 'hello']], [], false); $this->fail('Expected provider failure'); }
        catch (\Exception) {}
        $this->assertDatabaseCount('ai_provider_usage', 1);
        $row = DB::table('ai_provider_usage')->first();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->estimated_cost_usd);
        $this->assertNull($row->input_tokens);
    }

    public function test_stream_records_final_usage_chunk_even_without_choices(): void
    {
        $body = 'data: '.json_encode(['model' => 'gpt-4o', 'choices' => [['delta' => ['content' => 'Hi']]]])."\n\n";
        $body .= 'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])."\n\ndata: [DONE]\n\n";
        $client = $this->client([new Response(200, [], $body)]);
        $response = json_decode($client->chatCompletion([['role' => 'user', 'content' => 'Hello']], [], true, ['usage_feature' => 'robbie_chat']), true);
        $this->assertSame('Hi', $response['content']);
        $this->assertDatabaseHas('ai_provider_usage', ['input_tokens' => 10, 'output_tokens' => 2, 'status' => 'success']);
    }

    public function test_history_is_idempotent_and_missing_cost_is_not_zero(): void
    {
        $this->artisan('ai-usage:import-history')->assertSuccessful();
        $this->artisan('ai-usage:import-history')->assertSuccessful();
        $this->assertDatabaseCount('ai_provider_usage', 5);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-01&end=2026-10-10')->assertOk()
            ->assertJsonPath('data.summary.historical_calls', 177)->assertJsonPath('data.summary.metered_calls', 0)
            ->assertJsonPath('data.summary.estimated_cost_usd', null)->assertJsonPath('data.summary.unknown_token_calls', 177)
            ->assertJsonCount(10, 'data.daily')->assertJsonPath('data.daily.1.historical_calls', 53);
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-06&end=2026-10-06')->assertOk()
            ->assertJsonPath('data.summary.historical_calls', 4)->assertJsonCount(1, 'data.daily');
    }

    public function test_date_validation_and_metered_historical_separation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-10&end=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/admin/accounting-ai-usage?start=2025-01-01&end=2026-10-10')->assertUnprocessable();
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(0, 0));
        $recorder = app(AiUsageRecorder::class);
        $id = $recorder->begin('email_assistant', 'unknown-model', 'chat/completions');
        $recorder->finish($id, 'unknown-model', ['prompt_tokens' => 30, 'completion_tokens' => 10], 'success');
        $this->getJson('/api/admin/accounting-ai-usage?start=2026-10-10&end=2026-10-10')->assertOk()
            ->assertJsonPath('data.summary.metered_calls', 1)->assertJsonPath('data.summary.historical_calls', 0)
            ->assertJsonPath('data.summary.input_tokens', 30)->assertJsonPath('data.summary.unpriced_calls', 1)
            ->assertJsonPath('data.summary.estimated_cost_usd', null)->assertJsonPath('data.features.0.label', 'Email drafting');
    }

    public function test_image_pricing_requires_modality_split_and_never_uses_chat_rates(): void
    {
        $recorder = app(AiUsageRecorder::class);
        $usage = ['input_tokens' => 1100, 'output_tokens' => 2000, 'input_tokens_details' => ['text_tokens' => 100, 'image_tokens' => 1000]];
        $this->assertEqualsWithDelta(0.03425, $recorder->estimate('gpt-image-2', $usage), 0.0000001);
        unset($usage['input_tokens_details']);
        $this->assertNull($recorder->estimate('gpt-image-2', $usage));
        $this->assertNull($recorder->estimate('unpriced-model', $usage));
    }

    public function test_image_provider_records_actual_usage_without_changing_the_generated_result(): void
    {
        config(['services.openai.api_key' => 'fixture-only']);
        $image = imagecreatetruecolor(32, 32);
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [['b64_json' => base64_encode($bytes)]],
            'usage' => ['input_tokens' => 1100, 'output_tokens' => 2000, 'input_tokens_details' => ['text_tokens' => 100, 'image_tokens' => 1000]],
        ], 200, ['x-request-id' => 'req_image'])]);
        $result = app(OpenAiImageProvider::class)->edit($bytes, 'Improve this image');
        $this->assertSame([32, 32], array_slice(getimagesizefromstring($result), 0, 2));
        $this->assertDatabaseHas('ai_provider_usage', ['feature' => 'image_edit', 'input_tokens' => 1100, 'output_tokens' => 2000, 'request_id' => 'req_image']);
        $this->assertEqualsWithDelta(0.03425, DB::table('ai_provider_usage')->first()->estimated_cost_usd, 0.0000001);
        Http::assertSentCount(1);
    }

    public function test_ledger_failure_never_repeats_a_paid_provider_call(): void
    {
        Schema::drop('ai_provider_usage');
        $client = $this->client([new Response(200, [], '{"choices":[{"message":{"content":"Done"}}]}')]);
        $result = $client->chatCompletion([['role' => 'user', 'content' => 'Hello']], [], false);
        $this->assertSame('Done', $result['choices'][0]['message']['content']);
    }
}
