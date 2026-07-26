<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\AuthorizesStudioRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class StudioController extends Controller
{
    use AuthorizesStudioRequests;

    protected function pendingStudioOperation(
        Request $request,
        string $operation,
        string $action = 'view'
    ): JsonResponse {
        $this->authorizeStudioAction($request->user(), $action);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'studio_operation_not_implemented',
                'message' => 'This Studio operation is not available yet.',
                'operation' => $operation,
            ],
        ], 501);
    }
}
