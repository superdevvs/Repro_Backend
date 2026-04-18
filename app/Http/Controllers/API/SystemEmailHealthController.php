<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Messaging\SystemEmailHealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SystemEmailHealthController extends Controller
{
    public function __invoke(SystemEmailHealthCheckService $systemEmailHealthCheckService): JsonResponse
    {
        $summary = $systemEmailHealthCheckService->inspect();

        if (($summary['healthy'] ?? false) !== true) {
            Log::warning('System email health check failed.', [
                'failure_type' => $summary['failure_type'] ?? null,
                'channel_id' => data_get($summary, 'checks.default_channel.channel_id'),
                'provider' => data_get($summary, 'checks.default_channel.provider'),
                'from_email' => data_get($summary, 'checks.default_channel.from_email'),
                'connection_error' => data_get($summary, 'checks.provider_connection.error'),
                'base_url' => config('services.cakemail.base_url'),
                'has_username' => filled(config('services.cakemail.username')),
                'has_password' => filled(config('services.cakemail.password')),
            ]);
        }

        return response()->json(
            $summary,
            $systemEmailHealthCheckService->statusCode($summary),
        );
    }
}
