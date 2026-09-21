<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ShortLinks\ShortLinkSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShortLinkSettingsController extends Controller
{
    public function show(ShortLinkSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->current(),
        ]);
    }

    public function update(Request $request, ShortLinkSettings $settings): JsonResponse
    {
        $allowed = $settings->allowedTypes();
        $unknown = array_diff(array_keys($request->input('types', [])), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'types' => 'Unknown short link type.',
            ]);
        }

        $typeRules = [];
        foreach ($allowed as $type) {
            $typeRules["types.{$type}"] = ['required', 'boolean'];
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'code_length' => ['required', 'integer', 'min:8', 'max:12'],
            'types' => ['required', 'array'],
            ...$typeRules,
        ]);

        return response()->json([
            'data' => $settings->save($validated),
        ]);
    }
}
