<?php

namespace App\Http\Middleware;

use App\Services\ReproApiSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateExternalApiKey
{
    /**
     * Validate the external API key from the X-API-Key header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'Missing API key. Provide X-API-Key header.',
            ], 401);
        }

        $validKeys = array_values(array_filter([
            app(ReproApiSettingsService::class)->externalBookingApiKey(),
            config('services.external_booking.api_key'),
        ], fn ($key) => is_string($key) && trim($key) !== ''));

        $isAuthorized = collect($validKeys)
            ->contains(fn (string $validKey) => hash_equals($validKey, $apiKey));

        if (empty($validKeys) || !$isAuthorized) {
            return response()->json([
                'message' => 'Invalid API key.',
            ], 403);
        }

        return $next($request);
    }
}
