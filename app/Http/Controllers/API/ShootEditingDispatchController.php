<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootEditingDispatchService;
use App\Support\LockedWrite;
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
        $this->authorizeShoot($request, $shoot, $request->has('file_ids') && $request->input('mode') === 'ai' && (!$request->has('scope') || $request->input('scope') === 'selected'));
        $data = $this->validateInput($request);
        try {
            return response()->json(['data' => $dispatch->dispatch($shoot, $request->user(), $data)], 202);
        } catch (\Throwable $exception) {
            if (! LockedWrite::isLockContention($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Editing is temporarily busy. Retry this request in a moment.',
                'error_type' => 'editing_dispatch_busy',
                'retryable' => true,
                'dispatch_request_id' => $data['request_id'],
            ], 503)->header('Retry-After', '1');
        }
    }

    public function preview(Request $request, Shoot $shoot)
    {
        $this->authorizeShoot($request, $shoot, $request->input('scope') === 'selected' && $request->input('mode') === 'ai');
        $data = $this->validateInput($request);
        abort_unless(isset($data['scope']), 422, 'Choose an editing scope.');
        return response()->json(['data' => app(\App\Services\Shoots\ScopedEditingPlan::class)->preview($shoot, $request->user(), $data, true)]);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'mode' => ['required', Rule::in(['ai', 'editor'])],
            'request_id' => ['required', 'uuid'],
            'scope' => ['sometimes', Rule::in(['whole', 'photos', 'videos', 'selected'])],
            'source_versions' => ['required_with:scope', 'array'], 'source_versions.*' => ['integer', 'min:1'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'photo_editor_id' => ['nullable', 'integer', 'exists:users,id'], 'video_editor_id' => ['nullable', 'integer', 'exists:users,id'],
            'file_ids' => ['sometimes', 'array', 'min:1', 'max:300'], 'file_ids.*' => ['integer', 'distinct', 'min:1'],
            'preset' => ['sometimes', Rule::in(array_keys(\App\Services\Shoots\ScopedEditingPlan::WORKFLOWS))],
            'targets' => ['sometimes', 'array:virtual-staging,green-grass'],
            'targets.*' => ['array', 'min:1', 'max:300'], 'targets.*.*' => ['integer', 'distinct', 'min:1'],
            'staging' => ['sometimes', 'array:roomType,furnitureStyle,removal'],
            'staging.roomType' => ['sometimes', 'string'], 'staging.furnitureStyle' => ['sometimes', 'string'], 'staging.removal' => ['sometimes', 'string'],
        ]);
    }
}
