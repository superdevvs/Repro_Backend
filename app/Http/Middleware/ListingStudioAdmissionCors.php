<?php

namespace App\Http\Middleware;

use App\Exceptions\PublicApiException;
use App\Services\ApiErrorResponder;
use Closure;
use Illuminate\Http\Request;

/** Only this anonymous admission endpoint is callable by the website browser. */
class ListingStudioAdmissionCors
{
    public const PATH = '/api/listing-studio/trial-admissions/verify';

    public function handle(Request $request, Closure $next)
    {
        if ($request->getPathInfo() !== self::PATH) {
            return $next($request);
        }
        $origin = (string) $request->header('Origin');
        $allowed = [];
        foreach (config('listing-studio-trials.turnstile_hosts', []) as $host) {
            if (is_string($host) && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
                $allowed[] = 'https://'.$host;
            }
        }
        if (! in_array($origin, $allowed, true)) {
            return $this->error($request, 'This website cannot verify a Studio upload.', 'trial_origin_invalid', 403);
        }
        if ($request->isMethod('OPTIONS')) {
            $headers = array_filter(array_map('trim', explode(',', strtolower((string) $request->header('Access-Control-Request-Headers')))));
            if ($request->header('Access-Control-Request-Method') !== 'POST' || array_diff($headers, ['content-type'])) {
                $response = $this->error($request, 'This verification request is not supported.', 'request_invalid', 403);
            } else {
                $response = response('', 204)->header('Access-Control-Allow-Methods', 'POST')->header('Access-Control-Allow-Headers', 'Content-Type')->header('Access-Control-Max-Age', '300');
            }
        } elseif (! $request->isMethod('POST') || $request->getQueryString() !== null) {
            $response = $this->error($request, 'This verification request is not supported.', 'request_invalid', 405);
        } elseif (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') {
            $response = $this->error($request, 'Send the verification as JSON.', 'request_invalid', 415);
        } else {
            // Bound the actual body before Laravel's JSON parsing middleware, even without Content-Length.
            $stream = $request->getContent(true);
            $bytes = stream_get_contents($stream, 4097);
            rewind($stream);
            $response = strlen($bytes) > 4096
                ? $this->error($request, 'The verification request is too large.', 'request_too_large', 413)
                : $next($request);
        }
        // Override only this response, including the global exception renderer's generic CORS headers.
        foreach (['Access-Control-Allow-Credentials', 'Access-Control-Allow-Origin', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Methods', 'Access-Control-Expose-Headers'] as $header) {
            if (! $request->isMethod('OPTIONS') || ! in_array($header, ['Access-Control-Allow-Headers', 'Access-Control-Allow-Methods'], true)) {
                $response->headers->remove($header);
            }
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Cache-Control', 'no-store');
        $response->setVary('Origin', false);

        return $response;
    }

    private function error(Request $request, string $message, string $code, int $status)
    {
        return app(ApiErrorResponder::class)->render(new PublicApiException($message, $code, $status), $request);
    }
}
