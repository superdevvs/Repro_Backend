<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\FeaturedShootPayloadService;
use App\Services\ReproApiSettingsService;
use Illuminate\Http\Request;

class FeaturedShootController extends Controller
{
    public function __invoke(
        Request $request,
        FeaturedShootPayloadService $payloadService,
        ReproApiSettingsService $settingsService
    )
    {
        $providedToken = (string) $request->bearerToken();
        $validTokens = array_values(array_filter([
            $settingsService->featuredShootApiKey(),
            config('services.repro_dashboard.api_key'),
        ], fn ($token) => is_string($token) && trim($token) !== ''));

        $isAuthorized = $providedToken !== '' && collect($validTokens)
            ->contains(fn (string $token) => hash_equals($token, $providedToken));

        if (empty($validTokens) || !$isAuthorized) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $payloadService->payload();
        if ($payload === null) {
            return response('null', 200)
                ->header('Content-Type', 'application/json')
                ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
        }

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
