<?php

namespace Tests\Unit\Studio;

use App\Services\Studio\Providers\FotelloClient;
use App\Services\Studio\Providers\FotelloException;
use App\Services\Studio\Providers\FotelloTransferUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FotelloClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['studio_providers.fotello' => ['api_key' => 'test-only-credential', 'team_id' => 'provider-team']]);
        Http::preventStrayRequests();
    }

    #[DataProvider('documentedRequests')]
    public function test_documented_writes_use_the_exact_contract_and_server_team(string $method, string $endpoint, array $payload, array|string $response, array $expected): void
    {
        $sentOptions = [];
        Http::fake(function (Request $request, array $options) use (&$sentOptions, $response) {
            $sentOptions = $options;

            return Http::response($response, 201);
        });
        $this->assertSame($expected, $this->client()->{$method}($payload));
        $this->assertFalse($sentOptions['allow_redirects']);
        $this->assertTrue($sentOptions['verify']);
        Http::assertSent(function (Request $request) use ($endpoint, $payload): bool {
            $this->assertSame($payload + ['teamId' => 'provider-team'], $request->data());

            return $request->method() === 'POST' && $request->url() === 'https://api.fotello.co/v1/'.$endpoint
                && $request->hasHeader('Authorization', 'Bearer test-only-credential');
        });
        Http::assertSentCount(1);
    }

    public static function documentedRequests(): array
    {
        $variant = ['type' => 'twilight', 'preferences' => ['server_configured_option' => 'opaque']];

        return [
            ['createListing', 'create-listing', ['name' => 'Draft 1', 'num_total_brackets' => 3, 'filenames' => ['room.nef'], 'charge_photo_edit' => true], ['id' => 'listing-1'], ['id' => 'listing-1']],
            ['createUpload', 'create-upload', ['filename' => 'room.nef', 'listingId' => 'listing-1'], ['id' => 'upload-1', 'url' => 'https://files.example.test/upload?signature=signed', 'uri' => 's3://provider/room.nef', 'expires' => '2026-09-09T00:00:00Z'], ['id' => 'upload-1', 'url' => 'https://files.example.test/upload?signature=signed', 'uri' => 's3://provider/room.nef', 'expires' => '2026-09-09T00:00:00Z']],
            ['createEnhance', 'create-enhance', ['upload_ids' => ['upload-1', 'upload-2', 'upload-3'], 'listing_id' => 'listing-1', 'shot_type' => 'interior', 'preferences' => ['contrast_style' => 'server-configured-style']], ['id' => 'enhance-1'], ['id' => 'enhance-1']],
            ['updateEnhance', 'update-enhance', ['id' => 'enhance-1', 'variant' => $variant, 'override' => false], ['enhanceId' => 'enhance-1', 'variantId' => 'variant-1', 'renderId' => 'render-1', 'orderId' => 'order-1'], ['enhanceId' => 'enhance-1', 'variantId' => 'variant-1', 'renderId' => 'render-1', 'orderId' => 'order-1']],
            ['updateAsset', 'update-asset', ['id' => 'asset-1', 'variant' => $variant], ['assetId' => 'asset-1', 'variantId' => 'variant-1', 'renderId' => 'render-1', 'orderId' => 'order-1'], ['assetId' => 'asset-1', 'variantId' => 'variant-1', 'renderId' => 'render-1', 'orderId' => 'order-1']],
            ['createAiRevision', 'create-ai-revision', ['variant_id' => 'variant-1', 'input_image_uri' => 's3://provider/in.jpg', 'selected_output_uri' => 's3://provider/out.jpg', 'prompt' => 'Retain the window', 'model' => 'pro', 'reference_images' => [['uri' => 's3://provider/ref.jpg', 'filename' => 'ref.jpg']]], ['success' => true, 'error' => null], ['success' => true]],
            ['createUpscale', 'create-upscale', ['variant_id' => 'variant-1', 'input_image_uri' => 's3://provider/in.jpg', 'selected_output_uri' => 's3://provider/out.jpg'], '{}', []],
            ['prepareDownload', 'prepare-download', ['listing_id' => 'listing-1', 'sections' => ['photos'], 'photo_formats' => ['original']], ['download_url' => 'https://files.example.test/archive.zip', 'total_items' => 3, 'processed_items' => 2], ['download_url' => 'https://files.example.test/archive.zip', 'total_items' => 3, 'processed_items' => 2]],
        ];
    }

    public function test_polling_an_existing_id_is_resumable_without_another_creation(): void
    {
        Http::fake(['https://api.fotello.co/v1/get-enhance*' => Http::sequence()
            ->push(['id' => 'saved-id', 'status' => 'pending'])
            ->push(['id' => 'saved-id', 'status' => 'in_progress'])
            ->push(['id' => 'saved-id', 'status' => 'completed', 'enhanced_image_url' => 'https://files.example.test/result.jpg'])]);
        foreach (['pending', 'in_progress', 'completed'] as $status) {
            $this->assertSame($status, $this->client()->getEnhance('saved-id')['status']);
        }
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->data() === ['id' => 'saved-id']);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_failed_enhancement_is_returned_truthfully_and_provider_error_text_is_dropped(): void
    {
        Http::fake(['*' => Http::response(['id' => 'saved-id', 'status' => 'failed', 'error' => 'Fotello PRIVATE_PIXELS https://private.test'])]);
        $this->assertSame(['id' => 'saved-id', 'status' => 'failed', 'enhanced_image_url' => null, 'enhanced_image_url_expires' => null], $this->client()->getEnhance('saved-id'));
    }

    #[DataProvider('invalidReads')]
    public function test_incomplete_status_contracts_never_look_completed(array|string $response): void
    {
        Http::fake(['*' => Http::response($response)]);
        $this->expectException(FotelloException::class);
        $this->client()->getEnhance('saved-id');
    }

    public static function invalidReads(): array
    {
        return [[['id' => 'saved-id', 'status' => 'completed']], [['id' => 'someone-else', 'status' => 'pending']], [['id' => 'saved-id', 'status' => 'success']], ['not JSON'], ['[]']];
    }

    #[DataProvider('rejectedPayloads')]
    public function test_invalid_or_undocumented_request_fields_do_not_reach_the_provider(string $method, array $payload): void
    {
        Http::fake();
        try {
            $this->client()->{$method}($payload);
            $this->fail('Invalid payload was accepted.');
        } catch (FotelloException $exception) {
            $this->assertSame('request_contract', $exception->category);
        }
        Http::assertNothingSent();
    }

    public static function rejectedPayloads(): array
    {
        return [
            ['createListing', ['name' => 'Draft', 'teamId' => 'other-team']],
            ['createListing', ['name' => 'Draft', 'webhook_url' => 'https://unapproved.test']],
            ['createEnhance', ['listing_id' => 'listing-1', 'upload_ids' => []]],
            ['createEnhance', ['listing_id' => 'listing-1', 'upload_ids' => ['u1'], 'preferences' => ['brightness' => 10]]],
            ['updateAsset', ['id' => 'asset-1']],
            ['updateEnhance', ['id' => 'e1', 'variant' => []]],
            ['updateEnhance', ['id' => 'e1', 'variant' => ['type' => 'green_grass', 'preferences' => []]]],
            ['updateEnhance', ['id' => 'e1', 'variant' => ['type' => 'twilight', 'preferences' => ['list-value']]]],
            ['createAiRevision', ['variant_id' => 'v1', 'input_image_uri' => 'in', 'selected_output_uri' => 'out', 'prompt' => 'edit', 'reference_images' => [['url' => 'unknown']]]],
            ['prepareDownload', ['listing_id' => 'l1', 'photo_formats' => ['unsupported']]],
        ];
    }

    public function test_empty_variant_preferences_are_encoded_as_documented_object(): void
    {
        Http::fake(['*' => Http::response(['enhanceId' => 'e1', 'variantId' => 'v1', 'renderId' => 'r1', 'orderId' => 'o1'])]);
        $this->client()->updateEnhance(['id' => 'e1', 'variant' => ['type' => 'edit_image', 'preferences' => []]]);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"preferences":{}'));
    }

    public function test_missing_credentials_fail_locally_and_constructor_override_uses_the_selected_team(): void
    {
        Http::fake(['*' => Http::response(['id' => 'l1'])]);
        try {
            $this->client(['api_key' => ''])->createListing(['name' => 'Draft']);
            $this->fail('Unconfigured integration accepted.');
        } catch (FotelloException $exception) {
            $this->assertSame('configuration', $exception->category);
        }
        Http::assertNothingSent();
        $this->client(['api_key' => 'server-override-key', 'team_id' => 'server-override-team'])->createListing(['name' => 'Draft']);
        Http::assertSent(fn (Request $request) => $request['teamId'] === 'server-override-team' && $request->hasHeader('Authorization', 'Bearer server-override-key'));
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_neutral_and_mutation_ambiguity_prevents_blind_retry(int $status, bool $retryable, bool $ambiguous): void
    {
        Http::fake(['*' => Http::response(['code' => 'private', 'message' => 'Fotello test-only-credential PRIVATE_PIXELS https://private.test'], $status)]);
        try {
            $this->client()->createEnhance(['listing_id' => 'l1', 'upload_ids' => ['u1']]);
            $this->fail('Provider error accepted.');
        } catch (FotelloException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame($retryable, $exception->retryable);
            $this->assertSame($ambiguous, $exception->ambiguousOutcome);
            $this->assertPrivate($exception);
        }
        Http::assertSentCount(1);
    }

    public static function httpFailures(): array
    {
        return [[400, false, false], [401, false, false], [402, false, false], [403, false, false], [422, false, false], [429, true, false], [408, false, true], [500, false, true], [503, false, true]];
    }

    public function test_lost_submission_response_is_ambiguous_but_poll_timeout_is_safe_to_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('Fotello test-only-credential PRIVATE_PIXELS https://private.test'));
        foreach ([true, false] as $mutation) {
            try {
                $mutation ? $this->client()->createListing(['name' => 'Draft']) : $this->client()->getEnhance('saved-id');
                $this->fail('Expected timeout.');
            } catch (FotelloException $exception) {
                $this->assertSame($mutation, $exception->ambiguousOutcome);
                $this->assertSame(! $mutation, $exception->retryable);
                $this->assertPrivate($exception);
            }
        }
    }

    public function test_missing_creation_id_is_an_unconfirmed_submission_and_false_revision_is_rejected(): void
    {
        Http::fake(['*' => Http::sequence()->push('{}')->push(['success' => false, 'error' => 'PRIVATE_PIXELS Fotello'])]);
        try {
            $this->client()->createListing(['name' => 'Draft']);
            $this->fail('Missing ID accepted.');
        } catch (FotelloException $exception) {
            $this->assertTrue($exception->ambiguousOutcome);
        }
        try {
            $this->client()->createAiRevision(['variant_id' => 'v1', 'input_image_uri' => 'in', 'selected_output_uri' => 'out', 'prompt' => 'edit']);
            $this->fail('Failed revision accepted.');
        } catch (FotelloException $exception) {
            $this->assertSame('revision_rejected', $exception->category);
            $this->assertPrivate($exception);
        }
    }

    public function test_presigned_put_and_download_pin_dns_and_never_forward_credentials(): void
    {
        $sentOptions = [];
        Http::fake(function (Request $request, array $options) use (&$sentOptions) {
            $sentOptions[] = $options;

            return Http::response('image-bytes');
        });
        $this->client()->uploadBytes(['id' => 'u1', 'uri' => 's3://bucket/in.jpg', 'url' => 'https://files.example.test/upload?sig=test-signature'], 'source-bytes');
        $this->assertSame('image-bytes', $this->client()->downloadBytes('https://files.example.test/result?sig=test-signature'));
        foreach ($sentOptions as $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['files.example.test:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        }
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization'));
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT' && $request->body() === 'source-bytes'
                && $request->hasHeader('Content-Type', 'application/octet-stream');
        });
        Http::assertSentCount(2);
    }

    public function test_redirects_and_oversized_results_are_not_followed_or_returned(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 302, ['Location' => 'http://127.0.0.1/private'])->push('12345')]);
        foreach (['rejected', 'transfer_limit'] as $category) {
            try {
                $this->client(['max_transfer_bytes' => 4])->downloadBytes('https://files.example.test/result');
                $this->fail('Invalid transfer accepted.');
            } catch (FotelloException $exception) {
                $this->assertSame($category, $exception->category);
            }
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));
    }

    private function client(array $overrides = []): FotelloClient
    {
        $urls = new class extends FotelloTransferUrl
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };

        return new FotelloClient($overrides, $urls);
    }

    private function assertPrivate(FotelloException $exception): void
    {
        foreach (['Fotello', 'test-only-credential', 'PRIVATE_PIXELS', 'https://'] as $secret) {
            $this->assertStringNotContainsString($secret, $exception->getMessage());
        }
        $this->assertNull($exception->getPrevious());
    }
}
