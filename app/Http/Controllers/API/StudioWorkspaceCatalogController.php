<?php

namespace App\Http\Controllers\API;

use App\Services\Studio\WorkspaceCatalog;
use App\Services\Studio\WorkspaceIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudioWorkspaceCatalogController extends StudioController
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor', 'client'];

    public function index(Request $request, WorkspaceCatalog $catalog): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        return response()->json(['data' => $catalog->present($request->user()->role)])->header('Cache-Control', 'private, no-store');
    }

    public function intent(Request $request, WorkspaceIntent $intent): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');
        $input = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'mode' => ['nullable', Rule::in(['photo', 'video'])],
            'presetId' => ['nullable', Rule::in(WorkspaceCatalog::PRESETS)],
            'mediaCount' => ['required', 'integer', 'between:0,300'],
        ]);

        return response()->json(['data' => $intent->resolve($input)])->header('Cache-Control', 'private, no-store');
    }
}
