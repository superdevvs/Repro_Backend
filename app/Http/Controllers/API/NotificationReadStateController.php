<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotificationReadStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationReadStateController extends Controller
{
    public function update(Request $request, NotificationReadStateService $states): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:1500'],
            'ids.*' => ['string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:\-]*\z/'],
            'lastSeenAt' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $states->merge($request, $data['ids'], $data['lastSeenAt'] ?? null)]);
    }
}
