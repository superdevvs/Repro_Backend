<?php

namespace App\Http\Middleware;

use App\Models\ToolBridgeInvocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelnyxToolBridgeAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.telnyx.tool_bridge.secret', '');

        if ($secret === '') {
            if (!app()->environment(['local', 'testing'])) {
                abort(403, 'Tool Bridge secret is not configured.');
            }

            return $next($request);
        }

        $bearer = (string) $request->bearerToken();
        if (!hash_equals($secret, $bearer)) {
            abort(401, 'Invalid Tool Bridge bearer token.');
        }

        $timestamp = (string) $request->header('X-Tool-Timestamp', '');
        $signature = (string) $request->header('X-Tool-Signature', '');
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($timestamp === '' || $signature === '' || $idempotencyKey === '') {
            abort(401, 'Missing Tool Bridge signature headers.');
        }

        $tolerance = (int) config('services.telnyx.tool_bridge.tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            abort(401, 'Stale Tool Bridge signature.');
        }

        $payload = $timestamp . '.' . $idempotencyKey . '.' . $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            abort(401, 'Invalid Tool Bridge signature.');
        }

        $existing = ToolBridgeInvocation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->whereNotNull('response_json')
            ->first();

        if ($existing) {
            return response()->json($existing->response_json, 200);
        }

        return $next($request);
    }
}
