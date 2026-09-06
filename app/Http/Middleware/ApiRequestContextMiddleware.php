<?php

namespace App\Http\Middleware;

use App\Services\ApiErrorResponder;
use App\Services\RequestCorrelation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs before authentication so early denials receive the same error contract. */
class ApiRequestContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/*')) {
            return $next($request);
        }
        $id = RequestCorrelation::id($request);
        $response = $next($request);
        $response->headers->set('X-Trace-Id', $id);
        $response->headers->set('X-Request-Id', $id);
        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $payload = $response->getData(true);
            if (is_array($payload) && !array_is_list($payload)) {
                $payload['request_id'] = $id;
                $payload['code'] ??= ApiErrorResponder::defaultCode($response->getStatusCode());
                $payload['message'] ??= ApiErrorResponder::defaultMessage($response->getStatusCode());
                $response->setData($payload);
                $response->headers->set('Cache-Control', 'no-store');
            }
        }
        return $response;
    }
}
