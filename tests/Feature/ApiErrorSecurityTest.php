<?php

namespace Tests\Feature;

use App\Exceptions\IguideOfflineUploadException;
use App\Exceptions\Messaging\SmsSendException;
use App\Exceptions\PublicApiResponseException;
use App\Exceptions\PublicBusinessRuleException;
use App\Exceptions\PublicConflictException;
use App\Http\Controllers\API\AddressProviderSettingsController;
use App\Services\ApiErrorResponder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedSecurityTestCase;

class ApiErrorSecurityTest extends IsolatedSecurityTestCase
{
    private const CANARY = 'SQLSTATE password=secret-canary /srv/private/credentials.php https://provider.test?token=secret-canary';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['app.debug' => false]);
    }

    #[DataProvider('opaqueFailures')]
    public function test_global_errors_keep_status_and_never_expose_exception_data(\Throwable $exception, int $status): void
    {
        Route::middleware('api')->get('/api/error-contract-test', fn () => throw $exception);
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            $response = $this->withHeader('X-Trace-Id', 'caller-controlled-id')->getJson('/api/error-contract-test');
            $response->assertStatus($status)->assertJsonStructure(['message', 'code', 'request_id']);
            $this->assertStringNotContainsString('secret-canary', $response->getContent());
            $this->assertStringNotContainsString('credentials.php', $response->getContent());
            $id = $response->json('request_id');
            $this->assertTrue(Str::isUuid($id));
            $this->assertSame($id, $response->headers->get('X-Trace-Id'));
            $this->assertSame($id, $response->headers->get('X-Request-Id'));
        }
    }

    public static function opaqueFailures(): array
    {
        return [
            [new \RuntimeException(self::CANARY), 500],
            [new \InvalidArgumentException(self::CANARY), 500],
            [new AuthenticationException(self::CANARY), 401],
            [new \Illuminate\Auth\Access\AuthorizationException(self::CANARY), 403],
            [new HttpException(403, self::CANARY), 403],
        ];
    }

    public function test_existing_controller_catch_sanitizes_database_error_and_keeps_envelope(): void
    {
        DB::shouldReceive('table')->once()->with('settings')->andThrow(new \RuntimeException(self::CANARY));
        Route::middleware('api')->get('/api/error-catch-test', [AddressProviderSettingsController::class, 'getProvider']);
        $response = $this->getJson('/api/error-catch-test');
        $response->assertStatus(500)->assertJson(['success' => false, 'message' => 'Failed to get address provider setting'])
            ->assertJsonStructure(['error', 'request_id', 'code']);
        $this->assertStringNotContainsString('secret-canary', $response->getContent());
    }

    public function test_reviewed_validation_conflict_sms_and_upload_messages_keep_actionable_details(): void
    {
        $responder = app(ApiErrorResponder::class);
        $request = Request::create('/api/test');
        $validation = $responder->render(ValidationException::withMessages(['email' => ['Enter a valid email address.']]), $request);
        $this->assertSame(422, $validation->status());
        $this->assertSame(['email' => ['Enter a valid email address.']], $validation->getData(true)['errors']);
        foreach ([
            [new PublicBusinessRuleException('A cancellation request is already pending for this shoot'), 422],
            [new PublicConflictException('Compensation changed in another session. Refresh and try again.'), 409],
        ] as [$exception, $status]) {
            $response = $responder->render($exception, $request);
            $this->assertSame($status, $response->status());
            $this->assertSame($exception->getMessage(), $response->getData(true)['message']);
        }
        $upload = $responder->render(new IguideOfflineUploadException('Upload is incomplete.', 409, 'upload_incomplete', null, ['missing_chunks' => [1, 3]]), $request);
        $this->assertSame([1, 3], $upload->getData(true)['missing_chunks']);
        $this->assertSame('upload_incomplete', $upload->getData(true)['error_type']);
        $this->assertSame('Upload is incomplete.', $upload->getData(true)['message']);
        $this->assertSame('SMS could not be sent. Please try again.', ApiErrorResponder::publicMessage(new SmsSendException(self::CANARY)));
        $this->assertSame('SMS could not be sent: the Telnyx sending number is not verified.', ApiErrorResponder::publicMessage(new SmsSendException('SMS could not be sent: the Telnyx sending number is not verified.')));
    }

    public function test_reviewed_response_keeps_retry_and_service_change_metadata_unknown_response_does_not(): void
    {
        $responder = app(ApiErrorResponder::class);
        $request = Request::create('/api/test');
        $response = $responder->render(new PublicApiResponseException(response()->json([
            'message' => 'Too many attempts. Please try again later.', 'code' => 'auth_rate_limited', 'retry_after' => 37,
        ], 429, ['Retry-After' => '37'])), $request);
        $this->assertSame(429, $response->status());
        $this->assertSame(37, $response->getData(true)['retry_after']);
        $this->assertSame('37', $response->headers->get('Retry-After'));
        $response = $responder->render(new PublicApiResponseException(response()->json([
            'message' => 'Confirm service removal before saving this shoot.', 'code' => 'service_detach_confirmation_required',
            'confirmation_token' => 'reviewed-product-confirmation-token', 'impact' => ['removed_service_ids' => [12]],
        ], 409)), $request);
        $this->assertSame([12], $response->getData(true)['impact']['removed_service_ids']);
        $unknown = $responder->render(new HttpResponseException(response()->json(['message' => self::CANARY], 429)), $request);
        $this->assertSame(429, $unknown->status());
        $this->assertStringNotContainsString('secret-canary', $unknown->getContent());
    }

    public function test_broken_logging_does_not_replace_response_and_diagnostics_have_no_message_or_arguments(): void
    {
        Log::shouldReceive('error')->once()->andThrow(new \RuntimeException('logger unavailable'));
        $exception = new \RuntimeException(self::CANARY);
        $response = app(ApiErrorResponder::class)->render($exception, Request::create('/api/test'));
        $this->assertSame(500, $response->status());
        $diagnostic = ApiErrorResponder::diagnosticContext($exception);
        $this->assertSame(['exception', 'file', 'line'], array_keys($diagnostic));
        $this->assertSame(\RuntimeException::class, $diagnostic['exception']);
        $this->assertStringNotContainsString('secret-canary', json_encode($diagnostic));
        $this->assertArrayNotHasKey('trace', $diagnostic);
    }
}
