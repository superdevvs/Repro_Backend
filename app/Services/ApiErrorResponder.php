<?php

namespace App\Services;

use App\Exceptions\IguideOfflineUploadException;
use App\Exceptions\Messaging\SmsSendException;
use App\Exceptions\PublicApiException;
use App\Exceptions\PublicApiResponseException;
use App\Exceptions\PublicAuthorizationException;
use App\Exceptions\PublicBusinessRuleException;
use App\Exceptions\PublicConflictException;
use App\Exceptions\StudioClientAccessPaused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApiErrorResponder
{
    public function render(\Throwable $exception, Request $request): JsonResponse
    {
        $status = $this->status($exception);
        $payload = [
            'message' => self::publicMessage($exception, self::defaultMessage($status)),
            'code' => $exception instanceof PublicApiException ? $exception->errorCode : self::defaultCode($status),
            'request_id' => RequestCorrelation::id($request),
        ];
        if ($exception instanceof PublicApiResponseException && $exception->getResponse() instanceof JsonResponse) {
            $reviewed = $exception->getResponse()->getData(true);
            if (is_array($reviewed) && !array_is_list($reviewed)) {
                $payload = array_merge($payload, $reviewed, ['request_id' => RequestCorrelation::id($request)]);
            }
        } elseif ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        } elseif ($exception instanceof PublicApiException) {
            $payload = array_merge($exception->publicDetails, $payload);
        } elseif ($exception instanceof StudioClientAccessPaused
            || ($exception instanceof HttpExceptionInterface && $exception->getPrevious() instanceof StudioClientAccessPaused)) {
            $payload['code'] = 'studio_client_access_paused';
        } elseif ($exception instanceof SmsSendException) {
            $payload['code'] = 'sms_send_failed';
            $payload['error'] = 'sms_send_failed';
        } elseif ($exception instanceof IguideOfflineUploadException) {
            $payload['error_type'] = $exception->errorType;
            $payload['code'] = $exception->errorType;
            $payload = array_merge($exception->details, $payload);
            if ($exception->uploadSession !== null) {
                $payload = array_merge(app(IguideOfflineChunkUploadService::class)->payload($exception->uploadSession), $payload);
            }
        }

        if ($status >= 500) {
            try {
                Log::error('API request failed.', self::diagnosticContext($exception) + ['request_id' => $payload['request_id'], 'code' => $payload['code']]);
            } catch (\Throwable) {
                // A broken diagnostic destination must not change the API response.
            }
        }

        $response = response()->json($payload, $status)
            ->header('X-Trace-Id', $payload['request_id'])
            ->header('X-Request-Id', $payload['request_id'])
            ->header('Cache-Control', 'no-store');
        if ($exception instanceof HttpExceptionInterface || $exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
            // Preserve protocol-relevant backoff/method headers, not arbitrary diagnostics.
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : $exception->getResponse()->headers->all();
            foreach ($headers as $key => $value) {
                if (in_array(strtolower($key), ['retry-after', 'allow', 'www-authenticate'], true)) {
                    $response->headers->set($key, $value);
                }
            }
        }

        return $response;
    }

    public function status(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof PublicApiException => $exception->httpStatus,
            $exception instanceof \Illuminate\Http\Exceptions\HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof PublicBusinessRuleException => 422,
            $exception instanceof PublicConflictException => 409,
            $exception instanceof IguideOfflineUploadException => $exception->httpStatus,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof SmsSendException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => $exception->status() ?: 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
    }

    /** For reviewed controller catch responses, retaining their status and metadata. */
    public static function publicMessage(\Throwable $exception, string $fallback = 'The request could not be completed. Please try again.'): string
    {
        // Laravel converts authorization exceptions into HTTP exceptions before rendering.
        if ($exception instanceof HttpExceptionInterface
            && ($exception->getPrevious() instanceof PublicAuthorizationException || $exception->getPrevious() instanceof StudioClientAccessPaused)) {
            return $exception->getPrevious()->getMessage();
        }
        if ($exception instanceof PublicApiException || $exception instanceof PublicAuthorizationException || $exception instanceof PublicBusinessRuleException
            || $exception instanceof PublicConflictException || $exception instanceof StudioClientAccessPaused
            || $exception instanceof IguideOfflineUploadException) {
            return $exception->getMessage();
        }
        if ($exception instanceof SmsSendException) {
            // These are the two reviewed outputs of MessagingService::clientSafeSmsError.
            return in_array($exception->getMessage(), [
                'SMS could not be sent: the Telnyx sending number is not verified.',
                'SMS could not be sent due to a provider error.',
            ], true) ? $exception->getMessage() : 'SMS could not be sent. Please try again.';
        }
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $messages) {
                foreach ((array) $messages as $message) {
                    if (is_string($message) && $message !== '') {
                        return $message;
                    }
                }
            }
            return 'Please check the highlighted fields.';
        }

        return $fallback;
    }

    /** Restricted diagnostics identify the exception without serializing its data. */
    public static function diagnostic(\Throwable $exception): string
    {
        $request = app()->bound('request') ? request() : null;

        return $exception::class.($request instanceof Request ? ' [request_id='.RequestCorrelation::id($request).']' : '');
    }

    /** No exception messages, previous exceptions, stack arguments, or request values. */
    public static function diagnosticContext(\Throwable $exception): array
    {
        $file = str_replace('\\', '/', $exception->getFile());
        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return [
            'exception' => $exception::class,
            'file' => str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file),
            'line' => $exception->getLine(),
        ];
    }

    public static function log(\Throwable $exception, string $level = 'error'): void
    {
        try {
            $context = self::diagnosticContext($exception);
            if (app()->bound('request') && request() instanceof Request) {
                $context['request_id'] = RequestCorrelation::id(request());
            }
            Log::log(in_array($level, ['error', 'warning', 'info', 'debug'], true) ? $level : 'error', 'API operation failed.', $context);
        } catch (\Throwable) {
            // Reporting must never replace the original operation's response.
        }
    }

    /** Older stored provider diagnostics must not become public when a job is read. */
    public static function storedFailure(mixed $value, string $fallback = 'This operation failed. Please try again or contact support.'): ?string
    {
        return $value === null || $value === '' ? null : $fallback;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Please sign in to continue.',
            403 => 'You do not have permission to perform this action.',
            404 => 'The requested item was not found.',
            405 => 'This request method is not supported.',
            409 => 'This item has changed. Refresh and try again.',
            410 => 'This item is no longer available.',
            413 => 'The upload is too large.',
            415 => 'This file or request format is not supported.',
            416 => 'The requested upload range is invalid.',
            419 => 'Your session has expired. Refresh and try again.',
            422 => 'Please check the information you entered.',
            429 => 'Too many attempts. Please wait and try again.',
            503 => 'This service is temporarily unavailable. Please try again.',
            default => $status >= 500 ? 'Something went wrong. Please try again.' : 'The request could not be completed.',
        };
    }

    public static function defaultCode(int $status): string
    {
        return match ($status) {
            401 => 'authentication_required', 403 => 'forbidden', 404 => 'not_found',
            409 => 'conflict', 413 => 'payload_too_large', 419 => 'session_expired',
            422 => 'validation_failed', 429 => 'rate_limited', 503 => 'service_unavailable',
            default => $status >= 500 ? 'internal_error' : 'request_failed',
        };
    }
}
