<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootRealtorOptionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The realtors the current user may put on this shoot's branded tour.
 *
 * One endpoint for every role, so the picker never has to know that staff see
 * the whole client list while a client sees only their own linked circle: the
 * rule lives in {@see ShootRealtorOptionsService} and is the same one the
 * shoot update enforces.
 */
class ShootRealtorOptionsController extends Controller
{
    public function __construct(
        private readonly ShootAuthorizationSupport $authorization,
        private readonly ShootRealtorOptionsService $realtorOptions,
    ) {}

    public function __invoke(Request $request, Shoot $shoot): JsonResponse
    {
        $user = $request->user();
        $this->authorization->ensureShootAccess($shoot, $user);

        if (! $this->realtorOptions->canChooseRealtor($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->realtorOptions->optionsFor($user, $shoot),
        ]);
    }
}
