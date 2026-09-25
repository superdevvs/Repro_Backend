<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditingDispatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShootEditingDispatchController extends Controller
{
    private function authorizeShoot(Request $request, Shoot $shoot, bool $selection = false): void
    {
        abort_unless(in_array($request->user()->role, $selection ? ['admin', 'superadmin', 'editing_manager', 'editor'] : ['admin', 'superadmin', 'editing_manager'], true), 403);
        app(ShootAuthorizationSupport::class)->ensureShootAccess($shoot, $request->user());
    }

    public function plan(Request $request, Shoot $shoot, ShootEditingDispatchService $dispatch)
    {
        $this->authorizeShoot($request, $shoot, true);
        return response()->json(['data' => $dispatch->plan($shoot, $request->user())]);
    }

    public function store(Request $request, Shoot $shoot, ShootEditingDispatchService $dispatch)
    {
        $this->authorizeShoot($request, $shoot, $request->has('file_ids') && $request->input('mode') === 'ai');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['ai', 'editor'])],
            'request_id' => ['required', 'uuid'],
            'file_ids' => ['sometimes', 'array', 'min:1', 'max:300'], 'file_ids.*' => ['integer', 'distinct', 'min:1'],
            'preset' => ['sometimes', Rule::in(['listing-ready', 'color-correction', 'sky-replacement', 'perspective-correction', 'green-grass', 'upscale'])],
            'targets' => ['sometimes', 'array:virtual-staging,green-grass'],
            'targets.*' => ['array', 'min:1', 'max:300'], 'targets.*.*' => ['integer', 'distinct', 'min:1'],
            'staging' => ['sometimes', 'array:roomType,furnitureStyle,removal'],
            'staging.roomType' => ['sometimes', 'string'], 'staging.furnitureStyle' => ['sometimes', 'string'], 'staging.removal' => ['sometimes', 'string'],
        ]);
        return response()->json(['data' => $dispatch->dispatch($shoot, $request->user(), $data)], 202);
    }
}
