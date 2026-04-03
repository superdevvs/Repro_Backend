<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\IpLocationLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpLocationController extends Controller
{
    public function __construct(
        private readonly IpLocationLookupService $ipLocationLookupService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $location = $this->ipLocationLookupService->lookup($request->ip());

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'IP-based location was not available.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $location,
        ]);
    }
}
