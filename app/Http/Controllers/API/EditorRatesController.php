<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EditorRatesController extends Controller
{
    protected function resolveMetadata(User $editor): array
    {
        $metadata = $editor->metadata ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    protected function normalizeRates(array $metadata): array
    {
        return [
            'photo_edit_rate' => isset($metadata['photo_edit_rate']) ? (float) $metadata['photo_edit_rate'] : 0.0,
            'video_edit_rate' => isset($metadata['video_edit_rate']) ? (float) $metadata['video_edit_rate'] : 0.0,
            'floorplan_rate' => isset($metadata['floorplan_rate']) ? (float) $metadata['floorplan_rate'] : 0.0,
            'other_rate' => isset($metadata['other_rate']) ? (float) $metadata['other_rate'] : 0.0,
        ];
    }

    protected function ensureAccess(Request $request, User $editor): ?\Illuminate\Http\JsonResponse
    {
        $viewer = $request->user();
        if (!$viewer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($editor->role !== 'editor') {
            return response()->json(['message' => 'Editor not found'], 404);
        }

        if ((string) $viewer->id !== (string) $editor->id && !in_array($viewer->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    public function show(Request $request, User $editor)
    {
        if ($response = $this->ensureAccess($request, $editor)) {
            return $response;
        }

        $metadata = $this->resolveMetadata($editor);

        return response()->json([
            'data' => $this->normalizeRates($metadata),
        ]);
    }

    public function update(Request $request, User $editor)
    {
        if ($response = $this->ensureAccess($request, $editor)) {
            return $response;
        }

        $validated = $request->validate([
            'photo_edit_rate' => 'nullable|numeric|min:0',
            'video_edit_rate' => 'nullable|numeric|min:0',
            'floorplan_rate' => 'nullable|numeric|min:0',
            'other_rate' => 'nullable|numeric|min:0',
        ]);

        $metadata = $this->resolveMetadata($editor);
        foreach (['photo_edit_rate', 'video_edit_rate', 'floorplan_rate', 'other_rate'] as $key) {
            if (array_key_exists($key, $validated)) {
                $metadata[$key] = $validated[$key] ?? 0;
            }
        }

        $editor->metadata = $metadata;
        $editor->save();

        return response()->json([
            'message' => 'Editor rates updated successfully.',
            'data' => $this->normalizeRates($metadata),
        ]);
    }
}
