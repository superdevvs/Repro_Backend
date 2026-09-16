<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class InboundWebhookGuard
{
    public static function requireConfiguredSecret(?string $secret): ?JsonResponse
    {
        if (trim((string) $secret) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Webhook secret is not configured',
            ], 503);
        }

        return null;
    }
}
