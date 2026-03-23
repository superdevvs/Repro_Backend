<?php

namespace App\Http\Controllers;

use App\Services\ClientBillingService;
use Illuminate\Http\Request;

class ClientBillingController extends Controller
{
    public function index(Request $request, ClientBillingService $clientBillingService)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (strtolower((string) $user->role) !== 'client') {
            return response()->json(['message' => 'Only clients can access billing'], 403);
        }

        return response()->json(
            $clientBillingService->getClientBilling($user)
        );
    }
}
