<?php

namespace Tests\Unit;

use App\Exceptions\FalTerminalException;
use App\Services\FalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FalServiceTerminalErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fal.key' => 'test-only-secret', 'services.fal.image_model' => 'fal-ai/flux-kontext/dev', 'services.fal.model' => 'fal-ai/wan-pro/image-to-video']);
        Http::preventStrayRequests();
    }

    public function test_completed_but_invalid_result_is_terminal_and_does_not_fetch_the_same_result_again(): void
    {
        Http::fake([
            '*/status' => Http::response(['status' => 'COMPLETED']),
            '*/response' => Http::response(['detail' => [['msg' => 'Invalid image https://private.test/source.jpg', 'input' => 'data:image/jpeg;base64,PRIVATE_PIXELS']]], 422),
        ]);
        $fal = app(FalService::class);
        $this->assertSame('COMPLETED', $fal->modelStatus('fal-ai/flux-pro/outpaint', 'request-one'));
        try {
            $fal->modelImageResult('fal-ai/flux-pro/outpaint', 'request-one');
            $this->fail('Expected a terminal result rejection.');
        } catch (FalTerminalException $exception) {
            $this->assertSame(422, $exception->httpStatus);
            $this->assertTrue($exception->canDiscardRequest());
            $this->assertSafeMessage($exception);
        }
        Http::assertSentCount(2);
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_statuses_are_typed_but_credentials_do_not_discard_a_valid_request(int $status, bool $discard): void
    {
        Http::fake(['*' => Http::response(['detail' => 'https://private.test/source.jpg data:image/jpeg;base64,PRIVATE_PIXELS'], $status)]);
        try {
            app(FalService::class)->modelVideoResult('fal-ai/kling-video/v2.5-turbo/pro/image-to-video', 'request-two');
            $this->fail('Expected terminal rejection.');
        } catch (FalTerminalException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame($discard, $exception->canDiscardRequest());
            $this->assertSafeMessage($exception);
        }
    }

    public static function terminalStatuses(): array
    {
        return [[400, true], [401, false], [402, false], [403, false], [404, true], [410, true], [413, true], [415, true], [422, true]];
    }

    #[DataProvider('transientStatuses')]
    public function test_transient_result_failures_preserve_the_request(int $status): void
    {
        Http::fake(['*' => Http::response(['detail' => 'https://private.test/source.jpg PRIVATE_PIXELS'], $status)]);
        try {
            app(FalService::class)->imageEditResult('request-transient');
            $this->fail('Expected temporary failure.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(FalTerminalException::class, $exception);
            $this->assertSafeMessage($exception);
        }
        Http::assertSentCount(1);
    }

    public static function transientStatuses(): array
    {
        return [[408], [409], [425], [429], [500], [502], [503], [504]];
    }

    public function test_a_network_timeout_is_sanitized_and_does_not_become_a_terminal_rejection(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timed out at https://private.test/source.jpg data:image/jpeg;base64,PRIVATE_PIXELS'));
        try {
            app(FalService::class)->result('request-timeout');
            $this->fail('Expected network failure.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(FalTerminalException::class, $exception);
            $this->assertSafeMessage($exception);
        }
    }

    public function test_legacy_base_result_fallback_still_works_after_response_endpoint_404(): void
    {
        Http::fake([
            '*/response' => Http::response([], 404),
            '*/requests/request-legacy' => Http::response(['images' => [['url' => 'https://fixture.test/result.jpg']]]),
        ]);
        $this->assertSame('https://fixture.test/result.jpg', app(FalService::class)->modelImageResult('fal-ai/flux-pro/outpaint', 'request-legacy'));
        Http::assertSentCount(2);
    }

    public function test_image_submission_error_never_echoes_provider_input(): void
    {
        Http::fake(['*' => Http::response(['detail' => ['input' => 'data:image/jpeg;base64,PRIVATE_PIXELS', 'url' => 'https://private.test/source.jpg']], 422)]);
        try {
            app(FalService::class)->submitImageEdit('data:image/jpeg;base64,PRIVATE_PIXELS', 'enhance');
            $this->fail('Expected validation rejection.');
        } catch (FalTerminalException $exception) {
            $this->assertSafeMessage($exception);
        }
    }

    private function assertSafeMessage(RuntimeException $exception): void
    {
        $this->assertLessThan(220, strlen($exception->getMessage()));
        foreach (['https://', 'base64', 'PRIVATE_PIXELS', 'test-only-secret'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $exception->getMessage());
        }
        $this->assertNull($exception->getPrevious());
    }
}
