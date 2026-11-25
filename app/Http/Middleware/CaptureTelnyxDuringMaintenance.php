<?php

namespace App\Http\Middleware;

use App\Support\TelnyxMaintenanceSpool;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs first in the global stack: before the maintenance-mode check and
 * before the API group (whose telemetry writes to the database).
 *
 * While the app is down and TELNYX_MAINTENANCE_CAPTURE_ENABLED is on, signed
 * Telnyx texts are saved to the private spool and answered 200, so Telnyx
 * stops retrying; telnyx:replay-spool handles them after the deploy. Voice
 * and Vapi events are saved for the missed-call list but still get the normal
 * 503, so the provider's own retry or failover is unchanged. Anything not
 * verified or not saved also gets the normal 503. Nothing here touches the
 * database. It needs `php artisan down` without --render.
 */
class CaptureTelnyxDuringMaintenance
{
    private const ROUTES = [
        '/api/webhooks/telnyx/messaging' => [TelnyxMaintenanceSpool::TEXTS, 'messaging'],
        '/api/webhooks/telnyx/status' => [TelnyxMaintenanceSpool::TEXTS, 'status'],
        '/api/webhooks/telnyx/voice' => [TelnyxMaintenanceSpool::CALLS, 'voice'],
        '/api/webhooks/vapi' => [TelnyxMaintenanceSpool::CALLS, 'vapi'],
    ];

    // Same pattern as the route's whereUuid().
    private const SHARED_MMS = '#\A/api/webhooks/telnyx/shared-mms/([\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12})\z#';

    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.telnyx.maintenance_capture.enabled')
            || $request->getRealMethod() !== 'POST'
            || ! $this->app->isDownForMaintenance()) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        $routeKey = null;
        if (preg_match(self::SHARED_MMS, $path, $match)) {
            [$folder, $route, $routeKey] = [TelnyxMaintenanceSpool::TEXTS, 'shared-mms', $match[1]];
        } elseif (isset(self::ROUTES[$path])) {
            [$folder, $route] = self::ROUTES[$path];
        } else {
            return $next($request);
        }

        $arrivedAt = now()->toImmutable()->utc();
        $maxBytes = (int) config('services.telnyx.maintenance_capture.max_body_bytes', 1048576);
        if ((int) $request->headers->get('Content-Length', '0') > $maxBytes) {
            return $next($request);
        }
        $body = $request->getContent();
        $headers = strlen($body) > $maxBytes ? null
            : ($route === 'vapi' ? $this->verifiedVapi($request, $body) : $this->verifiedTelnyx($request, $body, $arrivedAt->getTimestamp()));
        if ($headers === null) {
            return $next($request);
        }

        try {
            TelnyxMaintenanceSpool::fromConfig()->save($folder, $route, $routeKey, $body, $headers, $arrivedAt);
        } catch (Throwable $error) {
            Log::warning('Telnyx maintenance capture could not save a webhook.', ['route' => $route, 'error' => $error::class]);

            return $next($request);
        }

        return $folder === TelnyxMaintenanceSpool::TEXTS
            ? response()->json(['status' => 'captured'])
            : $next($request);
    }

    /** @return array<string, string>|null The headers needed to re-verify later. */
    private function verifiedTelnyx(Request $request, string $body, int $arrivedAt): ?array
    {
        $signatures = $request->headers->all('telnyx-signature-ed25519');
        $timestamps = $request->headers->all('telnyx-timestamp');
        if (count($signatures) !== 1 || count($timestamps) !== 1) {
            return null;
        }
        [$signature, $timestamp] = [(string) $signatures[0], (string) $timestamps[0]];
        $tolerance = max(1, (int) config('services.telnyx.webhook_tolerance_seconds', 300));
        if (! ctype_digit($timestamp) || abs($arrivedAt - (int) $timestamp) > $tolerance
            || ! TelnyxMaintenanceSpool::telnyxSignatureValid($body, $signature, $timestamp)) {
            return null;
        }
        $eventId = data_get(json_decode($body, true), 'data.id');
        if (! is_string($eventId) || $eventId === '') {
            return null;
        }

        return ['telnyx-signature-ed25519' => $signature, 'telnyx-timestamp' => $timestamp];
    }

    /** @return array<string, string>|null Never the shared secret itself. */
    private function verifiedVapi(Request $request, string $body): ?array
    {
        $secret = (string) config('services.vapi.server_secret', '');
        $header = (string) ($request->header('X-Vapi-Secret') ?: $request->header('X-Webhook-Secret'));
        if ($secret === '' || ! (hash_equals($secret, $header) || hash_equals('Bearer '.$secret, (string) $request->header('Authorization')))) {
            return null;
        }

        return is_array(json_decode($body, true)) ? [] : null;
    }
}
