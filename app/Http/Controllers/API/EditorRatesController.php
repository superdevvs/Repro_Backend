<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EditorRatesController extends Controller
{
    protected function normalizeServiceRates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($rate) {
            if (!is_array($rate)) {
                return null;
            }

            $serviceName = trim((string) ($rate['service_name'] ?? $rate['serviceName'] ?? ''));
            if ($serviceName === '') {
                return null;
            }

            $serviceId = $rate['service_id'] ?? $rate['serviceId'] ?? null;

            return [
                'service_id' => $serviceId !== null && $serviceId !== '' ? (string) $serviceId : null,
                'service_name' => $serviceName,
                'rate' => isset($rate['rate']) ? (float) $rate['rate'] : 0.0,
            ];
        }, $value)));
    }

    protected function findServiceRate(array $serviceRates, string $pattern): ?float
    {
        foreach ($serviceRates as $rate) {
            $serviceName = (string) ($rate['service_name'] ?? '');
            if ($serviceName !== '' && preg_match($pattern, $serviceName)) {
                return isset($rate['rate']) ? (float) $rate['rate'] : 0.0;
            }
        }

        return null;
    }

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
        $serviceRates = $this->normalizeServiceRates(
            $metadata['service_rates'] ?? $metadata['serviceRates'] ?? $metadata['editing_service_rates'] ?? []
        );

        return [
            'photo_edit_rate' => isset($metadata['photo_edit_rate']) ? (float) $metadata['photo_edit_rate'] : 0.0,
            'video_edit_rate' => isset($metadata['video_edit_rate']) ? (float) $metadata['video_edit_rate'] : 0.0,
            'floorplan_rate' => isset($metadata['floorplan_rate']) ? (float) $metadata['floorplan_rate'] : 0.0,
            'virtual_staging_rate' => isset($metadata['virtual_staging_rate']) ? (float) $metadata['virtual_staging_rate'] : 0.0,
            'other_rate' => isset($metadata['other_rate']) ? (float) $metadata['other_rate'] : 0.0,
            'service_rates' => $serviceRates,
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
            'virtual_staging_rate' => 'nullable|numeric|min:0',
            'other_rate' => 'nullable|numeric|min:0',
            'service_rates' => 'nullable|array',
            'service_rates.*.service_id' => 'nullable',
            'service_rates.*.service_name' => 'required_with:service_rates|string',
            'service_rates.*.rate' => 'nullable|numeric|min:0',
        ]);

        $metadata = $this->resolveMetadata($editor);
        foreach (['photo_edit_rate', 'video_edit_rate', 'floorplan_rate', 'virtual_staging_rate', 'other_rate'] as $key) {
            if (array_key_exists($key, $validated)) {
                $metadata[$key] = $validated[$key] ?? 0;
            }
        }

        if (array_key_exists('service_rates', $validated)) {
            $metadata['service_rates'] = $this->normalizeServiceRates($validated['service_rates']);

            $metadata['photo_edit_rate'] = $this->findServiceRate($metadata['service_rates'], '/photo|hdr|twilight/i') ?? 0.0;
            $metadata['video_edit_rate'] = $this->findServiceRate($metadata['service_rates'], '/video/i') ?? 0.0;
            $metadata['virtual_staging_rate'] = $this->findServiceRate($metadata['service_rates'], '/virtual\s*staging/i') ?? 0.0;
            $metadata['floorplan_rate'] = 0.0;
        }

        $editor->metadata = $metadata;
        $editor->save();

        return response()->json([
            'message' => 'Editor rates updated successfully.',
            'data' => $this->normalizeRates($metadata),
        ]);
    }
}
