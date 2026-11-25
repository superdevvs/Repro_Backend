<?php

namespace App\Http\Controllers;

use App\Services\DatabaseHealth;
use Illuminate\Http\JsonResponse;

/** GET /up: 200 when the database answers on the expected engine, else 503. */
final class HealthCheckController
{
    public function __invoke(DatabaseHealth $health): JsonResponse
    {
        $result = $health->check();

        return response()
            ->json($result, $result['status'] === 'ok' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
