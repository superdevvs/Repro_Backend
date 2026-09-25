<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioProviderSettingsController extends Controller
{
    public function show(Request $request, StudioProviderSettings $settings): JsonResponse
    {
        $this->authorizeSettings($request);

        return response()->json(['data' => $settings->present()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, StudioProviderSettings $settings): JsonResponse
    {
        $this->authorizeSettings($request);
        $input = $request->validate([
            'services' => ['sometimes', 'array', 'max:14'], 'services.*' => ['array:id,provider,model,fallback'],
            'services.*.id' => ['required', 'string', 'distinct'], 'services.*.provider' => ['required', 'string', 'max:30'], 'services.*.model' => ['required', 'string', 'max:150'],
            'services.*.fallback' => ['nullable', 'array:provider,model'], 'services.*.fallback.provider' => ['required_with:services.*.fallback', 'string'], 'services.*.fallback.model' => ['required_with:services.*.fallback', 'string'],
            'credentials' => ['sometimes', 'array:fotello,virtualStagingAi,autoenhance'], 'credentials.fotello' => ['sometimes', 'array:apiKey,teamId'],
            'credentials.autoenhance' => ['sometimes', 'array:apiKey,webhookSecret'],
            'credentials.autoenhance.apiKey' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[^\r\n]*$/'],
            'credentials.autoenhance.webhookSecret' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[^\r\n]*$/'],
            'credentials.fotello.apiKey' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[^\r\n]*$/'],
            'credentials.fotello.teamId' => ['sometimes', 'nullable', 'string', 'max:150', 'regex:/^[a-zA-Z0-9_-]*$/'],
            'credentials.virtualStagingAi' => ['sometimes', 'array:apiKey,refresh'],
            'credentials.virtualStagingAi.apiKey' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[^\r\n]*$/'],
            'credentials.virtualStagingAi.refresh' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $settings->save($input)])->header('Cache-Control', 'private, no-store');
    }

    public function capabilities(StudioProviderSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->capabilities()])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeSettings(Request $request): void
    {
        // A client's or staff member's secondary roles never grant credential administration.
        abort_unless(strtolower(trim((string) $request->user()?->role)) === 'superadmin', 403);
    }
}
